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
// #1205 -- the sigma sat in a single-quoted sprintf format, so the label read "2.3\u{3c3}-robust" literally.
$of = sn_analytics_anomaly_of( 'uptime', 'uptime', $series, '2026-06-14', '2026-06-20' );
ok( 1 === count( $of ) && false !== strpos( $of[0]['plain_label'], "\u{3c3}-robust, median" ) && false === strpos( $of[0]['plain_label'], '\\u{' ), 'anomaly_of: the label prints the sigma character, not the escape (#1205)' );
ok( false !== strpos( sn_analytics_trajectory_magnitude( 1.5 ), "\u{d7} the typical level" ) && false === strpos( sn_analytics_trajectory_magnitude( 1.5 ), '\\u{' ), 'trajectory magnitude prints the times character, not the escape (#1205)' );
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

// ── v13.80.0: the trajectory envelope and its unit ──────────────────────────
// Both PRE-EXISTING on the human dashboard; reader-anomalies surfaced them by
// pointing the composer at a series with a small median.

// 1. baseline_days was hardcoded 0.
$sn_rise = array(); for ( $i = 0; $i < 30; $i++ ) { $sn_rise[] = array( 'views' => 10 + 2 * $i ); }
$sn_t = sn_analytics_trajectory_of( 'x', 'X', $sn_rise, '2026-08-03', '2026-09-01', 10 );
ok( 30 === $sn_t['window']['baseline_days'], 'the trajectory envelope reports the REAL baseline day count, not 0' );

// 2. The magnitude unit. rel = slope*n/median is a MULTIPLE of the level, so a
// percentage is only true below 100%. Live openai printed "decaying (-231%)".
ok( '+45%' === sn_analytics_trajectory_magnitude( 0.45 ), 'below 100% stays a percentage' );
ok( '-45%' === sn_analytics_trajectory_magnitude( -0.45 ), 'in both directions' );
ok( false !== strpos( sn_analytics_trajectory_magnitude( -2.31 ), 'typical level' ), 'above 100% becomes a MULTIPLE of the typical level' );
ok( false === strpos( sn_analytics_trajectory_magnitude( -2.31 ), '%' ), 'and drops the percent sign that implied a floor it does not have' );
ok( false !== strpos( sn_analytics_trajectory_magnitude( -2.31 ), '-2.3' ), 'the NUMBER is unchanged — only the unit was wrong' );

// The boundary is exactly 1.0, and both sides are pinned.
ok( false !== strpos( sn_analytics_trajectory_magnitude( 0.999 ), '%' ), 'just under the boundary is a percentage' );
ok( false === strpos( sn_analytics_trajectory_magnitude( 1.0 ), '%' ), 'exactly at it is a multiple' );

// CLASSIFICATION IS UNTOUCHED — the locked behaviour this change must not move.
$sn_fall = array(); for ( $i = 0; $i < 30; $i++ ) { $sn_fall[] = array( 'views' => max( 1, 60 - 2 * $i ) ); }
$sn_d = sn_analytics_trajectory_of( 'x', 'X', $sn_fall, '2026-08-03', '2026-09-01', 10 );
ok( 'down' === $sn_d['direction'] && false !== strpos( $sn_d['plain_label'], 'decaying' ), 'a falling series still classifies down/decaying' );
ok( 2 === $sn_d['severity'], 'and keeps its severity' );
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

echo "\nGroup: forecast point (2026-10-12: the trailing median replaced Holt)\n";
$ramp = array(); for ( $i = 1; $i <= 20; $i++ ) { $ramp[] = $i; }
ok( 13.5 === sn_analytics_forecast_point( $ramp ), 'point: the median of the last 14 days (7..20 → 13.5), not a projected trend' );
ok( null === sn_analytics_forecast_point( array() ), 'point: no history → null' );
ok( 1.0 === sn_analytics_stat_quantile( array( 5, 1, 3, 2, 4 ), 0.0 ) && 5.0 === sn_analytics_stat_quantile( array( 5, 1, 3, 2, 4 ), 1.0 ) && 3.0 === sn_analytics_stat_quantile( array( 5, 1, 3, 2, 4 ), 0.5 ), 'quantile: nearest rank on the sorted list' );
ok( null === sn_analytics_stat_quantile( array(), 0.5 ), 'quantile: empty → null' );
ok( 3.0 === sn_analytics_stat_quantile( range( 1, 20 ), 0.1 ), 'quantile: q=0.1 of 20 items is index round(1.9) = 2 (nearest rank, not floor)' );

