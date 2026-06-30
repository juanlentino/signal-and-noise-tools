<?php
/**
 * Tests for inc/analytics-rest.php — read-only REST surface.
 * Self-contained: stubs WP REST seam + read accessors, fires the captured
 * rest_api_init closure.
 *
 * @package SignalNoiseTools
 * @since   6.1.0
 */
define( 'ABSPATH', '/' );
define( 'SN_REST_NAMESPACE', 'signal-noise/v1' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_Error { public $d; public function __construct( $c = '', $m = '', $d = array() ) { $this->d = $d; } }
class WP_REST_Request { private $p; public function __construct( $p = array() ) { $this->p = $p; } public function get_param( $k ) { return $this->p[ $k ] ?? null; } }
$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args ) { $GLOBALS['__routes'][ $ns . $route ] = $args; }
$GLOBALS['__cap'] = true;
function current_user_can( $c ) { return $GLOBALS['__cap']; }
function rest_authorization_required_code() { return 401; }
$GLOBALS['__rest_cb'] = null;
function add_action( $h, $c = null, $p = 10, $a = 1 ) { if ( 'rest_api_init' === $h ) { $GLOBALS['__rest_cb'] = $c; } }
// v6.54.0: the REST handlers now emit a deprecation notice (inc/rest-deprecations.php); stub it
// no-op here — its behavior is asserted in tests/rest-deprecations.php, not this suite.
function snt_rest_deprecated_notice( $route = '', $ability = '' ) {}
// read-accessor + resolver stubs (production shapes):
function sn_analytics_range_totals( $f, $t, $c = 'human' ) { return array( 'views' => 7, 'visits' => 9, 'scroll_avg' => 50.0, 'time_avg' => 1000.0 ); }
function sn_analytics_daily_series( $f, $t, $c = 'human', $g = 'day' ) { return array(); }
function sn_analytics_top_dimension( $d, $f, $t, $c = 'human', $l = 25 ) { return array(); }
function sn_analytics_distribution( $m, $f, $t, $c = 'human' ) { return array(); }
function sn_analytics_granularity( $d ) { return ( (int) $d > 90 ) ? 'week' : 'day'; }
function snt_analytics_resolve_range( $r ) { return 'all' === (string) $r ? 'all' : ( (int) $r ?: 30 ); }
function snt_analytics_resolve_class( $c ) { return $c ?: 'human'; }
function snt_analytics_range_dates( $r, $n = null ) { return array( '2026-06-01', '2026-06-12' ); }
// event-accessor stubs (sentinel values to verify passthrough):
function sn_analytics_top_events( $f, $t, $l = 25 ) { return array( array( 'name' => 'pageview', 'events' => 42, 'visitors' => 17 ) ); }
function sn_analytics_top_event_props( $f, $t, $property = '', $l = 50 ) { return array( array( 'property' => $property, 'value' => 'blog', 'events' => 5, 'visitors' => 3 ) ); }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  ok: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }

require __DIR__ . '/../inc/analytics-rest.php';
call_user_func( $GLOBALS['__rest_cb'] ); // fire the rest_api_init closure → registers routes

echo "\nGroup: rest routes\n";
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/summary'] ), 'summary route registered' );
$args = $GLOBALS['__routes']['signal-noise/v1/analytics/summary'];
ok( $args['methods'] === 'GET', 'summary is a GET (READABLE) route' );
ok( $args['permission_callback'] === 'sn_analytics_rest_can_read', 'permission_callback gates the route' );
$GLOBALS['__cap'] = false;
ok( sn_analytics_rest_can_read() instanceof WP_Error, 'denies without manage_options' );
$GLOBALS['__cap'] = true;
ok( sn_analytics_rest_can_read() === true, 'allows with manage_options' );
$resp = sn_analytics_rest_summary( new WP_REST_Request( array( 'range' => 30, 'class' => 'human' ) ) );
ok( isset( $resp['views'] ) && $resp['views'] === 7, 'summary callback returns durable totals' );
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/series'] ), 'series route registered' );
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/dimension/(?P<dim>[a-z]+)'] ), 'dimension route registered' );
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/distribution/(?P<metric>[a-z]+)'] ), 'distribution route registered' );

echo "\nGroup: events routes\n";
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/events'] ), 'events route registered' );
$ev_args = $GLOBALS['__routes']['signal-noise/v1/analytics/events'];
ok( isset( $ev_args['methods'] ) && $ev_args['methods'] === 'GET', 'events route is GET (READABLE)' );
ok( isset( $ev_args['permission_callback'] ) && $ev_args['permission_callback'] === 'sn_analytics_rest_can_read', 'events permission_callback is sn_analytics_rest_can_read' );
$ev_resp = sn_analytics_rest_events( new WP_REST_Request( array( 'range' => 30 ) ) );
ok( is_array( $ev_resp ) && isset( $ev_resp[0]['name'] ) && $ev_resp[0]['name'] === 'pageview', 'events callback returns accessor data' );

ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/event-props'] ), 'event-props route registered' );
$ep_args = $GLOBALS['__routes']['signal-noise/v1/analytics/event-props'];
ok( isset( $ep_args['methods'] ) && $ep_args['methods'] === 'GET', 'event-props route is GET (READABLE)' );
ok( isset( $ep_args['permission_callback'] ) && $ep_args['permission_callback'] === 'sn_analytics_rest_can_read', 'event-props permission_callback is sn_analytics_rest_can_read' );
$ep_resp = sn_analytics_rest_event_props( new WP_REST_Request( array( 'range' => 30, 'property' => 'page' ) ) );
ok( is_array( $ep_resp ) && isset( $ep_resp[0]['property'] ) && $ep_resp[0]['property'] === 'page', 'event-props callback passes property param through' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
