<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

// Data-accessor stubs (fixtures controlled per-test). __daily_filter (default off,
// so every legacy fixture is untouched) makes the daily stub honor its window args
// like the real rollup reader — the opts-consumption group needs that so
// $opts['baseline_days'] → $baseline_from genuinely excludes older rows.
$GLOBALS['__daily'] = array();
$GLOBALS['__daily_filter'] = false;
function sn_analytics_daily_series( $from, $to, $class = 'human', $g = 'day' ) {
	if ( empty( $GLOBALS['__daily_filter'] ) ) { return $GLOBALS['__daily']; }
	return array_values( array_filter( $GLOBALS['__daily'], static function ( $r ) use ( $from, $to ) {
		$d = (string) ( $r['day'] ?? '' );
		return $d >= (string) $from && $d <= (string) $to;
	} ) );
}
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

// Production (signal-and-noise-tools.php) requires analytics-derived.php BEFORE
// analytics-signals.php. Load both in that order so cross-file constant collisions
// surface here instead of only on a live site — the harness-isolation gap that let
// v9.30.0 ship a duplicate SN_ANALYTICS_ANOMALY_Z (2.0 in derived, 3.5 here): the
// first definition won and the anomaly engine silently ran at 2.0σ.
$GLOBALS['__load_warnings'] = array();
set_error_handler( static function ( $errno, $errstr ) { $GLOBALS['__load_warnings'][] = $errstr; return true; }, E_WARNING | E_NOTICE | E_DEPRECATED );
require __DIR__ . '/../inc/analytics-derived.php';
require __DIR__ . '/../inc/analytics-signals.php';
restore_error_handler();
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
// ── v13.75.0: forecast SKILL, and suppression that states its reason ────────
// The backtest measured MAE and compared it to nothing, so the panel could
// report an average error while being worse than assuming tomorrow equals
// today. skill = 1 - mae/mae_naive over the SAME folds and the same held-out
// actuals; <= 0 withholds the forecast.
//
// Measured, not assumed: the gate does NOT fire on thin noisy traffic (Holt's
// smoothed level legitimately beats persistence on a stationary series). It
// fires on STRUCTURAL MISFIT — a trend that reverses, or a cycle Holt cannot
// represent. Naive is only a strong baseline for random-walk-shaped data.
$sn_lin  = array(); for ( $i = 0; $i < 22; $i++ ) { $sn_lin[] = 10 + 2 * $i; }
$sn_rev  = array( 10,14,18,22,26,30,34,38,42,46,50,46,42,38,34,30,26,22,18,14,10,6 );
$sn_flat = array_fill( 0, 22, 7 );
$sn_series = static function ( $ys ) { return array_map( static function ( $v ) { return array( 'views' => $v ); }, $ys ); };

$sn_b = sn_analytics_forecast_backtest( $sn_lin, 7, 11 );
ok( isset( $sn_b['mae_naive'], $sn_b['skill'] ), 'the backtest reports a naive baseline and a skill score' );
ok( abs( $sn_b['skill'] - ( 1.0 - $sn_b['mae'] / $sn_b['mae_naive'] ) ) < 1e-9, 'skill is exactly 1 - mae/mae_naive' );
ok( $sn_b['skill'] > 0.9, 'a clean linear trend scores near-perfect skill (' . round( $sn_b['skill'], 3 ) . ')' );
// The baseline must be PERSISTENCE (last observed), not any other anchor. Skill
// is meaningless otherwise, and every assertion above survives a swapped anchor
// — measured: changing it to the series mean left them all green. On this
// slope-2 line the persistence error at step h is exactly 2h, and the truncated
// tail folds pull the mean to exactly 7.0; a mean anchor gives 20.75.
ok( abs( $sn_b['mae_naive'] - 7.0 ) < 1e-9, 'the naive baseline is persistence: mae_naive is exactly 7.0 on a slope-2 line' );

$sn_br = sn_analytics_forecast_backtest( $sn_rev, 7, 11 );
ok( $sn_br['skill'] < 0.0, 'a trend that REVERSES scores negative skill — Holt extrapolates a dead trend (' . round( $sn_br['skill'], 3 ) . ')' );

$sn_bf = sn_analytics_forecast_backtest( $sn_flat, 7, 11 );
ok( null === $sn_bf['skill'], 'a rigid series yields NULL skill: persistence is perfect, so the comparison is undefined' );

// The gate, end to end.
$sn_ok = sn_analytics_forecast_of( 'x', 'Linear', $sn_series( $sn_lin ), '2026-08-01', '2026-08-30', array() );
ok( is_array( $sn_ok ) && 'forecast' === $sn_ok['kind'], 'a skillful series still forecasts' );