echo "\nGroup: backtest harness\n";
// A stationary noisy series: the median beats "tomorrow equals today".
$stat30 = array(); for ( $i = 0; $i < 30; $i++ ) { $stat30[] = 20 + ( ( $i * 7 ) % 11 ) - 5; }
$bt = sn_analytics_forecast_backtest( $stat30, 7, 14 );
ok( is_array( $bt ) && $bt['skill'] > 0.2, 'backtest: on stationary noise the median beats persistence (skill ' . round( (float) $bt['skill'], 3 ) . ')' );
ok( abs( $bt['skill'] - ( 1.0 - $bt['mae'] / $bt['mae_naive'] ) ) < 1e-9, 'skill is exactly 1 - mae/mae_naive' );
ok( 91 === $bt['checks'] && count( $bt['errors'] ) === $bt['checks'], 'backtest: 16 rolling folds × capped horizon = 91 checks, each with its signed miss' );
ok( null !== $bt['coverage'] && $bt['band_checks'] > 0 && $bt['band_checks'] < $bt['checks'], 'backtest: band coverage is measured out of sample, only once enough misses are known' );
ok( null === sn_analytics_forecast_backtest( array( 1, 2, 3 ), 7, 14 ), 'backtest: too short for one fold → null (insufficient)' );
$short = sn_analytics_forecast_backtest( array_slice( $stat30, 0, 18 ), 7, 14 );
ok( is_array( $short ) && null === $short['coverage'] && 0 === $short['band_checks'], 'backtest: too few known misses → coverage NULL (unmeasured), never 0' );
// Out of sample: a jump the history never saw is outside a band built before it.
$jump = array_merge( array_fill( 0, 40, 10 ), array( 10, 11, 9, 10, 11, 9, 10, 11, 9, 10 ), array( 90, 90, 90 ) );
$bj   = sn_analytics_forecast_backtest( $jump, 7, 14 );
ok( null !== $bj['coverage'] && $bj['coverage'] < 1.0, 'backtest: the band never grades itself, so a jump it could not have seen is a miss (coverage ' . round( (float) $bj['coverage'], 3 ) . ')' );

// ── v13.75.0: forecast SKILL, and suppression that states its reason ────────
// skill = 1 - mae/mae_naive over the SAME folds and the same held-out actuals;
// <= 0 withholds the forecast. A median states a level, so a STEADY TREND is
// the case it loses to persistence: it lags the line.
$sn_lin  = array(); for ( $i = 0; $i < 22; $i++ ) { $sn_lin[] = 10 + 2 * $i; }
$sn_flat = array_fill( 0, 22, 7 );
$sn_series = static function ( $ys ) { return array_map( static function ( $v ) { return array( 'views' => $v ); }, $ys ); };

$sn_b = sn_analytics_forecast_backtest( $sn_lin, 7, 11 );
ok( isset( $sn_b['mae_naive'], $sn_b['skill'] ), 'the backtest reports a naive baseline and a skill score' );
// The baseline must be PERSISTENCE (last observed), not any other anchor. On
// this slope-2 line the persistence error at step h is exactly 2h, and the
// truncated tail folds pull the mean to exactly 7.0; a mean anchor gives 20.75.
ok( abs( $sn_b['mae_naive'] - 7.0 ) < 1e-9, 'the naive baseline is persistence: mae_naive is exactly 7.0 on a slope-2 line' );
ok( $sn_b['skill'] < 0.0, 'a steady trend scores negative skill: the median lags the line (' . round( $sn_b['skill'], 3 ) . ')' );

