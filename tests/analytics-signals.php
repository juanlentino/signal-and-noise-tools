<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

// Data-accessor stubs (fixtures controlled per-test).
$GLOBALS['__daily'] = array();
function sn_analytics_daily_series( $from, $to, $class = 'human', $g = 'day' ) { return $GLOBALS['__daily']; }
$GLOBALS['__paths'] = array();
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__paths']; }
$GLOBALS['__pathseries'] = array();
function sn_analytics_path_daily_series( $path, $from, $to ) { return $GLOBALS['__pathseries'][ $path ] ?? array(); }
$GLOBALS['__camps'] = array();
function sn_analytics_top_utm_campaigns( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__camps']; }
$GLOBALS['__campseries'] = array();
function sn_analytics_utm_series( $mode, $vals, $from, $to, $class = 'human', $g = 'day' ) { return $GLOBALS['__campseries']; }

require __DIR__ . '/../inc/analytics-signals.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: statistical helpers\n";
ok( sn_analytics_stat_median( array( 3, 1, 2 ) ) === 2.0, 'median: odd' );
ok( sn_analytics_stat_median( array( 1, 2, 3, 4 ) ) === 2.5, 'median: even' );
ok( null === sn_analytics_stat_median( array() ), 'median: empty → null' );
ok( sn_analytics_stat_mad( array( 1, 1, 1, 1, 100 ) ) === 0.0, 'mad: robust to a lone outlier (median dev 0)' );
// A perfect line y = 2x: Theil–Sen slope = 2.
ok( sn_analytics_stat_theil_sen( array( 0, 2, 4, 6, 8 ) ) === 2.0, 'theil-sen: exact slope of a line' );
// One wild outlier must not move the slope much (median of pairwise slopes).
ok( abs( sn_analytics_stat_theil_sen( array( 0, 2, 4, 6, 999 ) ) - 2.0 ) < 0.001, 'theil-sen: outlier-resistant' );

echo "\nGroup: anomaly signals\n";
// 19-day baseline oscillating 9/11 around 10 (median 10, MAD 1 — real variance so
// the robust z is defined), with a spike to 60 on the last day (within display range).
$series = array();
for ( $i = 0; $i < 19; $i++ ) { $series[] = array( 'day' => sprintf( '2026-06-%02d', $i + 1 ), 'views' => ( $i % 2 ) ? 11 : 9, 'visits' => 8 ); }
$series[] = array( 'day' => '2026-06-20', 'views' => 60, 'visits' => 8 );
$GLOBALS['__daily'] = $series;
$sig = sn_analytics_signal_anomalies( '2026-06-14', '2026-06-20', 'human' );
$views_anom = array_values( array_filter( $sig, static function ( $s ) { return 'views' === $s['subject']; } ) );
ok( count( $views_anom ) === 1 && 'up' === $views_anom[0]['direction'], 'anomaly: catches the views spike, direction up' );
ok( 'predictive' === $views_anom[0]['tier'] && 'anomaly' === $views_anom[0]['kind'], 'anomaly: tier/kind set' );
ok( '' !== $views_anom[0]['plain_label'], 'anomaly: carries a plain_label' );
// Flat series → no anomaly (MAD 0 → skipped, no fake precision).
$flat = array(); for ( $i = 0; $i < 20; $i++ ) { $flat[] = array( 'day' => sprintf( '2026-06-%02d', $i + 1 ), 'views' => 10, 'visits' => 10 ); }
$GLOBALS['__daily'] = $flat;
ok( array() === sn_analytics_signal_anomalies( '2026-06-14', '2026-06-20', 'human' ), 'anomaly: flat baseline → none' );
// Short history (< 14 days) → insufficient → none.
$GLOBALS['__daily'] = array_slice( $flat, 0, 10 );
ok( array() === sn_analytics_signal_anomalies( '2026-06-14', '2026-06-20', 'human' ), 'anomaly: <14d baseline → insufficient, no flag' );
$GLOBALS['__daily'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
