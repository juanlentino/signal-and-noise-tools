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
$GLOBALS['__lifecycle'] = null;
function sn_analytics_posts_lifecycle( $limit = 400 ) { return $GLOBALS['__lifecycle']; }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); } }

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

echo "\nGroup: trajectory + aggregate\n";
// Rising path: 14 points climbing 5→31.
$rising = array(); for ( $i = 0; $i < 14; $i++ ) { $rising[] = array( 'day' => sprintf( '2026-06-%02d', $i + 1 ), 'views' => 5 + 2 * $i ); }
$GLOBALS['__paths'] = array( array( 'path' => '/notes/x', 'views' => 200, 'visits' => 120 ) );
$GLOBALS['__pathseries'] = array( '/notes/x' => $rising );
$GLOBALS['__camps'] = array();
$traj = sn_analytics_signal_trajectories( '2026-06-01', '2026-06-14', 'human' );
ok( count( $traj ) === 1 && 'up' === $traj[0]['direction'] && false !== strpos( $traj[0]['plain_label'], 'rising' ), 'trajectory: rising path classified up' );
ok( 'trajectory' === $traj[0]['kind'] && 'theil_sen' === $traj[0]['stat'], 'trajectory: kind/stat set' );
// Short series → skipped.
$GLOBALS['__pathseries'] = array( '/notes/x' => array_slice( $rising, 0, 5 ) );
ok( array() === sn_analytics_signal_trajectories( '2026-06-01', '2026-06-14', 'human' ), 'trajectory: <14 points → skipped' );
// Aggregate producer merges + sorts by severity desc (anomaly high=3 before trajectory=1).
$GLOBALS['__daily'] = $series; // reuse the spike fixture from Task 2
$GLOBALS['__pathseries'] = array( '/notes/x' => $rising );
$all = sn_analytics_signals( '2026-06-14', '2026-06-20', 'human' );
ok( count( $all ) >= 2 && $all[0]['severity'] >= $all[ count( $all ) - 1 ]['severity'], 'aggregate: merged + sorted by severity desc' );
$GLOBALS['__daily'] = array(); $GLOBALS['__paths'] = array(); $GLOBALS['__pathseries'] = array();

echo "\nGroup: Holt fit\n";
// Perfect line y=2t: level tracks the last point, trend locks to 2, residuals vanish.
$hf = sn_analytics_stat_holt( array( 0, 2, 4, 6, 8 ) );
ok( is_array( $hf ) && abs( $hf['level'] - 8.0 ) < 1e-9 && abs( $hf['trend'] - 2.0 ) < 1e-9, 'holt: exact level+trend on a perfect line' );
ok( count( $hf['residuals'] ) === 4 && max( array_map( 'abs', $hf['residuals'] ) ) < 1e-9, 'holt: one-step residuals vanish on a perfect line' );
ok( abs( sn_analytics_stat_holt_point( $hf, 7 ) - 22.0 ) < 1e-9, 'holt: 7-step point continues the line (8 + 7×2)' );
ok( null === sn_analytics_stat_holt( array( 1, 2 ) ), 'holt: <3 points → null' );

echo "\nGroup: backtest harness\n";
// Perfect 25-point line: forecasts are exact, so MAE ~0 and every check lands inside.
$line25 = array(); for ( $i = 0; $i < 25; $i++ ) { $line25[] = 10 + 2 * $i; }
$bt = sn_analytics_forecast_backtest( $line25, 7, 14 );
ok( is_array( $bt ) && $bt['mae'] < 1e-9, 'backtest: perfect line → MAE ~0' );
ok( 1.0 === $bt['coverage'], 'backtest: perfect line → 100% interval coverage' );
ok( 56 === $bt['checks'], 'backtest: 11 rolling folds × capped horizon = 56 checks' );
ok( null === sn_analytics_forecast_backtest( array( 1, 2, 3 ), 7, 14 ), 'backtest: too short for one fold → null (insufficient)' );
// Deterministic noise ±2 around a slope-2 line: intervals must absorb the noise.
$noisy = array(); for ( $i = 0; $i < 30; $i++ ) { $noisy[] = 10 + 2 * $i + ( ( $i % 2 ) ? 2 : -2 ); }
$btn = sn_analytics_forecast_backtest( $noisy, 7, 14 );
ok( is_array( $btn ) && $btn['coverage'] >= 0.8 && 91 === $btn['checks'], 'backtest: noisy line → high measured coverage over 91 checks' );
ok( $btn['mae'] < 4.0, 'backtest: noisy line → MAE bounded by the noise scale' );