$sn_bf = sn_analytics_forecast_backtest( $sn_flat, 7, 11 );
ok( null === $sn_bf['skill'], 'a rigid series yields NULL skill: persistence is perfect, so the comparison is undefined' );

// The gate, end to end.
$sn_ok = sn_analytics_forecast_of( 'x', 'Steady', $sn_series( $stat30 ), '2026-08-01', '2026-08-30', array() );
ok( is_array( $sn_ok ) && 'forecast' === $sn_ok['kind'], 'a skillful series still forecasts' );

// A steady trend the median lags is where Holt earns its place: picked by its measured skill.
$sn_t = sn_analytics_forecast_of( 'x', 'Trend', $sn_series( $sn_lin ), '2026-08-01', '2026-08-30', array() );
ok( is_array( $sn_t ) && 'forecast' === $sn_t['kind'] && 'holt_linear' === $sn_t['stat'] && 'up' === $sn_t['direction'], 'a steady climb picks Holt (it beats the median by the margin) and states its direction' );
ok( false !== strpos( $sn_t['plain_label'], 'Holt trend' ), 'and the label names the model it used' );
// What a Holt pick shows is Holt's own: its point 7 days out, clamped at 0, its
// band from its own misses, its confidence from its own coverage.
$holt_shows = static function ( array $ys, $sig ) {
	$fit   = sn_analytics_stat_holt( $ys );
	$point = max( 0.0, $fit['level'] + 7 * $fit['trend'] );
	$bt    = sn_analytics_forecast_backtest( $ys, 7, min( SN_ANALYTICS_FORECAST_WINDOW, max( 3, (int) floor( count( $ys ) / 2 ) ) ), 'holt' );
	return round( $point, 1 ) === $sig['value']
		&& round( max( 0.0, $point + sn_analytics_stat_quantile( $bt['errors'], 0.1 ) ), 1 ) === $sig['interval']['low']
		&& round( max( 0.0, $point + sn_analytics_stat_quantile( $bt['errors'], 0.9 ) ), 1 ) === $sig['interval']['high']
		&& sn_analytics_forecast_confidence( $bt['coverage'], (int) $bt['band_checks'] ) === $sig['confidence'];
};
ok( $holt_shows( $sn_lin, $sn_t ), 'a Holt pick shows Holt\'s 7-day point, its own band and its own confidence' );
// The backtest clamps Holt at 0 as the live forecast does: a fall below zero scores against 0.
$fall = array(); for ( $i = 0; $i < 30; $i++ ) { $fall[] = max( 0, 60 - 3 * $i ); }
$fb   = sn_analytics_forecast_backtest( $fall, 7, 14, 'holt' );
$ref  = 0.0; $nref = 0;
for ( $c = 14; $c < 30; $c++ ) { $ft = sn_analytics_stat_holt( array_slice( $fall, 0, $c ) ); for ( $h = 1; $h <= min( 7, 30 - $c ); $h++ ) { $ref += abs( $fall[ $c + $h - 1 ] - max( 0.0, $ft['level'] + $h * $ft['trend'] ) ); $nref++; } }
ok( abs( $fb['mae'] - $ref / $nref ) < 1e-9, 'the Holt backtest scores the clamped point, as the live forecast shows it' );
// Holt about 0.13 ahead clears the 0.1 lead and is picked; a rigid series keeps the median.
$sn_ahead = array(); for ( $i = 0; $i < 40; $i++ ) { $sn_ahead[] = 50 + 0.4 * $i + ( ( ( $i * 7 ) % 5 ) - 2 ) * 2.5; }
$ah = sn_analytics_forecast_of( 'x', 'Ahead', $sn_series( $sn_ahead ), '2026-08-01', '2026-08-30', array() );
ok( 'holt_linear' === ( $ah['stat'] ?? '' ) && 'forecast' === $ah['kind'] && $holt_shows( $sn_ahead, $ah ), 'Holt 0.13 ahead of a median that does not beat the baseline: picked, and shown as Holt' );
$rg = sn_analytics_forecast_of( 'x', 'Rigid', $sn_series( array_fill( 0, 30, 12 ) ), '2026-08-01', '2026-08-30', array() );
ok( 'rolling_median' === ( $rg['stat'] ?? '' ), 'a rigid series (both skills undefined) keeps the median' );
// Holt by less than the margin is noise, not a win: the median stays (a
// margin of 0 let picking score below the median alone on the real crawler series).
$sn_near = array(); for ( $i = 0; $i < 40; $i++ ) { $sn_near[] = 50 + 0.3 * $i + ( ( ( $i * 7 ) % 5 ) - 2 ) * 2; }
$nm = sn_analytics_forecast_backtest( $sn_near, 7, 14, 'median' ); $nh = sn_analytics_forecast_backtest( $sn_near, 7, 14, 'holt' );
$sn_n = sn_analytics_forecast_of( 'x', 'Near', $sn_series( $sn_near ), '2026-08-01', '2026-08-30', array() );
ok( $nh['skill'] > $nm['skill'] && $nh['skill'] < $nm['skill'] + 0.1 && 'rolling_median' === ( $sn_n['stat'] ?? '' ), sprintf( 'Holt ahead by %.3f, under the 0.1 margin: the median stays', $nh['skill'] - $nm['skill'] ) );
// Neither model beats persistence on a trend that reverses: withheld, with the reason.
$sn_rev = array( 10,14,18,22,26,30,34,38,42,46,50,46,42,38,34,30,26,22,18,14,10,6 );
$sn_w = sn_analytics_forecast_of( 'x', 'Reversal', $sn_series( $sn_rev ), '2026-08-01', '2026-08-30', array() );
ok( is_array( $sn_w ), 'an unskillful series is SUPPRESSED WITH A REASON, not returned as null' );
ok( 'forecast_withheld' === $sn_w['kind'], 'the withheld signal has its own kind' );
ok( 'predictive' === $sn_w['tier'], 'it stays in the predictive tier — the tier reports itself either way' );
ok( null === $sn_w['value'] && 'none' === $sn_w['confidence'], 'no value, no confidence' );
ok( ! isset( $sn_w['direction'] ), 'and NO direction: nothing is rising or falling' );
ok( 0 === $sn_w['severity'], 'severity 0 so it never outranks a real finding' );
ok( false !== strpos( $sn_w['plain_label'], 'same-value baseline' ) && false !== strpos( $sn_w['plain_label'], 'median scores' ) && false !== strpos( $sn_w['plain_label'], 'Holt trend' ), 'the label states WHY, with both models\' scores' );
// PR #2016 review: Holt can beat the baseline and still not clear its lead.
// A real 30-day crawler window: median -0.03, Holt +0.06. Withheld under the
// margin, and the label must not say "neither" beats the baseline.
$real_w = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/forecast-real-series.json' ), true );
$sn_lag = sn_analytics_forecast_of( 'x', 'AI training', $sn_series( array_slice( $real_w['crawler_ai_training'], 20, 30 ) ), '2026-08-01', '2026-08-30', array() );
ok( 'forecast_withheld' === ( $sn_lag['kind'] ?? '' ) && false === strpos( $sn_lag['plain_label'], 'neither' ) && false !== strpos( $sn_lag['plain_label'], 'median scores -0.03 and a Holt trend 0.06' ) && false !== strpos( $sn_lag['plain_label'], 'a lead of 0.1' ), 'Holt beats the baseline but not the lead: withheld, and the label gives both scores and the rule' );
ok( 1 === preg_match( '/median scores -?\d+\.\d\d and a Holt trend -?\d+\.\d\d over \d+ checks/', $sn_w['plain_label'] ), 'and names both scores and the check count, so the refusal is checkable' );

