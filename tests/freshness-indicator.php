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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
