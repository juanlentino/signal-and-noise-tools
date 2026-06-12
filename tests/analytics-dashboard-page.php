<?php
/**
 * Tests for inc/analytics-dashboard-page.php — the native WP Dashboard → Analytics
 * page: add_dashboard_page registration (+ hook-suffix append for asset enqueue)
 * and the render callback (cap re-check, .wrap/<h1>, flash, dashboard body).
 * Run: php tests/analytics-dashboard-page.php
 * @since plugin v5.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }

$GLOBALS['__dp_cap'] = true;
function current_user_can( $c ) { return $GLOBALS['__dp_cap']; }

class DP_Died extends Exception {}
$GLOBALS['__dp_died'] = false;
function wp_die( $m = '', $t = '', $a = array() ) { $GLOBALS['__dp_died'] = true; throw new DP_Died( (string) $m ); }

// add_action seam — capture (hook → [callbacks]) so we can invoke the registrar.
$GLOBALS['__dp_actions'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__dp_actions'][ $hook ][] = array( $cb, $prio ); }

// add_dashboard_page seam — record args, return a hook suffix.
$GLOBALS['__dp_add_calls'] = array();
function add_dashboard_page( $page_title, $menu_title, $cap, $slug, $cb, $pos = null ) {
	$GLOBALS['__dp_add_calls'][] = compact( 'page_title', 'menu_title', 'cap', 'slug', 'cb', 'pos' );
	return 'dashboard_page_' . $slug;
}

// sn_admin_page_hooks seam — replace-on-set accessor (mirrors inc/admin-menu.php).
$GLOBALS['__dp_hooks'] = array();
function sn_admin_page_hooks( $set = null ) {
	if ( is_array( $set ) ) { $GLOBALS['__dp_hooks'] = array_values( array_filter( $set, 'is_string' ) ); }
	return $GLOBALS['__dp_hooks'];
}

// Flash resolver seam.
function sn_admin_flash_to_notice( $code ) {
	return 'analytics_saved' === $code ? array( 'success', 'Saved.' ) : null;
}

// The read view — stub to a marker so the page can be tested without the renderer.
$GLOBALS['__dp_dash_calls'] = 0;
function snt_analytics_render_dashboard() { $GLOBALS['__dp_dash_calls']++; echo '<!--DASHBOARD-BODY-->'; }

require_once __DIR__ . '/../inc/analytics-dashboard-page.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function capture( $cb ) { ob_start(); try { $cb(); } catch ( DP_Died $e ) {} return ob_get_clean(); }

echo "Analytics dashboard page (WP Dashboard → Analytics)\n\n";

echo "Group: registration\n";
$GLOBALS['__dp_hooks'] = array( 'toplevel_page_sn-theme-options' ); // pretend the main menu already registered
snt_analytics_register_dashboard_page();
ok( count( $GLOBALS['__dp_add_calls'] ) === 1, 'register: calls add_dashboard_page once' );
$call = $GLOBALS['__dp_add_calls'][0];
ok( $call['slug'] === 'sn-analytics', 'register: slug is sn-analytics' );
ok( $call['cap'] === 'manage_options', 'register: requires manage_options' );
ok( $call['cb'] === 'snt_analytics_dashboard_page', 'register: render callback wired' );
ok( in_array( 'dashboard_page_sn-analytics', sn_admin_page_hooks(), true ), 'register: appends the page hook for asset enqueue' );
ok( in_array( 'toplevel_page_sn-theme-options', sn_admin_page_hooks(), true ), 'register: APPENDS (does not clobber the existing hooks)' );
ok( isset( $GLOBALS['__dp_actions']['admin_menu'] ), 'register: hooked on admin_menu' );
$prio = $GLOBALS['__dp_actions']['admin_menu'][0][1];
ok( $prio > 10, 'register: priority > 10 so the main menu populates hooks first' );

echo "\nGroup: render callback (authorized)\n";
$GLOBALS['__dp_cap']        = true;
$GLOBALS['__dp_dash_calls'] = 0;
unset( $_GET['sn_flash'] );
$html = capture( 'snt_analytics_dashboard_page' );
ok( strpos( $html, '<div class="wrap">' ) !== false, 'render: opens .wrap' );
ok( strpos( $html, '<h1>Analytics</h1>' ) !== false, 'render: page <h1> heading' );
ok( strpos( $html, '<!--DASHBOARD-BODY-->' ) !== false && $GLOBALS['__dp_dash_calls'] === 1, 'render: delegates to snt_analytics_render_dashboard' );
ok( substr_count( $html, '</div>' ) >= 1, 'render: closes the wrap' );

echo "\nGroup: render callback (flash notice)\n";
$_GET['sn_flash'] = 'analytics_saved';
$html = capture( 'snt_analytics_dashboard_page' );
ok( strpos( $html, 'notice-success' ) !== false && strpos( $html, 'Saved.' ) !== false, 'render: resolves ?sn_flash into a notice' );
$_GET['sn_flash'] = 'bogus_code';
$html = capture( 'snt_analytics_dashboard_page' );
ok( strpos( $html, 'notice-success' ) === false, 'render: unknown flash code → no notice' );
unset( $_GET['sn_flash'] );

echo "\nGroup: render callback (capability gate)\n";
$GLOBALS['__dp_cap']  = false;
$GLOBALS['__dp_died'] = false;
$html = capture( 'snt_analytics_dashboard_page' );
ok( $GLOBALS['__dp_died'] === true, 'render: re-checks the capability (wp_die when lacking manage_options)' );
ok( strpos( $html, '<!--DASHBOARD-BODY-->' ) === false, 'render: no dashboard body rendered to an unauthorized user' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