$sn_w = sn_analytics_forecast_of( 'x', 'Reversal', $sn_series( $sn_rev ), '2026-08-01', '2026-08-30', array() );
ok( is_array( $sn_w ), 'an unskillful series is SUPPRESSED WITH A REASON, not returned as null' );
ok( 'forecast_withheld' === $sn_w['kind'], 'the withheld signal has its own kind' );
ok( 'predictive' === $sn_w['tier'], 'it stays in the predictive tier — the tier reports itself either way' );
ok( null === $sn_w['value'] && 'none' === $sn_w['confidence'], 'no value, no confidence' );
ok( ! isset( $sn_w['direction'] ), 'and NO direction: nothing is rising or falling' );
ok( 0 === $sn_w['severity'], 'severity 0 so it never outranks a real finding' );
ok( false !== strpos( $sn_w['plain_label'], 'does not beat' ), 'the label states WHY' );
ok( false !== strpos( $sn_w['plain_label'], 'skill' ), 'and names the skill score, so the refusal is checkable' );

// NULL skill must NOT suppress — undefined is not failure.
$sn_f = sn_analytics_forecast_of( 'x', 'Flat', $sn_series( $sn_flat ), '2026-08-01', '2026-08-30', array() );
ok( null === $sn_f || 'forecast' === $sn_f['kind'], 'a rigid series is not withheld on undefined skill' );

// ── v13.78.0: the skill DENOMINATOR needs a relative floor ──────────────────
// v13.75.0 guarded mae_naive > 0, which catches a PERFECTLY rigid series and
// misses a NEARLY rigid one. Live crawler data 2026-09-02: the `uptime` family
// (median 480, MAD 0) produced skill -6.89 and had its forecast withheld — a
// series that is trivially forecastable. "Is the denominator exactly zero" is
// not the question; "is it large enough for the ratio to mean anything" is.
$sn_hi = array(); $sn_lo = array();
for ( $i = 0; $i < 30; $i++ ) { $sn_hi[] = 480 + ( $i % 3 ); $sn_lo[] = 10 + ( $i % 3 ); }
$sn_bh = sn_analytics_forecast_backtest( $sn_hi, 7, 15 );
$sn_bl = sn_analytics_forecast_backtest( $sn_lo, 7, 15 );

ok( null === $sn_bh['skill'], 'a NEARLY rigid series yields undefined skill, not a huge negative' );

// RELATIVITY is the property, and it is what an absolute floor would fail.
// Identical absolute jitter at two levels: noise at 480, real signal at 10.
ok( abs( $sn_bh['mae_naive'] - $sn_bl['mae_naive'] ) < 1e-9, 'both series carry the SAME absolute naive error (' . round( $sn_bh['mae_naive'], 3 ) . ')' );
ok( null !== $sn_bl['skill'], 'yet at level 10 the same jitter IS a real comparison — the floor scales with the series' );
ok( null === $sn_bh['skill'] && null !== $sn_bl['skill'], 'so an ABSOLUTE floor could not have separated these two' );

// Still undefined when persistence is exactly perfect.
ok( null === sn_analytics_forecast_backtest( array_fill( 0, 30, 480 ), 7, 15 )['skill'], 'a perfectly rigid series stays undefined' );

// And a series with real spread still gets a real verdict — the floor must not
// swallow the gate it was added to protect.
$sn_spread = array(); for ( $i = 0; $i < 30; $i++ ) { $sn_spread[] = 480 + ( $i % 7 ) * 5; }
ok( null !== sn_analytics_forecast_backtest( $sn_spread, 7, 15 )['skill'], 'a series with real spread still produces a skill score' );

// Undefined must NOT withhold: a rigid series is trivially forecastable.
$sn_sr = array_map( static function ( $v ) { return array( 'views' => $v ); }, $sn_hi );
$sn_fc = sn_analytics_forecast_of( 'x', 'Rigid', $sn_sr, '2026-08-01', '2026-08-30', array() );
ok( null === $sn_fc || 'forecast' === $sn_fc['kind'], 'undefined skill does not withhold the forecast' );

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

echo "\nGroup: display range never changes the stats (I5 invariant)\n";
// 30-day series ending 2026-07-12 with a spike on 2026-07-10 (inside both windows).
$inv = array(); for ( $i = 0; $i < 30; $i++ ) { $inv[] = array( 'day' => gmdate( 'Y-m-d', strtotime( '2026-06-13 UTC' ) + $i * 86400 ), 'views' => ( 27 === $i ) ? 60 : ( ( $i % 2 ) ? 11 : 9 ), 'visits' => 8 ); }
$GLOBALS['__daily'] = $inv;
$wide   = sn_analytics_signals( '2026-06-23', '2026-07-12', 'human' );
$narrow = sn_analytics_signals( '2026-07-08', '2026-07-12', 'human' );
$pick = static function ( $sigs, $kind ) { return array_values( array_filter( $sigs, static function ( $s ) use ( $kind ) { return $kind === $s['kind']; } ) ); };
$wa = $pick( $wide, 'anomaly' ); $na = $pick( $narrow, 'anomaly' );
ok( count( $wa ) >= 1 && count( $na ) >= 1 && $wa[0]['value'] === $na[0]['value'] && $wa[0]['interval'] === $na[0]['interval'], 'invariant: the spike scores identically in wide + narrow display windows (baseline is $to-anchored)' );
ok( json_encode( $pick( $wide, 'forecast' ) ) === json_encode( $pick( $narrow, 'forecast' ) ), 'invariant: forecasts identical across display ranges (history is $to-anchored)' );
$GLOBALS['__daily'] = array();

