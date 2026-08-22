<?php
/**
 * Tests for inc/analytics-dashboard-page.php — the S&N Analytics screen on its
 * own top-level menu (v12.10.0; was a WP Dashboard submenu from v5.4.0):
 * add_menu_page registration (+ hook-suffix append for asset enqueue), the URL
 * and hook ACCESSORS, the legacy-URL redirect, and the render callback (cap
 * re-check, .wrap/<h1>, flash, dashboard body).
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

// add_menu_page seam — record args, return the hook suffix WP would produce.
$GLOBALS['__dp_add_calls'] = array();
function add_menu_page( $page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $pos = null ) {
	$GLOBALS['__dp_add_calls'][] = compact( 'page_title', 'menu_title', 'cap', 'slug', 'cb', 'icon', 'pos' );
	return 'toplevel_page_' . $slug;
}
// URL seams.
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function add_query_arg( $args, $url ) {
	$q = http_build_query( $args );
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $q;
}
// The producer calls exit after redirecting — correct in wp-admin, fatal to a
// test run. Mirrors the DP_Died seam above: record, then throw something
// catchable so the `exit` is never reached and the suite keeps going.
class DP_Redirected extends Exception {}
$GLOBALS['__dp_redirect'] = null;
function wp_safe_redirect( $u, $status = 302 ) {
	$GLOBALS['__dp_redirect'] = array( $u, $status );
	throw new DP_Redirected( (string) $u );
}
/** Invoke the redirect guard, absorbing the exit-substitute. */
function dp_try_redirect() {
	try { snt_analytics_redirect_legacy_url(); } catch ( DP_Redirected $e ) { /* expected */ }
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
ok( count( $GLOBALS['__dp_add_calls'] ) === 1, 'register: calls add_menu_page once' );
$call = $GLOBALS['__dp_add_calls'][0];
ok( $call['slug'] === 'sn-analytics', 'register: slug is sn-analytics' );
ok( $call['cap'] === 'manage_options', 'register: requires manage_options' );
ok( $call['cb'] === 'snt_analytics_dashboard_page', 'register: render callback wired' );
ok( in_array( 'toplevel_page_sn-analytics', sn_admin_page_hooks(), true ), 'register: appends the TOP-LEVEL page hook for asset enqueue' );
ok( 'S&N Analytics' === $call['menu_title'], 'register: sidebar label is "S&N Analytics" — distinct from the site name, and from any other plugin shipping a bare "Analytics"' );
ok( '' !== (string) $call['icon'], 'register: carries a menu icon (a top-level entry without one renders a blank slot)' );
ok( is_numeric( $call['pos'] ) && (float) $call['pos'] > 81 && (float) $call['pos'] < 82, 'register: seats directly beneath the Signal & Noise menu (81), not at an arbitrary index' );
ok( in_array( 'toplevel_page_sn-theme-options', sn_admin_page_hooks(), true ), 'register: APPENDS (does not clobber the existing hooks)' );
ok( isset( $GLOBALS['__dp_actions']['admin_menu'] ), 'register: hooked on admin_menu' );
$prio = $GLOBALS['__dp_actions']['admin_menu'][0][1];
ok( $prio > 10, 'register: priority > 10 so the main menu populates hooks first' );

echo "\nGroup: the URL and hook are ACCESSORS, not literals\n";
// Twelve call sites built this URL by hand and one pinned the hook suffix. The
// page moving would have broken each of them silently and separately, which is
// why the move shipped WITH these accessors rather than a careful find/replace.
ok( 'https://example.test/wp-admin/admin.php?page=sn-analytics' === snt_analytics_page_url(),
	'url: canonical URL is admin.php, NOT index.php — a Dashboard remap cannot claim it' );
ok( false !== strpos( snt_analytics_page_url( array( 'sn_view' => 'posts' ) ), 'sn_view=posts' ),
	'url: extra query args ride along' );
ok( 'toplevel_page_sn-analytics' === snt_analytics_page_hook(),
	'hook: reports the top-level suffix — inc/uptime-status-widget.php gates its enqueue on this' );
ok( snt_analytics_page_hook() === 'toplevel_page_' . SNT_ANALYTICS_PAGE_SLUG,
	'hook and slug agree, so neither can drift from the registration' );

echo "\nGroup: the pre-v12.10.0 URL still resolves\n";
$GLOBALS['pagenow'] = 'index.php';
$_GET = array( 'page' => 'sn-analytics', 'sn_view' => 'edge' );
$GLOBALS['__dp_redirect'] = null;
dp_try_redirect();
ok( is_array( $GLOBALS['__dp_redirect'] ), 'redirect: the old Dashboard URL redirects rather than 404ing into a permissions error' );
ok( 301 === $GLOBALS['__dp_redirect'][1], 'redirect: 301 — the move is permanent, so bookmarks and history update' );
ok( false !== strpos( $GLOBALS['__dp_redirect'][0], 'admin.php?page=sn-analytics' ), 'redirect: lands on the new top-level URL' );
ok( false !== strpos( $GLOBALS['__dp_redirect'][0], 'sn_view=edge' ), 'redirect: CARRIES the view through — a deep link keeps its destination, not just its page' );

$GLOBALS['pagenow'] = 'index.php';
$_GET = array();
$GLOBALS['__dp_redirect'] = null;
dp_try_redirect();
ok( null === $GLOBALS['__dp_redirect'], 'redirect: the ordinary Dashboard is untouched — this must never intercept index.php itself' );

$GLOBALS['pagenow'] = 'admin.php';
$_GET = array( 'page' => 'sn-analytics' );
$GLOBALS['__dp_redirect'] = null;
dp_try_redirect();
ok( null === $GLOBALS['__dp_redirect'], 'redirect: does NOT fire on the new URL — that would be a loop' );
$_GET = array();

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

echo "\nGroup: the migrated call sites cannot quietly come back\n";
// Twelve hand-built copies of the old URL existed across inc/ before this move,
// and one of them used a query arg the first find/replace pass did not match —
// found only by grepping again afterwards. A construction that reappears would
// point at a page that no longer exists, and would do it silently.
$dp_inc = glob( dirname( __DIR__ ) . '/inc/*.php' );
$dp_offenders = array();
foreach ( (array) $dp_inc as $dp_file ) {
	$dp_src = (string) file_get_contents( $dp_file );
	// The CONSTRUCTION, not the mention: the page's own docblock explains the
	// history and must stay readable.
	if ( false !== strpos( $dp_src, "admin_url( 'index.php?page=sn-analytics" ) ) {
		$dp_offenders[] = basename( $dp_file );
	}
}
ok( array() === $dp_offenders, 'no inc/ file rebuilds the legacy Analytics URL by hand' . ( $dp_offenders ? ' — FOUND: ' . implode( ', ', $dp_offenders ) : '' ) );
ok( count( $dp_inc ) > 50, 'sanity: the scan actually read the inc/ tree (' . count( $dp_inc ) . ' files) — an empty glob would pass the pin above vacuously' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
