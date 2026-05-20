<?php
/**
 * Standalone fixture tests for inc/admin-page.php tab plumbing.
 *
 * Targets the single-source-of-truth helpers introduced in v3.0.2:
 *
 *   - sn_admin_pages()           → canonical registry
 *   - sn_admin_page_valid_tabs() → array_column('tab')
 *   - sn_admin_page_tab_labels() → array_column('label', 'tab')
 *
 * **The bug this prevents:** v3.0.0 added the Cron tab to sn_admin_pages()
 * + dispatch case, but missed inline `$valid_tabs` and `$tab_labels`
 * whitelists 200 lines away in sn_theme_options_page(). The final
 * cross-cutting reviewer caught it. This test catches it at PR time
 * forever — if a new tab is added to sn_admin_pages() and the derived
 * helpers don't surface it, the assertion below fails before merge.
 *
 * Run:
 *     php tests/admin-tabs.php
 *
 * Exits 0 on all-pass, 1 on any failure.
 *
 * @since plugin v3.0.2
 */

// ─── WP stubs ─────────────────────────────────────────────────────────
define( 'ABSPATH', '/' );

// add_action just registers; doesn't fire on file include. No-op stub.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page() { return ''; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page() { return ''; }
}

// Anything required for top-level constants in admin-page.php.
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/../inc/admin-page.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function tabs_assert_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
		echo "    Expected: " . var_export( $expected, true ) . "\n";
		echo "    Actual:   " . var_export( $actual, true ) . "\n";
	}
}

function tabs_assert_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

// ─── Test 1: sn_admin_pages() registry shape ─────────────────────────
echo "\nTest 1: sn_admin_pages() registry shape\n";
$pages = sn_admin_pages();
tabs_assert_true( is_array( $pages ) && count( $pages ) > 0, 'sn_admin_pages() returns non-empty array' );

$required_keys = array( 'slug', 'tab', 'label', 'title', 'subtitle' );
foreach ( $pages as $i => $p ) {
	foreach ( $required_keys as $k ) {
		tabs_assert_true( array_key_exists( $k, $p ), "page[$i] has '$k' key" );
		tabs_assert_true( is_string( $p[ $k ] ) && $p[ $k ] !== '', "page[$i]['$k'] is non-empty string" );
	}
}

// ─── Test 2: unique slugs ─────────────────────────────────────────────
echo "\nTest 2: unique slugs (no duplicate registration)\n";
$slugs = array_column( $pages, 'slug' );
tabs_assert_eq( count( $slugs ), count( array_unique( $slugs ) ), 'all slugs unique' );

// ─── Test 3: unique tabs ──────────────────────────────────────────────
echo "\nTest 3: unique tabs (no duplicate dispatch)\n";
$tabs = array_column( $pages, 'tab' );
tabs_assert_eq( count( $tabs ), count( array_unique( $tabs ) ), 'all tabs unique' );

// ─── Test 4: sn_admin_page_valid_tabs() derived correctly ─────────────
echo "\nTest 4: sn_admin_page_valid_tabs() derives from sn_admin_pages()\n";
$valid = sn_admin_page_valid_tabs();
tabs_assert_eq( $tabs, $valid, 'valid_tabs matches array_column(pages, tab)' );

foreach ( $tabs as $tab ) {
	tabs_assert_true( in_array( $tab, $valid, true ), "tab '$tab' is in valid_tabs whitelist" );
}

// ─── Test 5: sn_admin_page_tab_labels() derived correctly ─────────────
echo "\nTest 5: sn_admin_page_tab_labels() maps every tab to its label\n";
$labels = sn_admin_page_tab_labels();
foreach ( $pages as $p ) {
	tabs_assert_true(
		isset( $labels[ $p['tab'] ] ) && $labels[ $p['tab'] ] === $p['label'],
		"tab_labels['{$p['tab']}'] === '{$p['label']}'"
	);
}

// ─── Test 6: the Cron tab (v3.0.0 regression guard) ───────────────────
echo "\nTest 6: Cron tab IS registered and resolvable end-to-end\n";
tabs_assert_true( in_array( 'cron', $valid, true ), "'cron' is in valid_tabs (would have caught the v3.0.0 bug)" );
tabs_assert_true( isset( $labels['cron'] ), "'cron' has a label in tab_labels (would have caught the v3.0.0 bug)" );
tabs_assert_eq( 'Cron', $labels['cron'], "'cron' label is 'Cron'" );

// ─── Test 7: sn_admin_page_tab_for_slug round-trip ────────────────────
echo "\nTest 7: sn_admin_page_tab_for_slug() round-trips each slug\n";
foreach ( $pages as $p ) {
	tabs_assert_eq(
		$p['tab'],
		sn_admin_page_tab_for_slug( $p['slug'] ),
		"slug '{$p['slug']}' → tab '{$p['tab']}'"
	);
}
tabs_assert_eq( 'dashboard', sn_admin_page_tab_for_slug( 'nonexistent-slug' ), 'unknown slug falls through to dashboard' );

// ─── Test 8: subtitle lookup ──────────────────────────────────────────
echo "\nTest 8: sn_admin_page_subtitle_for_tab() returns the registered subtitle\n";
foreach ( $pages as $p ) {
	tabs_assert_eq(
		$p['subtitle'],
		sn_admin_page_subtitle_for_tab( $p['tab'] ),
		"tab '{$p['tab']}' → subtitle"
	);
}
tabs_assert_eq( '', sn_admin_page_subtitle_for_tab( 'nonexistent-tab' ), 'unknown tab returns empty string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