// NULL skill must NOT suppress — undefined is not failure.
$sn_f = sn_analytics_forecast_of( 'x', 'Flat', $sn_series( $sn_flat ), '2026-08-01', '2026-08-30', array() );
ok( null === $sn_f || 'forecast' === $sn_f['kind'], 'a rigid series is not withheld on undefined skill' );

// ── v13.78.0: the skill DENOMINATOR needs a relative floor ──────────────────
$sn_hi = array(); $sn_lo = array();
for ( $i = 0; $i < 30; $i++ ) { $sn_hi[] = 480 + ( $i % 3 ); $sn_lo[] = 10 + ( $i % 3 ); }
$sn_bh = sn_analytics_forecast_backtest( $sn_hi, 7, 15 );
$sn_bl = sn_analytics_forecast_backtest( $sn_lo, 7, 15 );
ok( null === $sn_bh['skill'], 'a NEARLY rigid series yields undefined skill, not a huge negative' );
ok( abs( $sn_bh['mae_naive'] - $sn_bl['mae_naive'] ) < 1e-9, 'both series carry the SAME absolute naive error (' . round( $sn_bh['mae_naive'], 3 ) . ')' );
ok( null !== $sn_bl['skill'], 'yet at level 10 the same jitter IS a real comparison — the floor scales with the series' );
ok( null === $sn_bh['skill'] && null !== $sn_bl['skill'], 'so an ABSOLUTE floor could not have separated these two' );
ok( null === sn_analytics_forecast_backtest( array_fill( 0, 30, 480 ), 7, 15 )['skill'], 'a perfectly rigid series stays undefined' );
$sn_spread = array(); for ( $i = 0; $i < 30; $i++ ) { $sn_spread[] = 480 + ( $i % 7 ) * 5; }
ok( null !== sn_analytics_forecast_backtest( $sn_spread, 7, 15 )['skill'], 'a series with real spread still produces a skill score' );
$sn_sr = array_map( static function ( $v ) { return array( 'views' => $v ); }, $sn_hi );
$sn_fc = sn_analytics_forecast_of( 'x', 'Rigid', $sn_sr, '2026-08-01', '2026-08-30', array() );
ok( null === $sn_fc || 'forecast' === $sn_fc['kind'], 'undefined skill does not withhold the forecast' );

