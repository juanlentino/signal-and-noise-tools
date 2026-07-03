<?php
/**
 * Standalone tests: cache freshness indicator (routes + card + enqueue).
 * Run: php tests/freshness-indicator.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// Functional filter registry so filterability is genuinely exercised.
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		foreach ( ( $GLOBALS['__filters'][ $tag ] ?? array() ) as $cb ) { $value = call_user_func( $cb, $value ); }
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

require_once __DIR__ . '/../inc/freshness-indicator.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Freshness indicator: routes\n";
$routes = snt_freshness_routes();
ok( is_array( $routes ) && in_array( '/', $routes, true ), 'default routes include the homepage' );
ok( in_array( '/notes/', $routes, true ) && in_array( '/provenance/', $routes, true ), 'default routes include the known virtual routes' );

// Filterable: a caller can add a route.
add_filter( 'snt_freshness_routes', function ( $r ) { $r[] = '/uses/'; return $r; } );
$routes2 = snt_freshness_routes();
ok( in_array( '/uses/', $routes2, true ), 'route list is filterable' );

// Normalized: empties/dupes removed, reindexed.
add_filter( 'snt_freshness_routes', function ( $r ) { $r[] = ''; $r[] = '/'; return $r; } );
$routes3 = snt_freshness_routes();
ok( ! in_array( '', $routes3, true ), 'empty routes are dropped' );
ok( count( $routes3 ) === count( array_unique( $routes3 ) ), 'routes are unique' );
ok( array_keys( $routes3 ) === range( 0, count( $routes3 ) - 1 ), 'routes array is reindexed (list)' );

echo "\nFreshness indicator: card\n";
$card = snt_freshness_card();
ok( is_array( $card ), 'card is an array' );
ok( ( $card['label'] ?? '' ) === 'Caches', 'card label is Caches' );
ok( ( $card['id'] ?? '' ) === 'snt-freshness-card', 'card carries the JS-target id' );
ok( ! empty( $card['value'] ), 'card has a neutral placeholder value' );
ok( ! isset( $card['pill'] ), 'card renders NO pill server-side (JS injects it after the check)' );

echo "\nFreshness indicator: enqueue\n";

// Constants + WP stubs the enqueue path touches.
if ( ! defined( 'SNT_URL' ) ) { define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', __DIR__ . '/../' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '9.9.9-test' ); }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $p = '', $f = '' ) { return SNT_URL . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
// Named seam the enqueue guard uses. Pretend one SN admin page hook exists.
if ( ! function_exists( 'sn_admin_page_hooks' ) ) { function sn_admin_page_hooks( $s = null ) { return array( 'toplevel_page_sn-theme-options' ); } }

$GLOBALS['__reg'] = array(); $GLOBALS['__loc'] = array(); $GLOBALS['__enq'] = array();
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script( $h, $src = '', $d = array(), $v = false, $f = false ) { $GLOBALS['__reg'][ $h ] = array( 'src' => $src, 'deps' => $d, 'ver' => $v, 'foot' => $f ); } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( $h, $obj, $data ) { $GLOBALS['__loc'][ $h ] = array( 'obj' => $obj, 'data' => $data ); } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( $h ) { $GLOBALS['__enq'][] = $h; } }

ok( function_exists( 'snt_freshness_enqueue' ), 'enqueue: snt_freshness_enqueue() is a named, testable callback' );

// On an SN admin page hook, the script registers + localizes + enqueues.
$GLOBALS['__reg'] = array(); $GLOBALS['__loc'] = array(); $GLOBALS['__enq'] = array();
snt_freshness_enqueue( 'toplevel_page_sn-theme-options' );
ok( isset( $GLOBALS['__reg']['sn-freshness-dot'] ), 'registers the sn-freshness-dot script on an SN admin page' );
ok( strpos( (string) ( $GLOBALS['__reg']['sn-freshness-dot']['src'] ?? '' ), 'freshness-dot.js' ) !== false, 'points at assets/freshness-dot.js' );
ok( in_array( 'sn-freshness-dot', $GLOBALS['__enq'], true ), 'enqueues the script' );
$loc = $GLOBALS['__loc']['sn-freshness-dot']['data'] ?? array();
ok( ( $GLOBALS['__loc']['sn-freshness-dot']['obj'] ?? '' ) === 'sntFreshness', 'localizes under the sntFreshness object' );
ok( ! empty( $loc['routes'] ) && is_array( $loc['routes'] ), 'localizes the routes list' );
ok( ( $loc['cardId'] ?? '' ) === 'snt-freshness-card', 'localizes the card id' );

// On a NON-SN screen, nothing is enqueued.
$GLOBALS['__reg'] = array(); $GLOBALS['__enq'] = array();
snt_freshness_enqueue( 'edit.php' );
ok( empty( $GLOBALS['__enq'] ), 'does NOT enqueue on a non-SN admin screen' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
