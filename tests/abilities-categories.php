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

// ════ Group D: cited-category completeness — DYNAMIC (v9.78.1) ══════════
// The v9.78.0 regression this closes: signal-noise/anchor-status shipped
// citing category 'monitoring', which this registrar never registers —
// WP 6.9's _doing_it_wrong fired on every live shell request (owner-caught
// via Query Monitor). The pre-ship check had grepped USAGE counts, which
// self-confirms: the new file's own citation was the one hit. The gate that
// can't be fooled scans every inc/abilities-*.php for cited categories and
// asserts each one is in the REGISTERED set — a new citation of an
// unregistered category now fails this suite instead of the live site.
echo "\nGroup D: every category cited by any abilities file is registered\n";
$cited = array();
foreach ( glob( __DIR__ . '/../inc/abilities-*.php' ) as $abilities_file ) {
	if ( preg_match_all( "/'category'\s*=>\s*'([a-z0-9-]+)'/", (string) file_get_contents( $abilities_file ), $m ) ) {
		foreach ( $m[1] as $slug ) { $cited[ $slug ][] = basename( $abilities_file ); }
	}
}
ok( count( $cited ) >= 5, 'the scan found a plausible citation set (got ' . count( $cited ) . ' distinct categories)' );
foreach ( $cited as $slug => $files ) {
	ok( isset( $GLOBALS['__acg_cats'][ $slug ] ),
		"cited category '$slug' is registered (cited by " . implode( ', ', array_unique( $files ) ) . ')' );
}

// ════ Group E: read-ability null-input schema — STRUCTURAL (v9.79.2) ════
// The trap this generalizes bit THREE times (get-deploy-status, the v9.78.1
// widget reads, anchor-status — closed v9.78.2): a readonly ability rides
// the GET run-path, a caller that omits ?input= delivers NULL, and a plain
// 'object' input schema rejects every such call ("input is not of type
// object"). Each bite was fixed by pinning ONE ability. The structural rule:
// EVERY ability annotated readonly whose input schema declares no `required`
// list (i.e. it is legally callable bodyless) MUST type its input as the
// array( 'object', 'null' ) union. Abilities with a `required` list are
// exempt (a bodyless call is invalid for them anyway), as are write-verb
// (readonly=false) abilities (POST run-path always carries a body).
// Scans every inc/*.php wp_register_ability() call site with balanced-paren
// extraction, same spirit as Group D: a new read ability shipped with a
// write ability's schema shape now fails this suite instead of the live site.
echo "\nGroup E: every no-required readonly ability declares the [object,null] input union\n";
$acg_sites = array();
foreach ( glob( __DIR__ . '/../inc/*.php' ) as $acg_file ) {
	$acg_src    = (string) file_get_contents( $acg_file );
	$acg_offset = 0;
	while ( preg_match( "/wp_register_ability\(\s*'([^']+)'/", $acg_src, $acg_m, PREG_OFFSET_CAPTURE, $acg_offset ) ) {
		$acg_start = $acg_m[0][1];
		$acg_open  = strpos( $acg_src, '(', $acg_start );
		$acg_depth = 0;
		$acg_i     = $acg_open;
		$acg_len   = strlen( $acg_src );
		while ( $acg_i < $acg_len ) {
			$acg_c = $acg_src[ $acg_i ];
			if ( '(' === $acg_c ) { $acg_depth++; }
			if ( ')' === $acg_c ) { $acg_depth--; if ( 0 === $acg_depth ) { break; } }
			$acg_i++;
		}
		$acg_sites[] = array(
			'file'  => basename( $acg_file ),
			'slug'  => $acg_m[1][0],
			'block' => substr( $acg_src, $acg_start, $acg_i - $acg_start + 1 ),
		);
		$acg_offset = $acg_i;
	}
}
// Sanity floor: the registrar surface is 40+ abilities; a broken scan that
// finds a handful must fail loudly, not vacuously pass an empty loop.
ok( count( $acg_sites ) >= 40, 'the scan found the full ability surface (got ' . count( $acg_sites ) . ' call sites)' );

$acg_read_bodyless = 0;
foreach ( $acg_sites as $acg_site ) {
	$acg_block = $acg_site['block'];
	if ( ! preg_match( "/'readonly'\s*=>\s*true/", $acg_block ) ) {
		continue; // write-verb: POST run-path, body always present.
	}
	// Extract the input_schema sub-array (balanced from its own paren).
	$acg_schema = '';
	if ( preg_match( "/'input_schema'\s*=>\s*array\s*\(/", $acg_block, $acg_sm, PREG_OFFSET_CAPTURE ) ) {
		$acg_sopen = strpos( $acg_block, '(', $acg_sm[0][1] + strlen( "'input_schema'" ) );
		$acg_depth = 0;
		$acg_i     = $acg_sopen;
		$acg_len   = strlen( $acg_block );
		while ( $acg_i < $acg_len ) {
			$acg_c = $acg_block[ $acg_i ];
			if ( '(' === $acg_c ) { $acg_depth++; }
			if ( ')' === $acg_c ) { $acg_depth--; if ( 0 === $acg_depth ) { break; } }
			$acg_i++;
		}
		$acg_schema = substr( $acg_block, $acg_sopen, $acg_i - $acg_sopen + 1 );
	}
	ok( '' !== $acg_schema, "readonly ability '{$acg_site['slug']}' declares an input_schema at all ({$acg_site['file']})" );
	if ( preg_match( "/'required'\s*=>/", $acg_schema ) ) {
		continue; // a required list makes a bodyless call invalid anyway.
	}
	$acg_read_bodyless++;
	// The schema's TOP-LEVEL type is its first 'type' key (house style puts
	// it first; nested property types only ever appear later).
	$acg_union = preg_match( "/'type'\s*=>\s*array\s*\(\s*'object'\s*,\s*'null'\s*\)/", $acg_schema )
		&& preg_match( "/'type'\s*=>\s*(array|')/", $acg_schema, $acg_tm, PREG_OFFSET_CAPTURE )
		&& preg_match( "/^'type'\s*=>\s*array\s*\(\s*'object'\s*,\s*'null'\s*\)/", substr( $acg_schema, $acg_tm[0][1] ) );
	ok(
		(bool) $acg_union,
		"readonly, no-required ability '{$acg_site['slug']}' types its input array('object','null') — bodyless GET delivers null ({$acg_site['file']})"
	);
}
// The rule currently covers the 12 shipped read surfaces (get-deploy-status,
// anchor-status, uptime-status, the get-* readers...). Pin the floor so a
// scan regression that silently skips the readonly filter can't pass green.
ok( $acg_read_bodyless >= 12, "the gate actually exercised the read surface (got $acg_read_bodyless no-required readonly abilities)" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