echo "\nGroup: forecast signal composer\n";
$noisy_rows = array_map( static function ( $v ) { return array( 'views' => $v ); }, $noisy );
$fs = sn_analytics_forecast_of( 'views', 'Views', $noisy_rows, '2026-06-13', '2026-07-12' );
ok( is_array( $fs ) && 'forecast' === $fs['kind'] && 'holt_linear' === $fs['stat'] && 'predictive' === $fs['tier'] && 'forecast:views:2026-07-12+7d' === $fs['id'], 'forecast: kind/stat/tier/id shaped per §5.1' );
ok( 'up' === $fs['direction'] && 'high' === $fs['confidence'], 'forecast: rising fixture → up, backtest-calibrated high confidence' );
ok( is_array( $fs['interval'] ) && $fs['interval']['low'] < $fs['value'] && $fs['value'] < $fs['interval']['high'], 'forecast: ALWAYS carries an interval that brackets the point' );
ok( false !== strpos( $fs['plain_label'], 'backtest' ) && false !== strpos( $fs['plain_label'], 'in-interval' ), 'forecast: plain_label carries the calibration note' );
$flat_rows = array_fill( 0, 30, array( 'views' => 50 ) );
$ff = sn_analytics_forecast_of( 'visits', 'Visits', $flat_rows, '2026-06-13', '2026-07-12' );
ok( is_array( $ff ) && 'flat' === $ff['direction'] && 50.0 === $ff['value'], 'forecast: flat series → flat direction, level point' );
$dec_rows = array(); for ( $i = 0; $i < 30; $i++ ) { $dec_rows[] = array( 'views' => 70 - 2 * $i + ( ( $i % 2 ) ? 1 : -1 ) ); }
$fd = sn_analytics_forecast_of( 'path:/notes/x', '/notes/x', $dec_rows, '2026-06-13', '2026-07-12' );
ok( is_array( $fd ) && 'down' === $fd['direction'] && 2 === $fd['severity'], 'forecast: decaying fixture → down, severity 2' );
ok( 0.0 === $fd['value'] && 0.0 === $fd['interval']['low'], 'forecast: sub-zero projection clamps to 0 (views cannot go negative)' );
ok( null === sn_analytics_forecast_of( 'visits', 'Visits', array_fill( 0, 30, array( 'views' => 0 ) ), '2026-06-13', '2026-07-12' ), 'forecast: all-zero series → suppressed (nothing to forecast honestly)' );
ok( null === sn_analytics_forecast_of( 'views', 'Views', array_slice( $noisy_rows, 0, 10 ), '2026-07-03', '2026-07-12' ), 'forecast: below min-sample floor → suppressed' );

echo "\nGroup: forecast producer\n";
$daily30 = array(); for ( $i = 0; $i < 30; $i++ ) { $daily30[] = array( 'day' => sprintf( '2026-06-%02d', $i + 1 ), 'views' => $noisy[ $i ], 'visits' => 50 ); }
$GLOBALS['__daily'] = $daily30;
$GLOBALS['__camps'] = array( array( 'value' => 'launch', 'views' => 900 ) );
$GLOBALS['__campseries'] = array( 'launch' => array_map( static function ( $v ) { return array( 'views' => $v ); }, $noisy ) );
$GLOBALS['__lifecycle'] = array( 'rows' => array(
	array( 'permalink' => 'https://juanlentino.com/notes/x', 'refresh_candidate' => true ),
	array( 'permalink' => 'https://juanlentino.com/notes/keep', 'refresh_candidate' => false ),
), 'summary' => array() );
$GLOBALS['__pathseries'] = array( '/notes/x' => $dec_rows );
$fc = sn_analytics_signal_forecasts( '2026-07-06', '2026-07-12', 'human' );
$fsub = array_map( static function ( $s ) { return $s['subject']; }, $fc );
ok( count( $fc ) === 4, 'producer: site views+visits, campaign, decay path → 4 forecasts' );
ok( in_array( 'views', $fsub, true ) && in_array( 'visits', $fsub, true ) && in_array( 'campaign:launch', $fsub, true ) && in_array( 'path:/notes/x', $fsub, true ), 'producer: all four subjects present' );
ok( ! in_array( 'path:/notes/keep', $fsub, true ), 'producer: non-candidate lifecycle rows skipped' );
$by = array(); foreach ( $fc as $s ) { $by[ $s['subject'] ] = $s; }
ok( 'up' === $by['views']['direction'] && 'flat' === $by['visits']['direction'] && 'down' === $by['path:/notes/x']['direction'] && 2 === $by['path:/notes/x']['severity'], 'producer: per-subject directions + decay severity' );
$GLOBALS['__campseries'] = array( 'launch' => array_map( static function ( $v ) { return array( 'views' => $v ); }, array_slice( $noisy, 0, 10 ) ) );
ok( count( sn_analytics_signal_forecasts( '2026-07-06', '2026-07-12', 'human' ) ) === 3, 'producer: short campaign history → that forecast suppressed' );
$GLOBALS['__daily'] = array(); $GLOBALS['__camps'] = array(); $GLOBALS['__campseries'] = array(); $GLOBALS['__lifecycle'] = null; $GLOBALS['__pathseries'] = array();

echo "\nGroup: aggregate includes forecasts\n";
// Re-arm the producer fixtures; the noisy series maxes out at robust z≈1.4, so the
// anomaly engine stays quiet and the aggregate exercises forecasts + sorting only.
$GLOBALS['__daily'] = $daily30;
$GLOBALS['__camps'] = array( array( 'value' => 'launch', 'views' => 900 ) );
$GLOBALS['__campseries'] = array( 'launch' => array_map( static function ( $v ) { return array( 'views' => $v ); }, $noisy ) );
$GLOBALS['__lifecycle'] = array( 'rows' => array( array( 'permalink' => 'https://juanlentino.com/notes/x', 'refresh_candidate' => true ) ), 'summary' => array() );
$GLOBALS['__pathseries'] = array( '/notes/x' => $dec_rows );
$agg = sn_analytics_signals( '2026-07-06', '2026-07-12', 'human' );
$fkinds = array_values( array_filter( $agg, static function ( $s ) { return 'forecast' === $s['kind']; } ) );
ok( count( $fkinds ) === 4, 'aggregate: sn_analytics_signals() now carries the 4 forecasts' );
ok( $agg[0]['severity'] >= $agg[ count( $agg ) - 1 ]['severity'] && 2 === $fkinds[0]['severity'], 'aggregate: still severity-sorted; the down-forecast leads the forecasts' );
$GLOBALS['__daily'] = array(); $GLOBALS['__camps'] = array(); $GLOBALS['__campseries'] = array(); $GLOBALS['__lifecycle'] = null; $GLOBALS['__pathseries'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
