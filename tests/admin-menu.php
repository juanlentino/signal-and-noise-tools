<?php
/**
 * Behavioral test for the SN admin asset enqueue (inc/admin-menu.php).
 *
 * Focus (v6.5.1): the dense Analytics dashboard CSS is enqueued as an EXTERNAL
 * stylesheet (assets/analytics/analytics-admin.css) on the admin_enqueue_scripts
 * hook, scoped to SN admin pages — NOT echoed inline by the render path. This test
 * fires the registered admin_menu + admin_enqueue_scripts callbacks against stubs
 * and asserts the stylesheet is registered (correct src / deps / version) on an SN
 * page and absent on a non-SN page.
 *
 * @since plugin v6.5.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '6.5.1-test' );
define( 'SNT_PATH', '/srv/plugin/' );

// Capture registered hook callbacks.
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $pri = 10, $args = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }

// Record enqueues.
$GLOBALS['__styles']  = array();
$GLOBALS['__scripts'] = array();
function wp_enqueue_style( $h, $src = '', $deps = array(), $ver = false, $media = 'all' ) { $GLOBALS['__styles'][ $h ]  = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver ); }
function wp_enqueue_script( $h, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__scripts'][ $h ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver ); }
function wp_set_script_translations( $h, $d ) { return true; }
function plugins_url( $path = '', $plugin = '' ) { return SNT_URL . ltrim( (string) $path, '/' ); }

// Menu registration stubs: return deterministic hook suffixes.
function add_menu_page( $pt, $mt, $cap, $slug, $cb = null, $icon = '', $pos = null ) { return 'toplevel_page_' . $slug; }
function add_submenu_page( $parent, $pt, $mt, $cap, $slug, $cb = null ) { return $parent . '_page_' . $slug; }

// v6.47.2: the Suggest+Apply JS enqueue resolves the active top-tab + sub-tab the
// SAME way the page dispatcher does (sn_admin_page_tab_for_slug /
// sn_admin_resolve_active_sub) so it tracks the IA. Load the REAL registry +
// resolvers here (instead of stubbing sn_admin_top_tabs) so the enqueue regression
// is pinned to the live sub-tab slugs. The bug it guards: the IA moved Health to
// `tab=monitoring&sub=health`, but the old guard checked `'health' === $_GET['tab']`,
// so the script was never enqueued and every Suggest button was dead.
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_unslash( $v ) { return $v; }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
require __DIR__ . '/../inc/admin-tabs-data.php';
require __DIR__ . '/../inc/admin-tabs.php';
require __DIR__ . '/../inc/admin-legacy-redirect.php';

require __DIR__ . '/../inc/admin-menu.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Admin menu — analytics stylesheet enqueue (external, scoped)\n\n";

// Fire admin_menu to populate the cached SN page-hook list.
foreach ( $GLOBALS['__actions']['admin_menu'] as $cb ) { $cb(); }
$hooks = sn_admin_page_hooks();
ok( ! empty( $hooks ), 'admin_menu populated sn_admin_page_hooks()' );

// Fire admin_enqueue_scripts against an SN page hook.
$sn_hook = (string) $hooks[0];
foreach ( $GLOBALS['__actions']['admin_enqueue_scripts'] as $cb ) { $cb( $sn_hook ); }

ok( isset( $GLOBALS['__styles']['sn-analytics-admin'] ), 'enqueue: sn-analytics-admin style registered on an SN page' );
$st = $GLOBALS['__styles']['sn-analytics-admin'] ?? array();
ok( isset( $st['src'] ) && strpos( (string) $st['src'], 'assets/analytics/analytics-admin.css' ) !== false,
	'enqueue: src points at assets/analytics/analytics-admin.css (external file, not inline)' );
ok( isset( $st['deps'] ) && in_array( 'sn-admin', (array) $st['deps'], true ),
	'enqueue: depends on sn-admin so it cascades after the base admin stylesheet' );
ok( isset( $st['deps'] ) && in_array( 'snt-analytics-tokens', (array) $st['deps'], true ),
	'enqueue: depends on snt-analytics-tokens (widget tokenization — ordering guaranteed by WP deps)' );
ok( ( $st['ver'] ?? null ) === SNT_VERSION, 'enqueue: cache-busted by SNT_VERSION' );

// Widget tokenization: the shared D4 token stylesheet also loads on SN admin pages.
ok( isset( $GLOBALS['__styles']['snt-analytics-tokens'] ), 'enqueue: snt-analytics-tokens style registered on an SN page' );
$tk = $GLOBALS['__styles']['snt-analytics-tokens'] ?? array();
ok( isset( $tk['src'] ) && strpos( (string) $tk['src'], 'assets/analytics/analytics-tokens.css' ) !== false,
	'enqueue: tokens src points at assets/analytics/analytics-tokens.css' );
ok( ( $tk['ver'] ?? null ) === SNT_VERSION, 'enqueue: tokens stylesheet cache-busted by SNT_VERSION' );

// Negative: scoped — NOT loaded on a non-SN admin page.
$GLOBALS['__styles'] = array();
foreach ( $GLOBALS['__actions']['admin_enqueue_scripts'] as $cb ) { $cb( 'edit.php' ); }
ok( ! isset( $GLOBALS['__styles']['sn-analytics-admin'] ), 'enqueue: NOT loaded on a non-SN admin page (scoped guard)' );
ok( ! isset( $GLOBALS['__styles']['snt-analytics-tokens'] ), 'enqueue: tokens stylesheet also NOT loaded on a non-SN admin page' );

// ── v6.47.2 regression: the shared Suggest+Apply JS (snt-health-suggest-actions)
// must load on exactly the leaves that render data-snt-suggest buttons —
// Monitoring → Health and Tools → Block Migrations — addressed by the ACTUAL IA
// URL shape (tab + sub), not a stale `?tab=` guess. Fire the real enqueue callback
// with the real routing params for each case. ──
echo "\nSuggest+Apply JS enqueue tracks the live IA (tab + sub), not a stale ?tab= guess\n";

// Fire admin_enqueue_scripts for a simulated request ($get becomes $_GET) on an
// SN page hook; return whether snt-health-suggest-actions was enqueued.
function sn_fire_suggest_enqueue( $get ) {
	$GLOBALS['__scripts'] = array();
	$_GET                 = $get;
	$hooks                = sn_admin_page_hooks();
	$hook                 = (string) $hooks[0];
	foreach ( $GLOBALS['__actions']['admin_enqueue_scripts'] as $cb ) { $cb( $hook ); }
	return isset( $GLOBALS['__scripts']['snt-health-suggest-actions'] );
}

ok( sn_fire_suggest_enqueue( array( 'page' => 'sn-theme-options', 'tab' => 'monitoring', 'sub' => 'health' ) ),
	'Monitoring → Health (tab=monitoring&sub=health) enqueues snt-health-suggest-actions [the reported bug]' );
ok( sn_fire_suggest_enqueue( array( 'page' => 'sn-monitoring', 'sub' => 'health' ) ),
	'Monitoring → Health via the sidebar slug (page=sn-monitoring, no ?tab=) also enqueues it' );
ok( sn_fire_suggest_enqueue( array( 'page' => 'sn-theme-options', 'tab' => 'content', 'sub' => 'block-migrations' ) ),
	'Content → Block Migrations enqueues it (v10.46.0: moved from Tools)' );
ok( sn_fire_suggest_enqueue( array( 'page' => 'sn-theme-options', 'tab' => 'content', 'sub' => 'pattern-adoption' ) ),
	'Content → Pattern Adoption enqueues it (v10.46.0: promoted out of the Health tab, where it used to inherit Health\'s enqueue)' );
ok( ! sn_fire_suggest_enqueue( array( 'page' => 'sn-theme-options', 'tab' => 'monitoring', 'sub' => 'analytics' ) ),
	'Measurement → Analytics does NOT enqueue it (no Suggest buttons on that leaf)' );
ok( ! sn_fire_suggest_enqueue( array( 'page' => 'sn-theme-options', 'tab' => 'tools', 'sub' => 'block-migrations' ) ),
	'the vacated Tools → Block Migrations address does NOT enqueue it (the leaf is gone from there)' );
ok( ! sn_fire_suggest_enqueue( array( 'tab' => 'health' ) ),
	'the stale legacy assumption (tab=health) does NOT enqueue it (guard tracks the real IA, not ?tab=)' );

// ── The guard is only as good as its agreement with the registry. Every entry
// in sn_admin_suggest_js_leaves() must name a leaf that actually exists; a leaf
// that moves without updating the list is the v6.47.2 dead-button outage again,
// and it produces no error of its own. This turns it into a failing test. ──
echo "\nEvery suggest-JS leaf still resolves against the live registry\n";
$__by_tab = array();
foreach ( sn_admin_top_tabs() as $__t ) { $__by_tab[ $__t['tab'] ] = $__t; }
foreach ( sn_admin_suggest_js_leaves() as $__pair ) {
	list( $__tab, $__sub ) = explode( '/', $__pair );
	ok( isset( $__by_tab[ $__tab ]['sub_tabs'][ $__sub ] ),
		"suggest-JS leaf '$__pair' exists in sn_admin_top_tabs()" );
	ok( sn_fire_suggest_enqueue( array( 'page' => 'sn-theme-options', 'tab' => $__tab, 'sub' => $__sub ) ),
		"suggest-JS leaf '$__pair' actually enqueues the script at its registry address" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
