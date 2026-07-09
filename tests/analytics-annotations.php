<?php
/**
 * Tests for inc/analytics-annotations.php — the rules-only panel-annotation
 * resolvers. Pure functions, so a plain CLI harness with inline stubs suffices.
 *
 * @since plugin v9.4.0
 */

// SECURITY: test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { return number_format( (float) $n ); }
}

require_once __DIR__ . '/../inc/analytics-annotations.php';

$pass = 0;
$fail = 0;
function an_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $m\n"; }
	else { $fail++; echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function an_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; }
}

echo "analytics-annotations resolver suite (v9.4.0)\n";

// ── read blocks are appended below by Tasks 3-6 ──

echo "\nmovers\n";
$down = array(
	array( 'path' => '/a', 'views' => 10, 'delta' => -40 ),
	array( 'path' => '/b', 'views' => 20, 'delta' => -30 ),
	array( 'path' => '/c', 'views' => 30, 'delta' => -20 ),
	array( 'path' => '/d', 'views' => 40, 'delta' =>  15 ),
);
an_eq( 'Movement skews down: 3 of 4 movers lost views.', sn_annotation_movers( $down ), 'down-skew read' );
$up = array(
	array( 'path' => '/a', 'views' => 50, 'delta' => 40 ),
	array( 'path' => '/b', 'views' => 40, 'delta' => 30 ),
	array( 'path' => '/c', 'views' => 30, 'delta' => 20 ),
);
an_eq( 'Movement skews up: 3 of 3 movers gained views.', sn_annotation_movers( $up ), 'up-skew read' );
$mixed = array(
	array( 'path' => '/a', 'views' => 10, 'delta' =>  40 ),
	array( 'path' => '/b', 'views' => 20, 'delta' => -30 ),
	array( 'path' => '/c', 'views' => 30, 'delta' =>  20 ),
	array( 'path' => '/d', 'views' => 40, 'delta' => -15 ),
);
an_eq( null, sn_annotation_movers( $mixed ), 'mixed movement -> null' );
an_eq( null, sn_annotation_movers( array( array( 'path' => '/a', 'views' => 1, 'delta' => -5 ) ) ), 'too few movers -> null' );
an_eq( null, sn_annotation_movers( array() ), 'empty -> null' );

echo "\nanomalies\n";
$mk = function ( $type, $n ) { $o = array(); for ( $i = 0; $i < $n; $i++ ) { $o[] = array( 'path' => "/p$i", 'type' => $type ); } return $o; };
an_eq( '2 pages skimmed: deep scroll, fast leave.', sn_annotation_anomalies( array( 'divergence' => $mk( 'skim', 2 ), 'outliers' => array() ) ), 'skim-only read' );
an_eq( '3 pages stalled: long dwell, low scroll.', sn_annotation_anomalies( array( 'divergence' => $mk( 'stall', 3 ), 'outliers' => array() ) ), 'stall-only read' );
an_eq( '2 pages skimmed: deep scroll, fast leave. 2 pages stalled: long dwell, low scroll.', sn_annotation_anomalies( array( 'divergence' => array_merge( $mk( 'skim', 2 ), $mk( 'stall', 2 ) ), 'outliers' => array() ) ), 'both types read' );
an_eq( null, sn_annotation_anomalies( array( 'divergence' => $mk( 'skim', 1 ), 'outliers' => array() ) ), 'below-threshold -> null' );
an_eq( null, sn_annotation_anomalies( array( 'divergence' => array(), 'outliers' => array() ) ), 'no anomalies -> null' );

echo "\nlifecycle\n";
$sum = function ( $cooling, $evergreen, $cands, $total ) {
	return array( 'counts' => array( 'spike' => 0, 'cooling' => $cooling, 'evergreen' => $evergreen, 'unknown' => 0 ), 'refresh_candidates' => $cands, 'total' => $total );
};
an_eq( '4 of 20 posts are cooling, and 3 are refresh candidates.', sn_annotation_lifecycle( $sum( 4, 10, 3, 20 ) ), 'refresh-candidate read' );
an_eq( 'Most of your catalogue holds: 15 of 20 posts are evergreen.', sn_annotation_lifecycle( $sum( 2, 15, 0, 20 ) ), 'evergreen-majority read' );
an_eq( null, sn_annotation_lifecycle( $sum( 1, 3, 0, 6 ) ), 'thin catalogue -> null' );
an_eq( null, sn_annotation_lifecycle( $sum( 2, 5, 1, 12 ) ), 'no strong signal -> null' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
