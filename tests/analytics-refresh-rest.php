<?php
/**
 * Tests for inc/analytics-refresh-rest.php — the authenticated rollup-refresh
 * trigger the Cloudflare Cron worker POSTs to (replaces flaky WP-Cron freshness).
 * Self-contained: stubs the WP REST seam + the trigger functions, fires the
 * captured rest_api_init closure, and exercises the permission + callback.
 *
 * Run: php tests/analytics-refresh-rest.php
 *
 * @package SignalNoiseTools
 * @since   9.27.0
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_REST_NAMESPACE', 'signal-noise/v1' );

class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_Error {
	public $code; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->data = $d; }
}
class WP_REST_Request {
	private $h;
	public function __construct( $h = array() ) { $this->h = $h; }
	public function get_header( $k ) { return $this->h[ $k ] ?? ''; }
}

$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args ) { $GLOBALS['__routes'][ $ns . $route ] = $args; }

$GLOBALS['__rest_cb'] = null;
function add_action( $h, $c = null, $p = 10, $a = 1 ) { if ( 'rest_api_init' === $h ) { $GLOBALS['__rest_cb'] = $c; } }

// Secret override seam: production reads SN_SRV_TOKEN then applies this filter, so
// the test can toggle set / unset / rotated without redefining a constant.
$GLOBALS['__refresh_secret'] = 'sekret';
function apply_filters( $hook, $value ) {
	if ( 'sn_analytics_refresh_secret' === $hook && array_key_exists( '__refresh_secret', $GLOBALS ) ) {
		return $GLOBALS['__refresh_secret'];
	}
	return $value;
}

// Trigger-function spies — the endpoint must call BOTH.
$GLOBALS['__ran'] = array();
function sn_analytics_run_rollup() { $GLOBALS['__ran'][] = 'rollup'; }
function sn_analytics_realtime_refresh() { $GLOBALS['__ran'][] = 'realtime'; }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  ok: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }

require __DIR__ . '/../inc/analytics-refresh-rest.php';
call_user_func( $GLOBALS['__rest_cb'] ); // fire rest_api_init → registers the route

$route = $GLOBALS['__routes']['signal-noise/v1/analytics/refresh'] ?? null;

echo "Analytics refresh REST trigger\n\n";

echo "Group: route registration\n";
ok( is_array( $route ), 'route: /analytics/refresh registered' );
ok( $route && WP_REST_Server::CREATABLE === $route['methods'], 'route: is POST (CREATABLE), not GET' );
ok( $route && 'sn_analytics_refresh_permission' === $route['permission_callback'], 'route: token permission_callback (never __return_true)' );

echo "\nGroup: permission gate (fails closed)\n";
// Secret configured + correct key → allowed.
$GLOBALS['__refresh_secret'] = 'sekret';
$ok = sn_analytics_refresh_permission( new WP_REST_Request( array( 'x_sn_refresh_key' => 'sekret' ) ) );
ok( true === $ok, 'perm: correct key → true' );
// Wrong key → 403.
$bad = sn_analytics_refresh_permission( new WP_REST_Request( array( 'x_sn_refresh_key' => 'nope' ) ) );
ok( $bad instanceof WP_Error && 403 === ( $bad->data['status'] ?? 0 ), 'perm: wrong key → 403' );
// Missing key → 403.
$none = sn_analytics_refresh_permission( new WP_REST_Request( array() ) );
ok( $none instanceof WP_Error && 403 === ( $none->data['status'] ?? 0 ), 'perm: missing key → 403' );
// Unset secret → 503 (fail CLOSED, never runs open).
$GLOBALS['__refresh_secret'] = '';
$unset = sn_analytics_refresh_permission( new WP_REST_Request( array( 'x_sn_refresh_key' => 'anything' ) ) );
ok( $unset instanceof WP_Error && 503 === ( $unset->data['status'] ?? 0 ), 'perm: unset secret → 503 (fail closed)' );
// An empty key must never satisfy an (impossible) empty secret.
$GLOBALS['__refresh_secret'] = '';
$empty = sn_analytics_refresh_permission( new WP_REST_Request( array( 'x_sn_refresh_key' => '' ) ) );
ok( $empty instanceof WP_Error, 'perm: empty secret + empty key → still rejected' );

echo "\nGroup: callback runs BOTH triggers\n";
$GLOBALS['__ran'] = array();
$res = sn_analytics_refresh_run( new WP_REST_Request( array() ) );
ok( in_array( 'rollup', $GLOBALS['__ran'], true ), 'callback: runs the durable rollup' );
ok( in_array( 'realtime', $GLOBALS['__ran'], true ), 'callback: runs the realtime refresh' );
ok( is_array( $res ) && ! empty( $res['ok'] ) && in_array( 'rollup', $res['ran'], true ) && in_array( 'realtime', $res['ran'], true ),
	'callback: returns {ok:true, ran:[rollup,realtime]}' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
