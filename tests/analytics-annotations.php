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
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
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
$sum = function ( $cooling, $sustained, $cands, $total ) {
	return array( 'counts' => array( 'spike' => 0, 'cooling' => $cooling, 'sustained' => $sustained, 'unknown' => 0 ), 'refresh_candidates' => $cands, 'total' => $total );
};
an_eq( '4 of 20 posts are cooling, and 3 are refresh candidates.', sn_annotation_lifecycle( $sum( 4, 10, 3, 20 ) ), 'refresh-candidate read' );
an_eq( 'Most of your catalogue holds: 15 of 20 posts have a sustained tail.', sn_annotation_lifecycle( $sum( 2, 15, 0, 20 ) ), 'sustained-majority read' );
an_eq( null, sn_annotation_lifecycle( $sum( 1, 3, 0, 6 ) ), 'thin catalogue -> null' );
an_eq( null, sn_annotation_lifecycle( $sum( 2, 5, 1, 12 ) ), 'no strong signal -> null' );

echo "\noverview\n";
$dv = function ( $pct, $dir ) { return array( 'views' => array( 'pct' => $pct, 'dir' => $dir ) ); };
an_eq( 'Views up 38%, but engaged rate slipped: more traffic, shallower reads.', sn_annotation_overview( $dv( 38, 'up' ), array( 'dir' => 'down' ) ), 'up-views / down-engagement divergence' );
an_eq( 'Views down 22%, but engaged rate rose: less traffic, but stickier reads.', sn_annotation_overview( $dv( 22, 'down' ), array( 'dir' => 'up' ) ), 'down-views / up-engagement divergence' );
// v9.64.1 honest vocabulary: the resolver's datum is VIEWS deltas — it holds no
// visit count, gated or otherwise, so the read must never claim "visits" moved.
an_true( false === stripos( (string) sn_annotation_overview( $dv( 38, 'up' ), array( 'dir' => 'down' ) ), 'visits' ), 'up-branch read never claims "visits" from views-only data' );
an_true( false === stripos( (string) sn_annotation_overview( $dv( 22, 'down' ), array( 'dir' => 'up' ) ), 'visits' ), 'down-branch read never claims "visits" from views-only data' );
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

echo "\nvisit quality\n";
// sn_session_metrics: { visits, bounce_rate, engaged_rate, ... }, engaged_rate a
// 0..1 fraction. No baseline exists on the panel, so absolute bands: the read
// speaks only on the two tails, and stays quiet on a typical middle range.
$vm = function ( $visits, $engaged ) { return array( 'visits' => $visits, 'engaged_rate' => $engaged, 'bounce_rate' => 0.4 ); };
an_eq( 'A high-quality range: 72% of visits were engaged reads.', sn_annotation_visit_quality( $vm( 100, 0.72 ) ), 'high engagement -> read' );
an_eq( 'A shallow range: only 18% of visits were engaged reads.', sn_annotation_visit_quality( $vm( 100, 0.18 ) ), 'low engagement -> read' );
an_eq( null, sn_annotation_visit_quality( $vm( 100, 0.45 ) ), 'typical middle range -> null' );
an_eq( null, sn_annotation_visit_quality( $vm( 10, 0.90 ) ), 'too few visits -> null' );
an_eq( null, sn_annotation_visit_quality( array() ), 'no metrics -> null' );

echo "\nconversions\n";
// sn_goal_attribution: [ { entry, conversions } ], conversions DESC. Names the
// entry page (a safe path, esc_html'd at render). Null with no/few conversions.
an_eq( 'Most contacts enter on /contact/: 80% of conversions land there first.', sn_annotation_conversions( array( array( 'entry' => '/contact/', 'conversions' => 8 ), array( 'entry' => '/services/', 'conversions' => 2 ) ) ), 'one entry dominates -> read' );
an_eq( null, sn_annotation_conversions( array( array( 'entry' => '/contact/', 'conversions' => 4 ), array( 'entry' => '/services/', 'conversions' => 3 ), array( 'entry' => '/about/', 'conversions' => 3 ) ) ), 'conversions spread -> null' );
an_eq( null, sn_annotation_conversions( array( array( 'entry' => '/contact/', 'conversions' => 2 ) ) ), 'too few conversions -> null' );
an_eq( null, sn_annotation_conversions( array() ), 'no conversions in range -> null' );