echo "\nGroup: loader-order parity (derived + signals together)\n";
$collisions = array_values( array_filter( $GLOBALS['__load_warnings'], static function ( $w ) { return false !== strpos( (string) $w, 'already defined' ); } ) );
ok( array() === $collisions, 'parity: no "already defined" constant warnings loading derived then signals' );
ok( defined( 'SN_ANALYTICS_SIGNAL_ANOMALY_Z' ) && 3.5 === constant( 'SN_ANALYTICS_SIGNAL_ANOMALY_Z' ), 'parity: the signal-engine threshold constant exists and is 3.5' );
ok( 2.0 === SN_ANALYTICS_ANOMALY_Z, 'parity: the legacy derived z constant is untouched at 2.0' );
// Behavioral pin: an outlier at robust z≈2.7 sits BETWEEN the legacy 2.0 cutoff and
// the engine's designed 3.5 — with both files loaded it must NOT flag. Under the
// duplicate-constant bug (effective threshold 2.0) it flagged. The 60-view spike in
// the anomaly group above (z≈33.7) already proves real anomalies still fire.
$mid = array();
for ( $i = 0; $i < 19; $i++ ) { $mid[] = array( 'day' => sprintf( '2026-06-%02d', $i + 1 ), 'views' => ( $i % 2 ) ? 11 : 9, 'visits' => 8 ); }
$mid[] = array( 'day' => '2026-06-20', 'views' => 14, 'visits' => 8 ); // z = 0.6745·(14−10)/1 ≈ 2.7
$GLOBALS['__daily'] = $mid;
ok( array() === sn_analytics_signal_anomalies( '2026-06-14', '2026-06-20', 'human' ), 'parity: z≈2.7 outlier stays quiet at the effective threshold (3.5, not 2.0)' );
$GLOBALS['__daily'] = array();

echo "\nGroup: engine consumes explicit \$opts (settings hub T2 armor)\n";
// A key-name typo/rename in the producer would leave the settings-hub knobs
// inert with every wiring suite still green — these assertions catch that by
// flipping OUTCOMES through the real engine with explicit $opts.
// (a) z: the parity group's z≈2.7 fixture stays quiet at the default 3.5 (pinned
// above); the SAME data must fire once the caller passes z 2.5.
$GLOBALS['__daily'] = $mid;
$zsig = sn_analytics_signal_anomalies( '2026-06-14', '2026-06-20', 'human', array( 'z' => 2.5 ) );
ok( 1 === count( $zsig ) && 'views' === $zsig[0]['subject'] && 'up' === $zsig[0]['direction'], 'opts: z 2.5 fires the z≈2.7 outlier the default 3.5 holds quiet' );
$GLOBALS['__daily'] = array();
// (b) baseline_days: window-honoring stub mode on. 16 wild days (2/18 oscillating)
// then 13 calm days (9/11) then a probe at 18, display window = the probe day only.
// 30-day baseline spans both regimes → median 10, MAD 8, probe z≈0.67 → quiet;
// baseline_days 14 excludes the wild regime → median 10, MAD 1, z≈5.4 → fires.
// Both regimes oscillate so MAD > 0 in every window (the I1 MAD-0 trap).
// Math prototyped + engine-verified in scratch before landing here.
$GLOBALS['__daily_filter'] = true;
$bl = array();
for ( $i = 0; $i < 16; $i++ ) { $bl[] = array( 'day' => sprintf( '2026-06-%02d', $i + 1 ), 'views' => ( $i % 2 ) ? 18 : 2, 'visits' => 8 ); }
for ( $i = 0; $i < 13; $i++ ) { $bl[] = array( 'day' => sprintf( '2026-06-%02d', $i + 17 ), 'views' => ( $i % 2 ) ? 11 : 9, 'visits' => 8 ); }
$bl[] = array( 'day' => '2026-06-30', 'views' => 18, 'visits' => 8 );
$GLOBALS['__daily'] = $bl;
ok( array() === sn_analytics_signal_anomalies( '2026-06-30', '2026-06-30', 'human' ), 'opts: default 30-day baseline absorbs the probe (wild old regime widens MAD)' );
$bsig = sn_analytics_signal_anomalies( '2026-06-30', '2026-06-30', 'human', array( 'baseline_days' => 14 ) );
ok( 1 === count( $bsig ) && 'up' === $bsig[0]['direction'], 'opts: baseline_days 14 excludes the old regime → the probe fires' );
ok( 14 === $bsig[0]['window']['baseline_days'], 'opts: the firing signal reports window.baseline_days = 14' );
$GLOBALS['__daily'] = array(); $GLOBALS['__daily_filter'] = false;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
