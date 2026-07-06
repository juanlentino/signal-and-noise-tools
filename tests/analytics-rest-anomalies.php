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

require_once __DIR__ . '/../inc/analytics-rest.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Analytics REST — anomalies route\n\n";
$r = $GLOBALS['__routes']['/analytics/anomalies'] ?? null;
ok( null !== $r, 'route: /analytics/anomalies is registered' );
ok( $r && 'sn_analytics_rest_can_read' === $r['args']['permission_callback'], 'route: guarded by the manage_options read callback' );
ok( $r && 'sn_analytics_rest_anomalies' === $r['args']['callback'], 'route: dispatches to the anomalies handler' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
