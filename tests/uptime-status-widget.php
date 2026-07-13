<?php
/**
 * Standalone fixture tests for inc/uptime-status-widget.php — since v8.3.0
 * the Uptime SECTION of the S&N Health widget (the standalone "S&N Uptime"
 * widget was consolidated away, owner call 2026-07-02).
 *
 * Contract under test:
 *   - REMOVAL GUARDS: no standalone dashboard widget registration remains
 *     (no wp_dashboard_setup hook, no wp_add_dashboard_widget call)
 *   - sn_uptime_status_health_section(): '' when unconfigured; heading +
 *     async mount when configured; token never in markup; ZERO HTTP
 *   - assets enqueue on index.php ONLY when configured (no token → no
 *     mount → shipping JS/CSS would be wasted requests), dep on
 *     snt-ability-run, nowhere else
 *
 * The integration (S&N Health widget appends the section via its
 * registered sn_site_health_widget_render_full callback) is asserted in
 * tests/site-health-widget.php.
 *
 * Run: php tests/uptime-status-widget.php
 *
 * @since plugin v8.2.0 (standalone widget), v8.3.0 (section rewrite)
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_URL', 'https://example.com/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '8.3.0' );

// ── Stubs ────────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }

$GLOBALS['__widgets'] = array();
function wp_add_dashboard_widget( $id, $title, $cb ) { $GLOBALS['__widgets'][ $id ] = array( 'title' => $title, 'cb' => $cb ); }

$GLOBALS['__scripts'] = array();
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__scripts'][ $handle ] = array( 'src' => $src, 'deps' => $deps ); }
$GLOBALS['__styles'] = array();
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { $GLOBALS['__styles'][ $handle ] = array( 'src' => $src, 'deps' => $deps ); }

// HTTP tripwire: ANY call from the section path is a contract violation.
$GLOBALS['__http_calls'] = 0;
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__http_calls']++; return array( 'code' => 200, 'body' => '{}' ); }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function get_transient( $k ) { return false; }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }

function wp_kses_post( $s ) { return (string) $s; }
function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); }

require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: strip + detail render through the primitive
require_once __DIR__ . '/../inc/uptime-status.php';
require_once __DIR__ . '/../inc/uptime-status-widget.php';

$pass = 0;
$fail = 0;
function uw_ok( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test 1: REMOVAL GUARDS — the standalone widget stays gone ───────
echo "\nTest 1: standalone widget removal guards (v8.3.0)\n";
foreach ( $GLOBALS['__actions']['wp_dashboard_setup'] ?? array() as $cb ) { $cb(); }
uw_ok( ! isset( $GLOBALS['__widgets']['sn_uptime_status'] ), 'no sn_uptime_status dashboard widget registered' );
$module_src = file_get_contents( __DIR__ . '/../inc/uptime-status-widget.php' );
uw_ok( false === strpos( $module_src, 'wp_add_dashboard_widget(' ), 'module contains no widget registration call' );
uw_ok( false === strpos( $module_src, "add_action( 'wp_dashboard_setup'" ), 'module no longer hooks wp_dashboard_setup' );

// ─── Test 2: section — unconfigured renders NOTHING ──────────────────
echo "\nTest 2: unconfigured section\n";
uw_ok( '' === sn_uptime_status_health_section(), 'empty string without a token (no prompt, no dead box)' );
uw_ok( 0 === $GLOBALS['__http_calls'], 'zero HTTP unconfigured' );

// ─── Test 3: configured section — heading + async mount, no HTTP ─────
echo "\nTest 3: configured section is an async shell\n";
$GLOBALS['__options']['sn_betterstack_api_token'] = 'secret-token-abcd1234';
$html = sn_uptime_status_health_section();
uw_ok( false !== strpos( $html, 'sn-uw-section' ), 'section wrapper present' );
uw_ok( false !== strpos( $html, '>Uptime<' ), 'section heading present' );
uw_ok( false !== strpos( $html, 'data-sn-uptime-status' ), 'async mount present' );
uw_ok( false === strpos( $html, 'secret-token-abcd1234' ), 'token never in markup' );
uw_ok( 0 === $GLOBALS['__http_calls'], 'zero HTTP on configured render (async contract)' );

// ─── Test 4: asset enqueue — index.php AND configured only ───────────
echo "\nTest 4: enqueue gating (configured + dashboard home)\n";
function uw_fire_enqueue( $hook ) {
	$GLOBALS['__scripts'] = array();
	$GLOBALS['__styles']  = array();
	foreach ( $GLOBALS['__actions']['admin_enqueue_scripts'] ?? array() as $cb ) { $cb( $hook ); }
}
uw_fire_enqueue( 'index.php' );
uw_ok( isset( $GLOBALS['__scripts']['sn-uptime-status'] ), 'script enqueued on the dashboard home when configured' );
uw_ok( in_array( 'snt-ability-run', $GLOBALS['__scripts']['sn-uptime-status']['deps'] ?? array(), true ), 'depends on snt-ability-run (the ONE ability transport)' );
uw_ok( isset( $GLOBALS['__styles']['sn-uptime-status'] ), 'stylesheet enqueued on the dashboard home' );

uw_fire_enqueue( 'edit.php' );
uw_ok( ! isset( $GLOBALS['__scripts']['sn-uptime-status'] ), 'not enqueued on unrelated admin screens' );

unset( $GLOBALS['__options']['sn_betterstack_api_token'] );
uw_fire_enqueue( 'index.php' );
uw_ok( ! isset( $GLOBALS['__scripts']['sn-uptime-status'] ), 'not enqueued unconfigured (no mount, no wasted requests)' );

// ─── Test 5: rail card — ONE uptime surface, native <details> (v9.37.0) ──
echo "\nTest 5: rail uptime card (merged surface, native details)\n";
unset( $GLOBALS['__options']['sn_betterstack_api_token'] );
uw_ok( '' === sn_uptime_status_rail_strip(), 'card: empty string without a token' );
$GLOBALS['__options']['sn_betterstack_api_token'] = 'secret-token-abcd1234';
$html = sn_uptime_status_rail_strip();
uw_ok( false !== strpos( $html, '<details class="sn-an-uptime">' ), 'card: native details wrapper (WAI-APG disclosure)' );
uw_ok( false === strpos( $html, '<details class="sn-an-uptime" open' ), 'card: starts collapsed' );
$sum_end = (int) strpos( $html, '</summary>' );
uw_ok( false !== strpos( substr( $html, 0, $sum_end ), 'data-sn-uptime-status' ), 'card: summary carries the status mount (monitor names + states ARE the summary)' );
uw_ok( false !== strpos( substr( $html, $sum_end ), 'data-sn-uptime-lazy-detail' ), 'card: expansion carries the LAZY detail mount' );
uw_ok( false === strpos( $html, 'data-sn-uptime-detail' ) || false !== strpos( $html, 'data-sn-uptime-lazy-detail' ), 'card: no eager detail attribute (page load costs the status tier only)' );
uw_ok( false !== strpos( $html, 'sn-an-postbox' ) && false !== strpos( $html, 'hndle' ), 'card: renders through the panel primitive (postbox feel kept)' );
uw_ok( false !== strpos( $html, '>Uptime<' ), 'card: heading present' );
uw_ok( false === strpos( $html, 'secret-token-abcd1234' ), 'token never in markup' );
uw_ok( 0 === $GLOBALS['__http_calls'], 'zero HTTP on the card render (async contract)' );

// ─── Test 6: the standalone detail panel is GONE ─────────────────────
echo "\nTest 6: detail panel retired (two-surfaces duplication killed)\n";
uw_ok( ! function_exists( 'sn_uptime_status_detail_panel' ), 'detail: sn_uptime_status_detail_panel() no longer exists (single surface)' );
uw_ok( ! in_array( 'sn_uptime_status_render_analytics_section', $GLOBALS['__actions']['snt_analytics_after_overview'] ?? array(), true ), 'module no longer hooks the after-Overview seam (header region seats the rail directly; the seam itself keeps firing)' );
uw_ok( false === strpos( $module_src, "add_action( 'snt_analytics_after_overview'" ), 'module source contains no seam hook' );

// ─── Test 7: JS lazy contract ────────────────────────────────────────
echo "\nTest 7: uptime-status.js lazy-detail contract\n";
$js = (string) file_get_contents( __DIR__ . '/../assets/uptime-status.js' );
uw_ok( false !== strpos( $js, 'data-sn-uptime-lazy-detail' ), 'JS knows the lazy mount' );
uw_ok( false !== strpos( $js, "addEventListener( 'toggle'" ) && false !== strpos( $js, 'sn-an-uptime' ), 'JS binds the native details toggle (capture — toggle does not bubble)' );
uw_ok( false !== strpos( $js, 'sn-an-panel-open' ), 'JS keeps the legacy panel-open path (postbox surfaces stay servable)' );
uw_ok( false !== strpos( $js, 'data-sn-uptime-loaded' ), 'JS once-guards the detail fetch' );

uw_fire_enqueue( 'dashboard_page_sn-analytics' );
uw_ok( isset( $GLOBALS['__scripts']['sn-uptime-status'] ), 'assets enqueued on the Analytics page hook when configured' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
