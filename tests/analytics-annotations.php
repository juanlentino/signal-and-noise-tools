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

echo "\noverview\n";
$dv = function ( $pct, $dir ) { return array( 'views' => array( 'pct' => $pct, 'dir' => $dir ) ); };
an_eq( 'Views up 38%, but engaged rate slipped: more traffic, shallower visits.', sn_annotation_overview( $dv( 38, 'up' ), array( 'dir' => 'down' ) ), 'up-views / down-engagement divergence' );
an_eq( 'Views down 22%, but engaged rate rose: fewer visits, but stickier.', sn_annotation_overview( $dv( 22, 'down' ), array( 'dir' => 'up' ) ), 'down-views / up-engagement divergence' );
an_eq( null, sn_annotation_overview( $dv( 38, 'up' ), array( 'dir' => 'up' ) ), 'same direction -> null' );
an_eq( null, sn_annotation_overview( $dv( 5, 'up' ), array( 'dir' => 'down' ) ), 'below views threshold -> null' );
an_eq( null, sn_annotation_overview( array(), array( 'dir' => 'down' ) ), 'no deltas (all range) -> null' );
an_eq( null, sn_annotation_overview( $dv( 38, 'up' ), array() ), 'no engaged dir -> null' );

echo "\ntop pages\n";
// { path, views, visits, ... }, views DESC. Share is of the returned rows' views
// (the panel has no grand total in scope), so the copy says "top pages", not "range".
$tp = function ( array $views ) { $r = array(); foreach ( $views as $i => $v ) { $r[] = array( 'path' => "/p$i", 'views' => $v, 'visits' => $v ); } return $r; };
an_eq( 'One page holds 61% of your top pages\' views: traffic is concentrated.', sn_annotation_top_pages( $tp( array( 61, 15, 14, 10 ) ) ), 'dominant top page -> read' );
an_eq( 'One page holds 55% of your top pages\' views: traffic is concentrated.', sn_annotation_top_pages( $tp( array( 55, 20, 15, 10 ) ) ), 'exactly at threshold -> read' );
an_eq( null, sn_annotation_top_pages( $tp( array( 30, 25, 25, 20 ) ) ), 'views spread across pages -> null' );
an_eq( null, sn_annotation_top_pages( $tp( array( 80, 20 ) ) ), 'too few pages -> null' );
an_eq( null, sn_annotation_top_pages( array() ), 'empty -> null' );
an_eq( null, sn_annotation_top_pages( $tp( array( 0, 0, 0, 0 ) ) ), 'zero views -> null' );

echo "\nsources\n";
// referrer_categories: 4 fixed rows { category, label, views, visits }. Direct is
// isolated cleanly here (unlike top_sources, which folds unknown into direct).
$rc = function ( $direct, $search, $social, $other ) {
	return array(
		array( 'category' => 'direct', 'label' => 'Direct', 'views' => $direct, 'visits' => $direct ),
		array( 'category' => 'search', 'label' => 'Search', 'views' => $search, 'visits' => $search ),
		array( 'category' => 'social', 'label' => 'Social', 'views' => $social, 'visits' => $social ),
		array( 'category' => 'other',  'label' => 'Other',  'views' => $other,  'visits' => $other ),
	);
};
an_eq( '88% of visits are direct, with little referral: an owned audience, not discovered.', sn_annotation_sources( $rc( 88, 6, 4, 2 ) ), 'direct-dominant -> read' );
an_eq( null, sn_annotation_sources( $rc( 60, 20, 15, 5 ) ), 'balanced mix -> null' );
an_eq( null, sn_annotation_sources( $rc( 20, 4, 3, 2 ) ), 'below min volume -> null' );
an_eq( null, sn_annotation_sources( array() ), 'empty -> null' );

echo "\ngeography\n";
// { value, views, visits } from sn_analytics_top_dimension('country',…,250) — every
// country, so the visits sum is a true total. Markets are ranked by visits (unordered
// input on purpose). Needs >=4 markets: with 3, top-2 is mathematically >=67%, trivial.
$geo = function ( array $visits ) { $r = array(); foreach ( $visits as $i => $v ) { $r[] = array( 'value' => "C$i", 'views' => $v, 'visits' => $v ); } return $r; };
an_eq( 'Two markets are 71% of visits: little discovery beyond your core geography.', sn_annotation_geography( $geo( array( 31, 40, 15, 14 ) ) ), 'top-2 concentration -> read (rank by visits, unordered)' );
an_eq( null, sn_annotation_geography( $geo( array( 30, 25, 25, 20 ) ) ), 'visits spread across markets -> null' );
an_eq( null, sn_annotation_geography( $geo( array( 50, 30, 20 ) ) ), 'only three markets -> null (concentration is trivial)' );
an_eq( null, sn_annotation_geography( array() ), 'empty -> null' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
