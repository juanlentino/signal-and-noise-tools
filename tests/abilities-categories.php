<?php
/**
 * Tests for inc/abilities-categories.php — the Abilities API category
 * registrations every per-feature abilities-*.php file depends on.
 *
 * WP 6.9.0 added a _doing_it_wrong in WP_Abilities_Registry::register()
 * when an ability cites a category that was never registered — the live
 * site's Query Monitor caught this firing on every request for BOTH
 * signal-noise/get-analytics-summary and signal-noise/get-analytics-events
 * (inc/abilities-analytics.php), because 'analytics' was the one category
 * those two abilities cite that this file never registered. Fixed by
 * adding the 'analytics' registration alongside the other 5, using the
 * identical guarded idiom (function_exists( 'wp_register_ability_category' )
 * + wp_has_ability_category() re-check).
 *
 * Covers:
 *   - Group A: old-WP guard — wp_register_ability_category() absent
 *     (pre-6.9) → the closure returns cleanly, no fatal, no call attempted.
 *   - Group B: present-WP path — every category the plugin's abilities
 *     cite gets registered (completeness), including the previously-missing
 *     'analytics'.
 *   - Group C: idempotency (X-02 audit) — re-firing the hook registers
 *     nothing a second time (preserves a theme's category metadata as
 *     canonical when both register the same slug).
 *
 * Run: php tests/abilities-categories.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
function ok_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $expected, true ) . "\n    Actual:   " . var_export( $actual, true ) . "\n"; }
}

echo "abilities-categories suite\n";

// ─── Capture the SUT's add_action closure (WITHOUT defining the Abilities
// API category functions yet — Group A below needs that absence). ────────
$GLOBALS['__acg_actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acg_actions'][ $tag ][] = $cb; return true; }

require __DIR__ . '/../inc/abilities-categories.php';
$categories_cb = $GLOBALS['__acg_actions']['wp_abilities_api_categories_init'][0] ?? null;
ok( is_callable( $categories_cb ), 'wp_abilities_api_categories_init closure captured' );

// ════ Group A: old-WP guard — wp_register_ability_category() absent ═════
echo "\nGroup A: old WP (<6.9.0), wp_register_ability_category() does not exist\n";
ok( ! function_exists( 'wp_register_ability_category' ), 'precondition: wp_register_ability_category is NOT defined yet' );
$threw = null;
try {
	call_user_func( $categories_cb );
} catch ( Throwable $e ) {
	$threw = $e;
}
ok( null === $threw, 'categories closure does not fatal when the Abilities API is absent (function_exists guard)' );

// ════ Group B: present-WP path — every category registers, guarded ══════
echo "\nGroup B: category registration (WP 6.9.0+)\n";
$GLOBALS['__acg_cats']           = array();
$GLOBALS['__acg_register_calls'] = array();
// Guarded declarations (NOT unconditional top-level functions): PHP hoists
// unconditional top-level `function` declarations for the whole file at
// compile time, which would make wp_register_ability_category() visible to
// Group A's "not defined yet" precondition even though this code runs
// later. Wrapping in function_exists() defers the definition to runtime,
// matching the house idiom used elsewhere (e.g. tests/abilities-integration.php).
if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $slug, $args ) {
		$GLOBALS['__acg_register_calls'][] = $slug;
		$GLOBALS['__acg_cats'][ $slug ]    = $args;
		return true;
	}
}
if ( ! function_exists( 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( $slug ) { return isset( $GLOBALS['__acg_cats'][ $slug ] ); }
}

call_user_func( $categories_cb );

// Every category the plugin's inc/abilities-*.php files actually cite —
// self-audited via grep at fix time (grep -rn "'category'\s*=>" inc/).
// Keep this list in sync if a future abilities-*.php introduces a category.
$cited_categories = array( 'ai-generation', 'analytics', 'content', 'diagnostics', 'maintenance', 'tools' );
foreach ( $cited_categories as $cat ) {
	ok( isset( $GLOBALS['__acg_cats'][ $cat ] ), "'$cat' category registered" );
}
ok_eq( 6, count( $GLOBALS['__acg_cats'] ), 'exactly 6 categories registered (no stray additions)' );
ok_eq( 6, count( $GLOBALS['__acg_register_calls'] ), 'exactly 6 wp_register_ability_category() calls' );

// The specific regression this fixes: 'analytics' backs
// inc/abilities-analytics.php's 2 abilities (get-analytics-summary,
// get-analytics-events) and was the one category missing before the fix —
// WP 6.9.0's WP_Abilities_Registry::register() fires a _doing_it_wrong and
// silently bails wp_register_ability() when an ability's category was
// never registered; Query Monitor caught it firing on every live request.
$analytics_cat = $GLOBALS['__acg_cats']['analytics'] ?? null;
ok( is_array( $analytics_cat ), 'analytics category entry retrievable' );
ok( isset( $analytics_cat['label'] ) && '' !== $analytics_cat['label'], 'analytics category has a non-empty label' );
ok( isset( $analytics_cat['description'] ) && '' !== $analytics_cat['description'], 'analytics category has a non-empty description' );

// ════ Group C: idempotency (X-02) — re-firing the hook registers
// nothing a second time (preserves a theme's category metadata as
// canonical when both the theme and the plugin register the same slug). ═
echo "\nGroup C: idempotency — re-firing the hook re-registers nothing\n";
call_user_func( $categories_cb );
ok_eq( 6, count( $GLOBALS['__acg_cats'] ), 'a second hook fire leaves the registered set unchanged' );
ok_eq( 6, count( $GLOBALS['__acg_register_calls'] ), 'a second hook fire makes ZERO new wp_register_ability_category() calls (wp_has_ability_category() gate holds)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
