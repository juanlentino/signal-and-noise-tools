<?php
/**
 * Asserts the /analytics/anomalies route is registered with the read guard.
 * Run: php tests/analytics-rest-anomalies.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; } }
$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args = array() ) { $GLOBALS['__routes'][ $route ] = array( 'ns' => $ns, 'args' => $args ); return true; }
function add_action( $h, $c = null, $p = 10, $a = 1 ) { if ( 'rest_api_init' === $h && is_callable( $c ) ) { $c(); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
// #1620: the args schema on every route derives its enums from the two
// vocabulary constants, loaded before inc/analytics-rest.php in production
// (inc/analytics-admin.php and inc/analytics-rollup.php). The real ranges
// constant comes from its own file; the classes one is DB-bound and is
// defined here the way every analytics sibling suite defines it.
function __( $s, $d = '' ) { return $s; }
require_once __DIR__ . '/../inc/analytics-admin.php';
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );

require_once __DIR__ . '/../inc/analytics-rest.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Analytics REST — anomalies route\n\n";
$r = $GLOBALS['__routes']['/analytics/anomalies'] ?? null;
ok( null !== $r, 'route: /analytics/anomalies is registered' );
ok( $r && 'sn_analytics_rest_can_read' === $r['args']['permission_callback'], 'route: guarded by the manage_options read callback' );
ok( $r && 'sn_analytics_rest_anomalies' === $r['args']['callback'], 'route: dispatches to the anomalies handler' );
ok( $r && array_merge( array_map( 'strval', SN_ANALYTICS_RANGES ), array( 'all' ) ) === ( $r['args']['args']['range']['enum'] ?? null ), 'route: range is a string enum of SN_ANALYTICS_RANGES + all, so an unknown window is a 400 and never a 7-day answer (#1620)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
