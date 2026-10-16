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
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
$GLOBALS['__i18n_domains'] = array();
function __( $s, $d = 'default' ) { $GLOBALS['__i18n_domains'][] = $d; return $s; }
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}
class WP_REST_Request {
	private $h;
	public function __construct( $h = array() ) { $this->h = $h; }
	public function get_header( $k ) { return $this->h[ $k ] ?? ''; }
}

$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args ) { $GLOBALS['__routes'][ $ns . $route ] = $args; }

$GLOBALS['__rest_cb'] = null;
$GLOBALS['__shutdown'] = array();
function add_action( $h, $c = null, $p = 10, $a = 1 ) {
	if ( 'rest_api_init' === $h ) { $GLOBALS['__rest_cb'] = $c; }
	if ( 'shutdown' === $h ) { $GLOBALS['__shutdown'][] = $c; }
}

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

require __DIR__ . '/../inc/rest-no-route.php';
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
// 2026-10-16 audit: an unset token and a wrong key are indistinguishable from
// outside, and both read exactly like a route that was never registered: core's
// rest_no_route body (WP_REST_Server::dispatch()), in core's 'default' domain.
$is_no_route = static function ( $e ) {
	return $e instanceof WP_Error && 'rest_no_route' === $e->code
		&& 'No route was found matching the URL and request method.' === $e->message
		&& array( 'status' => 404 ) === $e->data;
};
$bad = sn_analytics_refresh_permission( new WP_REST_Request( array( 'x_sn_refresh_key' => 'nope' ) ) );
ok( $is_no_route( $bad ), 'perm: wrong key → core rest_no_route 404' );
$none = sn_analytics_refresh_permission( new WP_REST_Request( array() ) );
ok( $is_no_route( $none ), 'perm: missing key → core rest_no_route 404' );
$GLOBALS['__refresh_secret'] = '';
$unset = sn_analytics_refresh_permission( new WP_REST_Request( array( 'x_sn_refresh_key' => 'anything' ) ) );
ok( $is_no_route( $unset ), 'perm: unset secret → core rest_no_route 404 (fail closed, no 503 oracle)' );
ok( $unset == $bad && $bad == $none, 'perm: unset, wrong and missing are the SAME error, field for field' );
ok( 'default' === end( $GLOBALS['__i18n_domains'] ), 'perm: the message is translated in core\'s default domain, as core\'s is' );
// An empty key must never satisfy an (impossible) empty secret.
$GLOBALS['__refresh_secret'] = '';
$empty = sn_analytics_refresh_permission( new WP_REST_Request( array( 'x_sn_refresh_key' => '' ) ) );
ok( $is_no_route( $empty ), 'perm: empty secret + empty key → still rejected' );
$GLOBALS['__refresh_secret'] = 'sekret';

echo "\nGroup: callback answers first, runs BOTH triggers after the response\n";
// 2026-10-16: the rollup takes 8 to 35 s and the Worker aborts at 15 s, so the
// request must answer before the work runs (the cron record read "error", null).
$GLOBALS['__ran'] = array(); $GLOBALS['__shutdown'] = array();
$res = sn_analytics_refresh_run( new WP_REST_Request( array() ) );
ok( array() === $GLOBALS['__ran'], 'callback: runs nothing inside the request' );
ok( $res instanceof WP_REST_Response && 202 === $res->get_status(), 'callback: answers 202 Accepted' );
ok( $res instanceof WP_REST_Response && ! empty( $res->get_data()['ok'] ) && array( 'rollup', 'realtime' ) === $res->get_data()['queued'],
	'callback: returns {ok:true, queued:[rollup,realtime]}' );
ok( array( 'sn_analytics_refresh_after_response' ) === $GLOBALS['__shutdown'], 'callback: queues the work on shutdown, once' );
foreach ( $GLOBALS['__shutdown'] as $cb ) { call_user_func( $cb ); }
ok( array( 'rollup', 'realtime' ) === $GLOBALS['__ran'], 'shutdown: runs the durable rollup, then the realtime refresh' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
