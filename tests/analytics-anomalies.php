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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
