<?php
/**
 * Standalone fixture tests for inc/uptime-status-widget.php (v8.2.0) —
 * the "S&N Uptime" dashboard widget.
 *
 * Contract under test (mirrors the site-health-widget discipline):
 *   - registration on wp_dashboard_setup, manage_options-gated
 *   - render is ZERO-COST: no HTTP ever (index.php renders on every
 *     admin login); data loads async via sntAbilityRun into the mount
 *   - unconfigured → settings prompt, no JS mount
 *   - configured → mount div + loading copy, still no HTTP
 *   - assets/uptime-status.js enqueued on index.php AND the SN admin
 *     pages (rail panel), dep on snt-ability-run, nowhere else
 *
 * Run: php tests/uptime-status-widget.php
 *
 * @since plugin v8.2.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_URL', 'https://example.com/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '8.2.0' );

// ── Stubs ────────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }

$GLOBALS['__can'] = true;
function current_user_can( $cap ) { return ! empty( $GLOBALS['__can'] ); }

$GLOBALS['__widgets'] = array();
function wp_add_dashboard_widget( $id, $title, $cb ) { $GLOBALS['__widgets'][ $id ] = array( 'title' => $title, 'cb' => $cb ); }

$GLOBALS['__scripts'] = array();
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__scripts'][ $handle ] = array( 'src' => $src, 'deps' => $deps ); }
$GLOBALS['__styles'] = array();
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { $GLOBALS['__styles'][ $handle ] = array( 'src' => $src, 'deps' => $deps ); }

// HTTP tripwire: ANY call during render is a contract violation.
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

// The widget consumes the data layer's configured()/mount helpers.
require_once __DIR__ . '/../inc/uptime-status.php';
require_once __DIR__ . '/../inc/uptime-status-widget.php';

$pass = 0;
$fail = 0;
function uw_ok( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

function uw_render() {
	$w = $GLOBALS['__widgets']['sn_uptime_status'] ?? null;
	if ( ! $w ) { return ''; }
	ob_start();
	call_user_func( $w['cb'] );
	return (string) ob_get_clean();
}
function uw_register() {
	$GLOBALS['__widgets'] = array();
	foreach ( $GLOBALS['__actions']['wp_dashboard_setup'] ?? array() as $cb ) { $cb(); }
}

// ─── Test 1: registration gate ───────────────────────────────────────
echo "\nTest 1: manage_options-gated registration\n";
$GLOBALS['__can'] = false;
uw_register();
uw_ok( ! isset( $GLOBALS['__widgets']['sn_uptime_status'] ), 'no widget without manage_options' );
$GLOBALS['__can'] = true;
uw_register();
uw_ok( isset( $GLOBALS['__widgets']['sn_uptime_status'] ), 'widget registered for admins' );

// ─── Test 2: unconfigured render — prompt, no mount, no HTTP ─────────
echo "\nTest 2: unconfigured render\n";
$html = uw_render();
uw_ok( false !== strpos( $html, 'page=sn-connections' ) && false !== strpos( $html, 'sub=webhooks' ), 'links to the Connections → Webhooks settings page' );
uw_ok( false === strpos( $html, 'data-sn-uptime-status' ), 'no JS mount when unconfigured' );
uw_ok( 0 === $GLOBALS['__http_calls'], 'zero HTTP on unconfigured render' );

// ─── Test 3: configured render — mount shell, still no HTTP ──────────
echo "\nTest 3: configured render is an async shell\n";
$GLOBALS['__options']['sn_betterstack_api_token'] = 'secret-token-abcd1234';
$html = uw_render();
uw_ok( false !== strpos( $html, 'data-sn-uptime-status' ), 'JS mount present when configured' );
uw_ok( false === strpos( $html, 'secret-token-abcd1234' ), 'token never in the widget markup' );
uw_ok( 0 === $GLOBALS['__http_calls'], 'zero HTTP on configured render (async contract)' );

// ─── Test 4: script enqueue surfaces ─────────────────────────────────
echo "\nTest 4: uptime-status.js enqueue gating\n";
function uw_fire_enqueue( $hook ) {
	$GLOBALS['__scripts'] = array();
	$GLOBALS['__styles']  = array();
	foreach ( $GLOBALS['__actions']['admin_enqueue_scripts'] ?? array() as $cb ) { $cb( $hook ); }
}
uw_fire_enqueue( 'index.php' );
uw_ok( isset( $GLOBALS['__scripts']['sn-uptime-status'] ), 'script enqueued on the dashboard home' );
uw_ok( in_array( 'snt-ability-run', $GLOBALS['__scripts']['sn-uptime-status']['deps'] ?? array(), true ), 'depends on snt-ability-run (the ONE ability transport)' );
uw_ok( isset( $GLOBALS['__styles']['sn-uptime-status'] ), 'stylesheet enqueued on the dashboard home' );

uw_fire_enqueue( 'edit.php' );
uw_ok( ! isset( $GLOBALS['__scripts']['sn-uptime-status'] ), 'not enqueued on unrelated admin screens' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