echo "\ndeploys (v9.81.0)\n";
// snt_deploy_history_get rows: { repo, ref, created_at ISO }. Pure resolver:
// releases whose created_at day falls inside [from, to] make the read.
$dep = function ( $repo, $ref, $day ) { return array( 'repo' => $repo, 'ref' => $ref, 'created_at' => $day . 'T12:00:00Z' ); };
an_eq(
	'1 release shipped in this range: signal-and-noise-tools v9.81.0.',
	sn_annotation_deploys( array( $dep( 'juanlentino/signal-and-noise-tools', 'v9.81.0', '2026-07-20' ) ), '2026-07-15', '2026-07-22' ),
	'one in-range release -> singular read naming repo short-name + ref'
);
an_eq(
	'2 releases shipped in this range: signal-and-noise-tools v9.81.0, signal-and-noise v10.47.1.',
	sn_annotation_deploys(
		array(
			$dep( 'juanlentino/signal-and-noise-tools', 'v9.81.0', '2026-07-20' ),
			$dep( 'juanlentino/signal-and-noise', 'v10.47.1', '2026-07-21' ),
			$dep( 'juanlentino/signal-and-noise', 'v10.40.0', '2026-06-01' ),
		),
		'2026-07-15',
		'2026-07-22'
	),
	'only in-range releases count; out-of-range ones are silent'
);
an_eq( null, sn_annotation_deploys( array( $dep( 'a/b', 'v1', '2026-06-01' ) ), '2026-07-15', '2026-07-22' ), 'nothing shipped in range -> null (quiet)' );
an_eq( null, sn_annotation_deploys( array( $dep( 'a/b', 'v1', '2026-07-20' ) ), '', '' ), 'an unbounded/blank range -> null (the all range tells no deploy story)' );
an_eq( null, sn_annotation_deploys( array(), '2026-07-15', '2026-07-22' ), 'no history -> null' );
an_eq( null, sn_annotation_deploys( 'not-an-array', '2026-07-15', '2026-07-22' ), 'garbage history -> null, no notice' );
$many = array();
foreach ( array( 'v1', 'v2', 'v3', 'v4', 'v5' ) as $i => $ref ) { $many[] = $dep( 'o/repo', $ref, '2026-07-2' . $i ); }
$read = sn_annotation_deploys( $many, '2026-07-15', '2026-07-29' );
an_true( is_string( $read ) && false !== strpos( $read, '5 releases' ) && false !== strpos( $read, 'and 2 more' ), 'more than 3 releases name 3 and fold the rest into "and N more"' );
an_true(
	1 === substr_count( (string) sn_annotation_deploys( array( $dep( 'o/r', 'v1', '2026-07-20' ), $dep( 'o/r', 'v1', '2026-07-20' ) ), '2026-07-15', '2026-07-22' ), 'r v1' ),
	'duplicate (repo, ref) rows dedupe to one named release'
);

echo "\nmaturity migration (v10.14.0)\n";
// Path-keyed rollups split at the 2026-07-30 /maturity/ re-parenting: the read
// fires only when the range spans that day AND an affected path (old top-level
// or new /maturity/ child) is among the rows the panel already fetched.
$mig_line = 'Maturity pages moved under /maturity/ on Jul 30, 2026: history before that date lives on the old top-level paths, after it on the new ones.';
$mig_rows = function ( array $paths ) { $r = array(); foreach ( $paths as $p ) { $r[] = array( 'path' => $p, 'views' => 10 ); } return $r; };
an_eq( $mig_line, sn_annotation_maturity_migration( $mig_rows( array( '/analytics/', '/notes/' ) ), '2026-07-01', '2026-08-15' ), 'old top-level path + spanning range -> read' );
an_eq( $mig_line, sn_annotation_maturity_migration( $mig_rows( array( '/maturity/proof-of-origin/' ) ), '2026-07-01', '2026-08-15' ), 'new /maturity/ child path -> read' );
an_eq( $mig_line, sn_annotation_maturity_migration( $mig_rows( array( '/maturity/' ) ), '2026-07-01', '2026-08-15' ), 'the /maturity/ hub itself -> read' );
an_eq( $mig_line, sn_annotation_maturity_migration( $mig_rows( array( '/a11y-maturity' ) ), '2026-07-01', '2026-08-15' ), 'trailing-slash-less row path still matches (normalization)' );
an_eq( $mig_line, sn_annotation_maturity_migration( $mig_rows( array( '/ops-maturity/' ) ), '', '' ), 'blank/unbounded range (all) spans the cliff -> read' );
an_eq( $mig_line, sn_annotation_maturity_migration( $mig_rows( array( '/machine-readability/' ) ), '2026-07-30', '2026-07-30' ), 'range that is exactly the migration day -> read' );
an_eq( null, sn_annotation_maturity_migration( $mig_rows( array( '/analytics/' ) ), '2026-06-01', '2026-07-29' ), 'range entirely before the migration day -> null' );
an_eq( null, sn_annotation_maturity_migration( $mig_rows( array( '/maturity/analytics/' ) ), '2026-07-31', '2026-08-15' ), 'range entirely after the migration day -> null' );
an_eq( null, sn_annotation_maturity_migration( $mig_rows( array( '/notes/', '/contact/' ) ), '2026-07-01', '2026-08-15' ), 'no affected path in the rows -> null' );
an_eq( null, sn_annotation_maturity_migration( $mig_rows( array( '/maturity/something-else/' ) ), '2026-07-01', '2026-08-15' ), 'an unmapped /maturity/ deep path -> null (exact list only)' );
an_eq( null, sn_annotation_maturity_migration( array(), '2026-07-01', '2026-08-15' ), 'empty rows -> null' );
an_eq( null, sn_annotation_maturity_migration( 'not-an-array', '2026-07-01', '2026-08-15' ), 'garbage rows -> null, no notice' );
// Movers rows carry { path, views, delta } — the same resolver serves that panel.
an_eq( $mig_line, sn_annotation_maturity_migration( array( array( 'path' => '/proof-of-origin/', 'views' => 5, 'delta' => -40 ) ), '2026-07-15', '2026-08-05' ), 'movers-shaped rows -> read (shared resolver)' );

// The movers COMPARE-WINDOW case (review finding on PR #409): a post-migration
// display range (Aug 1-14) stays silent by itself, but its prior compare window
// (Jul 18-31) straddles the cliff and MUST produce the read — the movers call
// site composes exactly these two calls (display ?? resolved-compare-window),
// so this pair pins the union logic's both halves against the pure resolver.
$mig_dropout = array( array( 'path' => '/analytics/', 'views' => 0, 'delta' => -120 ) ); // dropped-out prior-window path, views 0
an_eq( null, sn_annotation_maturity_migration( $mig_dropout, '2026-08-01', '2026-08-14' ), 'movers display window after the cliff alone -> null (the silent half)' );
an_eq( $mig_line, sn_annotation_maturity_migration( $mig_dropout, '2026-07-18', '2026-07-31' ), 'the resolved prior compare window straddles the cliff -> read (the rescuing half)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
