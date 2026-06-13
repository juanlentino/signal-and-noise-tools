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
function sn_analytics_top_dimension( $d, $f, $t, $c = 'human', $l = 25 ) { return array(); }
function sn_analytics_distribution( $m, $f, $t, $c = 'human' ) { return array(); }
function sn_analytics_granularity( $d ) { return ( (int) $d > 90 ) ? 'week' : 'day'; }
function snt_analytics_resolve_range( $r ) { return 'all' === (string) $r ? 'all' : ( (int) $r ?: 30 ); }
function snt_analytics_resolve_class( $c ) { return $c ?: 'human'; }
function snt_analytics_range_dates( $r, $n = null ) { return array( '2026-06-01', '2026-06-12' ); }

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
