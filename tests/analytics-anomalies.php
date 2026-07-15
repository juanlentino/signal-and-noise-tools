<?php
/**
 * Tests for the anomaly-arc derived functions (A1 + A6 data layer).
 * Run: php tests/analytics-anomalies.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'SN_ANALYTICS_CLASSES' ) ) { define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) ); }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }

$GLOBALS['__paths']  = array();
$GLOBALS['__totals'] = array();
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__paths']; }
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__totals'][ "$from|$to" ] ?? array( 'views' => 0, 'visits' => 0, 'scroll_avg' => 0, 'time_avg' => 0 ); }

// S2 §4: controllable settings stub (tests/analytics-tuning-render.php idiom) so
// the mapping + behavior groups below can flip the owner preset per-assertion.
$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}

require_once __DIR__ . '/../inc/analytics-derived.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Anomaly-arc derived functions\n\n";

echo "Group: stat summary + z-score\n";
$s = sn_analytics_stat_summary( array( 2, 4, 4, 4, 5, 5, 7, 9 ) );
ok( abs( $s['mean'] - 5.0 ) < 1e-9, 'stat: mean of the sample is 5' );
ok( abs( $s['sd'] - 2.0 ) < 1e-9, 'stat: population sd of the sample is 2' );
ok( 8 === $s['n'], 'stat: n counts the sample' );
$e = sn_analytics_stat_summary( array() );
ok( 0 === $e['n'] && 0.0 === $e['sd'], 'stat: empty input → n=0, sd=0 (no divide-by-zero)' );
ok( abs( sn_analytics_zscore( 9, 5, 2 ) - 2.0 ) < 1e-9, 'zscore: (9-5)/2 = 2' );
ok( 0.0 === sn_analytics_zscore( 9, 5, 0 ), 'zscore: sd=0 → 0 (never divides by zero)' );

echo "\nGroup: engagement anomalies (per-path cross-metric)\n";
$GLOBALS['__paths'] = array(
	array( 'path' => '/skim',   'views' => 400, 'visits' => 380, 'scroll_avg' => 82.0, 'time_avg' => 2500.0 ),
	array( 'path' => '/stall',  'views' => 300, 'visits' => 260, 'scroll_avg' => 10.0, 'time_avg' => 45000.0 ),
	array( 'path' => '/normal', 'views' => 250, 'visits' => 200, 'scroll_avg' => 55.0, 'time_avg' => 60000.0 ),
	array( 'path' => '/normal2','views' => 240, 'visits' => 190, 'scroll_avg' => 52.0, 'time_avg' => 58000.0 ),
	array( 'path' => '/tiny',   'views' => 3,   'visits' => 3,   'scroll_avg' => 90.0, 'time_avg' => 1000.0 ),
);
$a = sn_analytics_engagement_anomalies( '2026-06-08', '2026-06-14', 'human' );
$div_paths = array_column( $a['divergence'], 'path' );
ok( in_array( '/skim', $div_paths, true ),  'anomalies: deep-scroll + fast-leave flagged as skim' );
ok( in_array( '/stall', $div_paths, true ), 'anomalies: long-dwell + low-scroll flagged as stall' );
ok( ! in_array( '/tiny', $div_paths, true ), 'anomalies: sub-threshold-views path ignored' );
$skim = null;
foreach ( $a['divergence'] as $d ) { if ( '/skim' === $d['path'] ) { $skim = $d; } }
ok( $skim && 'skim' === $skim['type'] && is_int( $skim['time_avg_ms'] ), 'anomalies: skim record carries type + int ms' );
ok( isset( $a['outliers'] ) && is_array( $a['outliers'] ), 'anomalies: outliers key present (z-score list)' );
ok( array( 'divergence', 'outliers' ) === array_keys( $a ), 'anomalies: stable top-level shape' );

// Outlier scenario: 5 flat rows + 1 far-out dwell → a genuine |z|>=2 flag.
// (Population sd caps |z| at sqrt(n-1); n=6 lets one outlier clear the 2.0 cutoff.)
$GLOBALS['__paths'] = array(
	array( 'path' => '/o1',   'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 20000.0 ),
	array( 'path' => '/o2',   'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 20000.0 ),
	array( 'path' => '/o3',   'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 20000.0 ),
	array( 'path' => '/o4',   'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 20000.0 ),
	array( 'path' => '/o5',   'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 20000.0 ),
	array( 'path' => '/obig', 'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 200000.0 ),
);
$o        = sn_analytics_engagement_anomalies( '2026-06-08', '2026-06-14', 'human' );
$time_out = array_values( array_filter( $o['outliers'], static function ( $x ) { return 'time_avg' === $x['metric']; } ) );
$scroll_out = array_filter( $o['outliers'], static function ( $x ) { return 'scroll_avg' === $x['metric']; } );
ok( 1 === count( $time_out ) && '/obig' === $time_out[0]['path'], 'anomalies: far-out dwell time is flagged as a z-score outlier' );
ok( $time_out && 'high' === $time_out[0]['dir'] && $time_out[0]['z'] >= 2.0, 'anomalies: outlier record carries dir=high and |z|>=2' );
ok( empty( $scroll_out ), 'anomalies: a flat metric (sd=0) yields no outliers' );

echo "\nGroup: baseline movers (aggregate week-over-week)\n";
// Uses the REAL sn_analytics_prior_window() (already loaded from analytics-derived.php);
// it steps back 7 days per call, so these fixture keys match the windows it generates.
$GLOBALS['__totals'] = array();
$GLOBALS['__totals']['2026-06-08|2026-06-14'] = array( 'views' => 1500, 'visits' => 300, 'scroll_avg' => 55, 'time_avg' => 40000 );
$prior = array( '2026-06-01|2026-06-07' => 1000, '2026-05-25|2026-05-31' => 1010, '2026-05-18|2026-05-24' => 990, '2026-05-11|2026-05-17' => 1005, '2026-05-04|2026-05-10' => 995, '2026-04-27|2026-05-03' => 1000 );
foreach ( $prior as $k => $v ) { $GLOBALS['__totals'][ $k ] = array( 'views' => $v, 'visits' => 200, 'scroll_avg' => 55, 'time_avg' => 40000 ); }
$flags     = sn_analytics_baseline_movers( '2026-06-08', '2026-06-14', 'human', 6 );
$by_metric = array_column( $flags, null, 'metric' );
ok( isset( $by_metric['views'] ), 'movers: the 1500-vs-~1000 views spike is flagged (>2 sd)' );
ok( ! isset( $by_metric['scroll_avg'] ), 'movers: a flat metric (constant scroll) is NOT flagged' );
$v = $by_metric['views'] ?? array();
ok( isset( $v['typical_low'], $v['typical_high'], $v['dir'] ) && 'above' === $v['dir'], 'movers: flag carries typical range + direction' );
ok( is_array( sn_analytics_baseline_movers( '2026-06-08', '2026-06-14', 'human', 6 ) ), 'movers: always returns an array' );

echo "\nGroup: sn_analytics_derived_z() sensitivity mapping (S2 §4)\n";
// Mirrors sn_analytics_signal_opts()'s preset→z map idiom (inc/analytics-signals.php)
// but at the per-page detector's own scale: relaxed 1.5 / standard 2.0 / strict 2.5.
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'relaxed' );
ok( 1.5 === sn_analytics_derived_z(), 'derived_z: relaxed → 1.5' );
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'standard' );
ok( 2.0 === sn_analytics_derived_z(), 'derived_z: standard → 2.0 (== SN_ANALYTICS_ANOMALY_Z, the const stays the single source)' );
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'strict' );
ok( 2.5 === sn_analytics_derived_z(), 'derived_z: strict → 2.5' );
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'garbage-preset' );
ok( 2.0 === sn_analytics_derived_z(), 'derived_z: junk preset falls back to 2.0 (the engine fallback)' );
$GLOBALS['__settings'] = array();
ok( 2.0 === sn_analytics_derived_z(), 'derived_z: absent setting (key unset) falls back to 2.0' );

echo "\nGroup: the preset governs the per-page detector (S2 §4 behavior pin)\n";
// A z≈1.732 deviation (3 flat rows + 1 offset row → population z = sqrt(3), scale-
// invariant regardless of the offset size) sits strictly between the relaxed (1.5)
// and standard (2.0) cutoffs — the exact gap this task closes. Under relaxed it
// must fire; under standard (byte-identical to the pre-S2 default) it must not.
// scroll_avg held flat across all 4 rows (sd=0) so only time_avg produces an
// outlier, and scroll/time stay far from the skim/stall divergence thresholds so
// this fixture is clean of that path.
$GLOBALS['__paths'] = array(
	array( 'path' => '/z1', 'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 20000.0 ),
	array( 'path' => '/z2', 'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 20000.0 ),
	array( 'path' => '/z3', 'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 20000.0 ),
	array( 'path' => '/zout', 'views' => 100, 'visits' => 90, 'scroll_avg' => 50.0, 'time_avg' => 50000.0 ),
);
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'relaxed' );
$ea_relaxed = sn_analytics_engagement_anomalies( '2026-06-08', '2026-06-14', 'human' );
$out_relaxed = array_values( array_filter( $ea_relaxed['outliers'], static function ( $x ) { return '/zout' === $x['path']; } ) );
ok( 1 === count( $out_relaxed ) && abs( $out_relaxed[0]['z'] - 1.73 ) < 0.01, 'engagement_anomalies: relaxed (1.5) flags the z≈1.73 outlier the standard default holds quiet' );
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'standard' );
$ea_standard = sn_analytics_engagement_anomalies( '2026-06-08', '2026-06-14', 'human' );
$out_standard = array_values( array_filter( $ea_standard['outliers'], static function ( $x ) { return '/zout' === $x['path']; } ) );
ok( 0 === count( $out_standard ), 'engagement_anomalies: standard (2.0, byte-identical default) stays quiet on the same z≈1.73 fixture' );
$GLOBALS['__paths']    = array();
$GLOBALS['__settings'] = array();

// Same behavior pin through the OTHER real read site (baseline_movers, :422):
// 3 trailing weeks [910, 1000, 1090] (population sd≈73.48) + a current week of
// 1130 → z≈1.769, again strictly between relaxed and standard.
$GLOBALS['__totals'] = array(
	'2026-06-08|2026-06-14' => array( 'views' => 1130, 'visits' => 200, 'scroll_avg' => 55, 'time_avg' => 40000 ),
	'2026-06-01|2026-06-07' => array( 'views' => 910,  'visits' => 200, 'scroll_avg' => 55, 'time_avg' => 40000 ),
	'2026-05-25|2026-05-31' => array( 'views' => 1000, 'visits' => 200, 'scroll_avg' => 55, 'time_avg' => 40000 ),
	'2026-05-18|2026-05-24' => array( 'views' => 1090, 'visits' => 200, 'scroll_avg' => 55, 'time_avg' => 40000 ),
);
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'relaxed' );
$bm_relaxed  = sn_analytics_baseline_movers( '2026-06-08', '2026-06-14', 'human', 3 );
$bm_relaxed_by = array_column( $bm_relaxed, null, 'metric' );
ok( isset( $bm_relaxed_by['views'] ) && abs( $bm_relaxed_by['views']['z'] - 1.77 ) < 0.01, 'baseline_movers: relaxed (1.5) flags the z≈1.77 views mover the standard default holds quiet' );
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'standard' );
$bm_standard = sn_analytics_baseline_movers( '2026-06-08', '2026-06-14', 'human', 3 );
$bm_standard_by = array_column( $bm_standard, null, 'metric' );
ok( ! isset( $bm_standard_by['views'] ), 'baseline_movers: standard (2.0, byte-identical default) stays quiet on the same z≈1.77 fixture' );
$GLOBALS['__totals']   = array();
$GLOBALS['__settings'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
