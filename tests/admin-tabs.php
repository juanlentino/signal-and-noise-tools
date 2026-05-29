<?php
/**
 * Standalone fixture tests for inc/admin-page.php tab plumbing.
 *
 * Targets the v3.8.0+ two-source architecture:
 *
 *   - sn_admin_top_tabs()            → canonical 6 top-level tabs;
 *                                      drives valid_tabs, tab_labels, subtitle.
 *   - sn_admin_pages()               → legacy 12-page registry kept as a
 *                                      slug→tab lookup table for the
 *                                      redirect layer (deep-link parity).
 *   - sn_admin_legacy_redirect_map() → legacy tab → canonical top tab
 *                                      (+sub-tab + anchor) for the
 *                                      301-redirect performed before dispatch.
 *
 * **The bug this prevents:** the original v3.0.0 regression added the Cron
 * tab to sn_admin_pages() + dispatch but missed two inline `$valid_tabs`
 * and `$tab_labels` whitelists 200 lines away. v3.0.2 replaced the inline
 * whitelists with derived helpers (single source of truth). v3.8.0
 * restructured the IA into 6 top tabs with the old 12 tabs becoming
 * sub-tabs, redirected via sn_admin_legacy_redirect_map(). The new
 * coordination constraint enforced here: every legacy tab is reachable
 * via EITHER sn_admin_top_tabs() OR sn_admin_legacy_redirect_map() —
 * catching the same class of bug under the new architecture.
 *
 * Run:
 *     php tests/admin-tabs.php
 *
 * Exits 0 on all-pass, 1 on any failure.
 *
 * @since plugin v3.0.2 (rewritten for v3.8.0+ two-source architecture)
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

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

require_once __DIR__ . '/../inc/admin-tabs-data.php';
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

// ─── Test 4: sn_admin_page_valid_tabs() derived from top tabs ─────────
// v3.8.0+: valid_tabs derives from sn_admin_top_tabs() (6 NEW top-level
// tabs), NOT sn_admin_pages() (the legacy 12-page registry kept as a
// slug→tab lookup table for the redirect layer). Legacy slugs reach
// canonical destinations via sn_admin_legacy_redirect_map() before dispatch.
echo "\nTest 4: sn_admin_page_valid_tabs() derives from sn_admin_top_tabs()\n";
$top_tabs = array_column( sn_admin_top_tabs(), 'tab' );
$valid    = sn_admin_page_valid_tabs();
tabs_assert_eq( $top_tabs, $valid, 'valid_tabs matches array_column(top_tabs, tab)' );

foreach ( $top_tabs as $tab ) {
	tabs_assert_true( in_array( $tab, $valid, true ), "tab '$tab' is in valid_tabs whitelist" );
}

// ─── Test 5: sn_admin_page_tab_labels() derived from top tabs ─────────
echo "\nTest 5: sn_admin_page_tab_labels() maps every top tab to its label\n";
$labels = sn_admin_page_tab_labels();
foreach ( sn_admin_top_tabs() as $p ) {
	tabs_assert_true(
		isset( $labels[ $p['tab'] ] ) && $labels[ $p['tab'] ] === $p['label'],
		"tab_labels['{$p['tab']}'] === '{$p['label']}'"
	);
}

// ─── Test 6: every legacy tab is reachable (top tab OR redirect map) ──
// The v3.0.0 regression added Cron to sn_admin_pages() + dispatch but
// missed two inline whitelists 200 lines away. v3.8.0+ replaced the
// whitelists with derived helpers AND moved Cron under Automation as a
// sub-tab, redirected via the legacy map. The coordination constraint
// is now: every legacy tab must be reachable. If a new tab is added to
// sn_admin_pages() without wiring up a redirect destination, this fails.
echo "\nTest 6: every legacy tab is reachable (top tab or redirect map)\n";
$map = sn_admin_legacy_redirect_map();
foreach ( $tabs as $legacy_tab ) {
	$reachable = in_array( $legacy_tab, $top_tabs, true ) || isset( $map[ $legacy_tab ] );
	tabs_assert_true( $reachable, "legacy tab '$legacy_tab' is reachable (top tab or in redirect map)" );

	if ( isset( $map[ $legacy_tab ] ) ) {
		tabs_assert_true(
			in_array( $map[ $legacy_tab ]['tab'], $top_tabs, true ),
			"redirect map['$legacy_tab'].tab points at a valid top tab"
		);
	}
}
// v3.0.0 regression spot-check: Cron's redirect is still wired.
tabs_assert_true( isset( $map['cron'] ), "'cron' has a legacy redirect entry (v3.0.0 regression guard)" );
tabs_assert_eq( 'automation', $map['cron']['tab'], "'cron' redirects to 'automation' (Cron is now a sub-tab)" );
tabs_assert_eq( 'cron', $map['cron']['sub'], "'cron' redirects to sub-tab 'cron'" );

// ─── Test 7: sn_admin_page_tab_for_slug round-trip ────────────────────
// Legacy page slugs resolve to their LEGACY tab name (not the canonical
// top tab). The legacy→top translation happens in
// sn_admin_maybe_redirect_legacy() one layer up. New top-tab slugs
// resolve to their canonical top-tab name directly.
echo "\nTest 7: sn_admin_page_tab_for_slug() round-trips each slug\n";
foreach ( $pages as $p ) {
	tabs_assert_eq(
		$p['tab'],
		sn_admin_page_tab_for_slug( $p['slug'] ),
		"legacy slug '{$p['slug']}' → tab '{$p['tab']}'"
	);
}
foreach ( sn_admin_top_tabs() as $p ) {
	tabs_assert_eq(
		$p['tab'],
		sn_admin_page_tab_for_slug( $p['slug'] ),
		"top-tab slug '{$p['slug']}' → tab '{$p['tab']}'"
	);
}
tabs_assert_eq( 'dashboard', sn_admin_page_tab_for_slug( 'nonexistent-slug' ), 'unknown slug falls through to dashboard' );

// ─── Test 8: subtitle lookup ──────────────────────────────────────────
// v3.8.0+: subtitles live in sn_admin_top_tabs() (the 6 NEW top tabs),
// not the legacy 12-page registry. Legacy tabs return '' since they
// 301-redirect away before dispatch ever reads a subtitle.
echo "\nTest 8: sn_admin_page_subtitle_for_tab() returns the registered subtitle\n";
foreach ( sn_admin_top_tabs() as $p ) {
	tabs_assert_eq(
		$p['subtitle'],
		sn_admin_page_subtitle_for_tab( $p['tab'] ),
		"tab '{$p['tab']}' → subtitle"
	);
}
tabs_assert_eq( '', sn_admin_page_subtitle_for_tab( 'nonexistent-tab' ), 'unknown tab returns empty string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