echo "\nGroup: the site's own series (the 2026-10-12 backtest, as a pin)\n";
// The swap was measured on this site's real daily traffic: Holt scored -0.13
// to -0.28 against persistence; the median +0.18 to +0.30. A regression to a
// worse model turns this red.
$real = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/forecast-real-series.json' ), true );
foreach ( array( 'human_views' => 0.25, 'human_visits' => 0.25, 'crawler_total' => 0.15, 'crawler_ai_training' => 0.15 ) as $name => $floor ) {
	$rb = sn_analytics_forecast_backtest( $real[ $name ], 7, 30 );
	ok( is_array( $rb ) && $rb['skill'] > $floor, sprintf( '%s: the forecaster beats persistence by %.3f (floor %.2f) over %d checks', $name, (float) $rb['skill'], $floor, (int) $rb['checks'] ) );
	ok( null !== $rb['coverage'] && $rb['coverage'] >= 0.5, sprintf( '%s: its 80%% band held on %.0f%% of out-of-sample checks', $name, 100 * (float) $rb['coverage'] ) );
	// 2026-10-12 review: exact values from an independent brute-force version;
	// an in-sample band (0.815) or a day of lookahead (798 checks) moves them.
	$want = array( 'human_views' => array( 791, 0.781290 ), 'crawler_ai_training' => array( 259, 0.509653 ) );
	if ( isset( $want[ $name ] ) ) {
		ok( $want[ $name ][0] === $rb['band_checks'] && abs( $rb['coverage'] - $want[ $name ][1] ) < 1e-6, sprintf( '%s: out-of-sample band, exactly %d checks at %.6f', $name, $want[ $name ][0], $want[ $name ][1] ) );
	}
	$hb = sn_analytics_forecast_backtest( $real[ $name ], 7, 30, 'holt' );
	ok( $hb['skill'] < $rb['skill'], sprintf( '%s: Holt alone scores %.3f here, below the median: the reason the median is the default', $name, (float) $hb['skill'] ) );
	$picked = sn_analytics_forecast_of( 'x', $name, array_map( static function ( $v ) { return array( 'views' => $v ); }, array_slice( $real[ $name ], -90 ) ), 'a', 'b' );
	ok( 'rolling_median' === ( $picked['stat'] ?? '' ) && ! isset( $picked['direction'] ), sprintf( '%s: the per-series pick is the median, with no direction', $name ) );
}

