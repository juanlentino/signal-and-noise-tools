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
// read-accessor + resolver stubs (production shapes):
function sn_analytics_range_totals( $f, $t, $c = 'human' ) { return array( 'views' => 7, 'visits' => 9, 'scroll_avg' => 50.0, 'time_avg' => 1000.0 ); }
function sn_analytics_daily_series( $f, $t, $c = 'human', $g = 'day' ) { return array(); }
$GLOBALS['__rest_dim'] = array(); // v9.68.1: null models the accessor's failed-read verdict
function sn_analytics_top_dimension( $d, $f, $t, $c = 'human', $l = 25 ) { return $GLOBALS['__rest_dim']; }
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

// v7.0.0: /analytics/summary + /analytics/events were REMOVED (replaced by the
// get-analytics-summary / get-analytics-events Abilities). The read-only dimension
// routes below have no Ability equivalent and are KEPT, as is the shared read gate.
echo "\nGroup: rest routes (read-only dimensions)\n";
ok( ! isset( $GLOBALS['__routes']['signal-noise/v1/analytics/summary'] ), 'summary route REMOVED in v7.0.0' );
$GLOBALS['__cap'] = false;
ok( sn_analytics_rest_can_read() instanceof WP_Error, 'shared read gate denies without manage_options' );
$GLOBALS['__cap'] = true;
ok( sn_analytics_rest_can_read() === true, 'shared read gate allows with manage_options' );
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/series'] ), 'series route registered' );
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/dimension/(?P<dim>[a-z]+)'] ), 'dimension route registered' );
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/distribution/(?P<metric>[a-z]+)'] ), 'distribution route registered' );

echo "\nGroup: events routes (event-props kept; /analytics/events removed in v7.0.0)\n";
ok( ! isset( $GLOBALS['__routes']['signal-noise/v1/analytics/events'] ), 'events route REMOVED in v7.0.0' );
ok( isset( $GLOBALS['__routes']['signal-noise/v1/analytics/event-props'] ), 'event-props route registered' );
$ep_args = $GLOBALS['__routes']['signal-noise/v1/analytics/event-props'];
ok( isset( $ep_args['methods'] ) && $ep_args['methods'] === 'GET', 'event-props route is GET (READABLE)' );
ok( isset( $ep_args['permission_callback'] ) && $ep_args['permission_callback'] === 'sn_analytics_rest_can_read', 'event-props permission_callback is sn_analytics_rest_can_read' );
$ep_resp = sn_analytics_rest_event_props( new WP_REST_Request( array( 'range' => 30, 'property' => 'page' ) ) );
ok( is_array( $ep_resp ) && isset( $ep_resp[0]['property'] ) && $ep_resp[0]['property'] === 'page', 'event-props callback passes property param through' );

echo "\nGroup: v9.68.1 — dimension route: a FAILED read is an explicit error, never a silent []\n";
$GLOBALS['__rest_dim'] = array( array( 'value' => 'AR', 'views' => 3, 'visits' => 2 ) );
$dim_ok = sn_analytics_rest_dimension( new WP_REST_Request( array( 'range' => 30, 'dim' => 'country' ) ) );
ok( is_array( $dim_ok ) && 'AR' === ( $dim_ok[0]['value'] ?? '' ), 'dimension: healthy rows pass through' );
$GLOBALS['__rest_dim'] = null; // the accessor's failed-read verdict
$dim_fail = sn_analytics_rest_dimension( new WP_REST_Request( array( 'range' => 30, 'dim' => 'country' ) ) );
ok( $dim_fail instanceof WP_Error, 'dimension: a failed read (accessor null) returns a WP_Error — never an empty-window []' );
ok( 500 === (int) ( $dim_fail->d['status'] ?? 0 ), 'dimension: the error carries HTTP 500 (a server-side read fault)' );
$GLOBALS['__rest_dim'] = array();
ok( array() === sn_analytics_rest_dimension( new WP_REST_Request( array( 'range' => 30, 'dim' => 'country' ) ) ),
	'dimension: an EMPTY window still serves [] (an answer, not an error)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