// Confidence against the band's own 80% target: a band that held 78% of 791
// checks is high; one that held 71% is medium (the old cutoffs, built for a
// 95% interval, made high unreachable: 63 of 63 real windows read medium).
$conf_of = static function ( $name ) use ( $real ) {
	return sn_analytics_forecast_of( 'x', $name, array_map( static function ( $v ) { return array( 'views' => $v ); }, $real[ $name ] ), 'a', 'b', array( 'min_points' => 21 ) );
};
ok( 'high' === sn_analytics_forecast_confidence( 0.781290, 791 ), 'a band that held 78% of 791 checks (real views) is high' );
ok( 'medium' === sn_analytics_forecast_confidence( 0.71, 791 ) && 'medium' === sn_analytics_forecast_confidence( 0.78, 30 ), '71% is medium, and so is 78% over only 30 checks' );
ok( 'low' === sn_analytics_forecast_confidence( null, 0 ) && 'low' === sn_analytics_forecast_confidence( 0.4, 500 ), 'unmeasured or under half is low' );

echo "\nGroup: forecast signal composer\n";
$stat_rows = array_map( static function ( $v ) { return array( 'views' => $v ); }, $stat30 );
$fs = sn_analytics_forecast_of( 'views', 'Views', $stat_rows, '2026-06-13', '2026-07-12' );
ok( is_array( $fs ) && 'forecast' === $fs['kind'] && 'rolling_median' === $fs['stat'] && 'predictive' === $fs['tier'] && 'forecast:views:2026-07-12+7d' === $fs['id'], 'forecast: kind/stat/tier/id shaped per §5.1' );
ok( ! array_key_exists( 'direction', $fs ) && 1 === $fs['severity'], 'forecast: a level, never a direction (the trend tier says which way)' );
ok( (float) sn_analytics_forecast_point( $stat30 ) === $fs['value'], 'forecast: the value is the trailing 14-day median' );
ok( is_array( $fs['interval'] ) && $fs['interval']['low'] <= $fs['value'] && $fs['value'] <= $fs['interval']['high'] && $fs['interval']['high'] > $fs['interval']['low'], 'forecast: ALWAYS carries a band of real width around the point' );
ok( false !== strpos( $fs['plain_label'], '80% band' ) && false !== strpos( $fs['plain_label'], 'held on' ) && false === strpos( $fs['plain_label'], '–' ), 'forecast: plain_label names the band and how often it held (and no en dash)' );
$f21 = sn_analytics_forecast_of( 'views', 'Views', array_slice( $stat_rows, 0, 21 ), '2026-06-13', '2026-07-03', array( 'horizon' => 1 ) );
ok( is_array( $f21 ) && 'low' === $f21['confidence'] && false !== strpos( $f21['plain_label'], 'band not yet measured' ), 'forecast: an unmeasured band says so and is low confidence, never "0% held"' );
$flat_rows = array_fill( 0, 30, array( 'views' => 50 ) );
$ff = sn_analytics_forecast_of( 'visits', 'Visits', $flat_rows, '2026-06-13', '2026-07-12' );
ok( is_array( $ff ) && 50.0 === $ff['value'], 'forecast: flat series → its level' );
// A fading series: past misses run below the current level, so the band's floor would be negative.
$drop_rows = array(); for ( $i = 0; $i < 90; $i++ ) { $drop_rows[] = array( 'views' => max( 0, 2 - 2 * $i / 120 + 2 * ( ( ( $i * 7 ) % 11 ) - 5 ) / 5 ) ); }
$fl = sn_analytics_forecast_of( 'path:/notes/x', '/notes/x', $drop_rows, '2026-04-14', '2026-07-12' );
ok( is_array( $fl ) && 'forecast' === $fl['kind'] && 0.0 === $fl['interval']['low'], 'forecast: a band below zero clamps to 0 (views cannot go negative)' );
ok( null === sn_analytics_forecast_of( 'visits', 'Visits', array_fill( 0, 30, array( 'views' => 0 ) ), '2026-06-13', '2026-07-12' ), 'forecast: all-zero series → suppressed (nothing to forecast honestly)' );
ok( null === sn_analytics_forecast_of( 'views', 'Views', array_slice( $stat_rows, 0, 10 ), '2026-07-03', '2026-07-12' ), 'forecast: below min-sample floor → suppressed' );
$dec_rows = array(); for ( $i = 0; $i < 30; $i++ ) { $dec_rows[] = array( 'views' => 70 - 2 * $i + ( ( $i % 2 ) ? 1 : -1 ) ); }

echo "\nGroup: forecast producer\n";
$daily30 = array(); for ( $i = 0; $i < 30; $i++ ) { $daily30[] = array( 'day' => sprintf( '2026-06-%02d', $i + 1 ), 'views' => $stat30[ $i ], 'visits' => 50 ); }
$GLOBALS['__daily'] = $daily30;
$GLOBALS['__camps'] = array( array( 'value' => 'launch', 'views' => 900 ) );
$GLOBALS['__campseries'] = array( 'launch' => array_map( static function ( $v ) { return array( 'views' => $v ); }, $stat30 ) );
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
ok( 'rolling_median' === $by['views']['stat'] && 'forecast' === $by['visits']['kind'] && 'holt_linear' === $by['path:/notes/x']['stat'] && 'down' === $by['path:/notes/x']['direction'] && 2 === $by['path:/notes/x']['severity'], 'producer: steady subjects take the median; the decaying path takes Holt, says down, and ranks first' );
$GLOBALS['__campseries'] = array( 'launch' => array_map( static function ( $v ) { return array( 'views' => $v ); }, array_slice( $stat30, 0, 10 ) ) );
ok( count( sn_analytics_signal_forecasts( '2026-07-06', '2026-07-12', 'human' ) ) === 3, 'producer: short campaign history → that forecast suppressed' );
$GLOBALS['__daily'] = array(); $GLOBALS['__camps'] = array(); $GLOBALS['__campseries'] = array(); $GLOBALS['__lifecycle'] = null; $GLOBALS['__pathseries'] = array();

echo "\nGroup: aggregate includes forecasts\n";
// Re-arm the producer fixtures; the noisy series maxes out at robust z≈1.4, so the
// anomaly engine stays quiet and the aggregate exercises forecasts + sorting only.
$GLOBALS['__daily'] = $daily30;
$GLOBALS['__camps'] = array( array( 'value' => 'launch', 'views' => 900 ) );
$GLOBALS['__campseries'] = array( 'launch' => array_map( static function ( $v ) { return array( 'views' => $v ); }, $stat30 ) );
$GLOBALS['__lifecycle'] = array( 'rows' => array( array( 'permalink' => 'https://juanlentino.com/notes/x', 'refresh_candidate' => true ) ), 'summary' => array() );
$GLOBALS['__pathseries'] = array( '/notes/x' => $dec_rows );
$agg = sn_analytics_signals( '2026-07-06', '2026-07-12', 'human' );
$fkinds = array_values( array_filter( $agg, static function ( $s ) { return in_array( $s['kind'], array( 'forecast', 'forecast_withheld' ), true ); } ) );
ok( count( $fkinds ) === 4, 'aggregate: sn_analytics_signals() carries the 4 forecast verdicts' );
ok( $agg[0]['severity'] >= $agg[ count( $agg ) - 1 ]['severity'] && 2 === $fkinds[0]['severity'], 'aggregate: still severity-sorted; the falling Holt forecast leads the forecasts' );
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
