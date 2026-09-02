<?php
/**
 * Signal & Noise — analytics signal engine (predictive tier).
 * Pure statistics over the existing durable rollups. Produces typed Signal[]
 * (spec §5.1); no LLM, no new AE queries. @package SignalNoiseTools @since 9.30.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SN_ANALYTICS_SIGNAL_BASELINE_DAYS = 30;
const SN_ANALYTICS_SIGNAL_FLOOR_DAYS    = 14;
const SN_ANALYTICS_SIGNAL_ANOMALY_Z     = 3.5;   // MAD-based robust z (~3σ for normal data); SN_ANALYTICS_ANOMALY_Z is taken by analytics-derived.php (2.0)
const SN_ANALYTICS_TRAJ_MIN_POINTS      = 14;
const SN_ANALYTICS_FORECAST_HORIZON      = 7;    // days ahead (point at horizon; interval widens with √h)
const SN_ANALYTICS_FORECAST_MIN_POINTS   = 21;   // min history; below → suppressed (never fake precision)
const SN_ANALYTICS_FORECAST_HISTORY_DAYS = 30;   // trailing fit window ending $to (decoupled from display)
const SN_ANALYTICS_FORECAST_Z            = 1.96; // ~95% nominal interval; the backtest MEASURES real coverage
/**
 * Persistence-baseline floor, RELATIVE to the series level, below which skill is
 * undefined rather than negative.
 *
 * v13.75.0 guarded `mae_naive > 0`, which catches a PERFECTLY rigid series and
 * misses a NEARLY rigid one. Measured on live crawler data 2026-09-02: the
 * `uptime` family sits at median 480 with MAD 0 — a handful of days differ just
 * enough that mae_naive is small-but-nonzero, so skill blew up to -6.89 and the
 * forecast was withheld for a series that is trivially forecastable.
 *
 * "Is the denominator exactly zero" is not the question. "Is the denominator
 * large enough for the ratio to mean anything" is. When persistence already
 * tracks a series to within 1% of its own level there is nothing left to beat,
 * and the comparison says nothing about the model.
 */
const SN_ANALYTICS_FORECAST_SKILL_MIN_REL = 0.01;

/**
 * Owner-tunable engine options (settings hub, v9.36.0): builds the $opts array
 * the signal producers accept, from the two Measurement → Analytics knobs, then
 * lets code override via the 'sn_analytics_signal_config' filter (the
 * sessions-module pattern). Values are re-clamped AFTER the filter so a bad
 * override can't poison the math. Falls back to the file's own defaults when
 * sn_setting()/apply_filters() are absent (isolated CLI harnesses).
 *
 * Preset map (no new top-level constants — the duplicate-const class):
 * relaxed → 2.5σ, standard → SN_ANALYTICS_SIGNAL_ANOMALY_Z (3.5), strict → 4.5σ.
 *
 * Only baseline_days and z pass through — other filter-supplied keys (e.g.
 * floor) are intentionally dropped; engine defaults apply.
 *
 * @since 9.36.0
 * @return array{baseline_days:int, z:float}
 */
function sn_analytics_signal_opts() {
	$baseline = SN_ANALYTICS_SIGNAL_BASELINE_DAYS;
	$preset   = 'standard';
	if ( function_exists( 'sn_setting' ) ) {
		$baseline = (int) sn_setting( 'analytics.signal_baseline_days', $baseline );
		$preset   = (string) sn_setting( 'analytics.anomaly_sensitivity', $preset );
	}
	$z_map = array( 'relaxed' => 2.5, 'standard' => SN_ANALYTICS_SIGNAL_ANOMALY_Z, 'strict' => 4.5 );
	$cfg   = array(
		'baseline_days' => $baseline,
		'z'             => $z_map[ $preset ] ?? $z_map['standard'],
	);
	$out = function_exists( 'apply_filters' ) ? (array) apply_filters( 'sn_analytics_signal_config', $cfg ) : $cfg;
	return array(
		'baseline_days' => max( SN_ANALYTICS_SIGNAL_FLOOR_DAYS, min( 90, (int) ( $out['baseline_days'] ?? $cfg['baseline_days'] ) ) ),
		'z'             => max( 0.5, min( 10.0, (float) ( $out['z'] ?? $cfg['z'] ) ) ),
	);
}

/** Median of numeric values, or null when empty. */
function sn_analytics_stat_median( array $xs ) {
	$xs = array_values( array_filter( $xs, 'is_numeric' ) );
	sort( $xs, SORT_NUMERIC );
	$n = count( $xs );
	if ( 0 === $n ) { return null; }
	$mid = intdiv( $n, 2 );
	return ( $n % 2 ) ? (float) $xs[ $mid ] : ( (float) $xs[ $mid - 1 ] + (float) $xs[ $mid ] ) / 2.0;
}

/** Median absolute deviation (robust spread), or null when empty. */
function sn_analytics_stat_mad( array $xs, $median = null ) {
	$xs = array_values( array_filter( $xs, 'is_numeric' ) );
	if ( empty( $xs ) ) { return null; }
	if ( null === $median ) { $median = sn_analytics_stat_median( $xs ); }
	$dev = array_map( static function ( $x ) use ( $median ) { return abs( (float) $x - (float) $median ); }, $xs );
	return sn_analytics_stat_median( $dev );
}

/** Theil–Sen slope over index 0..n-1 (median of pairwise slopes), or null if n<2. */
function sn_analytics_stat_theil_sen( array $ys ) {
	$ys = array_values( $ys );
	$n  = count( $ys );
	if ( $n < 2 ) { return null; }
	$slopes = array();
	for ( $i = 0; $i < $n; $i++ ) {
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$slopes[] = ( (float) $ys[ $j ] - (float) $ys[ $i ] ) / ( $j - $i );
		}
	}
	return sn_analytics_stat_median( $slopes );
}

/**
 * Anomaly signals: robust median/MAD z on the daily views/visits series. Baseline
 * is the trailing SN_ANALYTICS_SIGNAL_BASELINE_DAYS ending $to (decoupled from the
 * display range); days within [$from,$to] are scored against it. Below the floor,
 * or on a flat (MAD=0) baseline, no signal is emitted (never fake precision).
 * @return array Signal[]
 */
function sn_analytics_signal_anomalies( $from, $to, $class = 'human', $opts = array() ) {
	if ( ! function_exists( 'sn_analytics_daily_series' ) ) { return array(); }
	$baseline_days = max( 1, (int) ( $opts['baseline_days'] ?? SN_ANALYTICS_SIGNAL_BASELINE_DAYS ) );
	$floor         = max( 1, (int) ( $opts['floor'] ?? SN_ANALYTICS_SIGNAL_FLOOR_DAYS ) );
	$z_thresh      = (float) ( $opts['z'] ?? SN_ANALYTICS_SIGNAL_ANOMALY_Z );
	$baseline_from = gmdate( 'Y-m-d', strtotime( (string) $to . ' -' . ( $baseline_days - 1 ) . ' days' ) );

	$series = sn_analytics_daily_series( $baseline_from, (string) $to, $class, 'day' );
	if ( ! is_array( $series ) || count( $series ) < $floor ) { return array(); }

	$out = array();
	foreach ( array( 'views', 'visits' ) as $metric ) {
		$vals   = array_map( static function ( $r ) use ( $metric ) { return (float) ( $r[ $metric ] ?? 0 ); }, $series );
		$median = sn_analytics_stat_median( $vals );
		$mad    = sn_analytics_stat_mad( $vals, $median );
		if ( null === $mad || $mad <= 0.0 ) { continue; } // flat baseline → no robust score
		$band = $z_thresh * $mad / 0.6745;
		foreach ( $series as $r ) {
			$day = (string) ( $r['day'] ?? '' );
			if ( $day < (string) $from ) { continue; }
			$v = (float) ( $r[ $metric ] ?? 0 );
			$z = 0.6745 * ( $v - $median ) / $mad;
			if ( abs( $z ) < $z_thresh ) { continue; }
			$conf = ( abs( $z ) >= $z_thresh + 1.5 ) ? 'high' : 'medium';
			$out[] = array(
				'id'            => 'anomaly:' . $metric . ':' . $day,
				'tier'          => 'predictive',
				'kind'          => 'anomaly',
				'subject'       => $metric,
				'subject_label' => ucfirst( $metric ),
				'stat'          => 'median_mad_z',
				'value'         => round( $z, 2 ),
				'direction'     => $z > 0 ? 'up' : 'down',
				'interval'      => array( 'low' => round( $median - $band, 1 ), 'high' => round( $median + $band, 1 ) ),
				'confidence'    => $conf,
				'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => $baseline_days ),
				'plain_label'   => sprintf( '%s ran %s the %d-day norm on %s (%.1fσ-robust)', ucfirst( $metric ), $z > 0 ? 'above' : 'below', $baseline_days, $day, abs( $z ) ),
				'severity'      => ( 'high' === $conf ) ? 3 : 2,
			);
		}
	}
	return $out;
}

/**
 * Anomaly signals from an INJECTED series — the series-taking sibling of
 * sn_analytics_signal_anomalies(), which queries the local analytics tables and
 * is coupled to $class and so cannot serve a caller that already holds a series.
 *
 * Added (v13.76.0) so all three tiers are uniformly injectable: trajectory_of
 * and forecast_of already took a series; this closes the set. The existing
 * composer is deliberately left ALONE — it is a locked contract with its own
 * two-metric loop, and refactoring it to share this code would put a shipped
 * surface at risk to save a dozen lines.
 *
 * Two-sided, like its sibling: a subject running BELOW its norm is as real a
 * finding as one running above. For crawler series it is the more interesting
 * one.
 *
 * @param string $subject Stable id fragment.
 * @param string $label   Human label.
 * @param array  $series  Rows carrying 'views' and 'day'.
 * @param string $from    Report anomalies on days >= this.
 * @param string $to      Window end (for the signal envelope).
 * @param array  $opts    z (threshold), floor (min points).
 * @return array Signal[] — empty when the baseline is flat (MAD 0) or too short.
 */
function sn_analytics_anomaly_of( $subject, $label, $series, $from, $to, $opts = array() ) {
	$floor    = max( 2, (int) ( $opts['floor'] ?? SN_ANALYTICS_SIGNAL_FLOOR_DAYS ) );
	$z_thresh = (float) ( $opts['z'] ?? SN_ANALYTICS_SIGNAL_ANOMALY_Z );
	$rows     = (array) $series;
	if ( count( $rows ) < $floor ) { return array(); }
	$vals   = array_map( static function ( $r ) { return (float) ( $r['views'] ?? 0 ); }, $rows );
	$median = sn_analytics_stat_median( $vals );
	$mad    = sn_analytics_stat_mad( $vals, $median );
	if ( null === $mad ) { return array(); }
	// MAD hits exactly 0 as soon as a strict MAJORITY of days repeat a value —
	// far easier than "every value identical", and real crawler traffic produces
	// bit-identical daily counts by the dozen. Measured live 2026-09-02: the
	// `uptime` family sits at median 480 with MAD 0, so it could NEVER fire. The
	// most rigid reader is the one whose deviation matters most, and it was the
	// one structurally excluded — a worse blind spot than the math it replaces.
	//
	// Fall back to the mean absolute deviation, sqrt(pi/2)-scaled: the same move
	// snt_ml_cadence_deviation_robust() makes, for the same reason. A PERFECTLY
	// rigid series still yields 0 and stays an honest unknown.
	//
	// Both branches produce a σ-EQUIVALENT, so z is (v - median) / sigma
	// throughout. 1.4826 is 1/0.6745 — the same constant the old form divided by,
	// written the way the kernel writes it.
	$sigma = ( $mad > 0.0 )
		? 1.4826 * $mad
		: sqrt( M_PI / 2.0 ) * ( array_sum( array_map( static function ( $v ) use ( $median ) { return abs( $v - $median ); }, $vals ) ) / max( 1, count( $vals ) ) );
	if ( $sigma <= 0.0 ) { return array(); }
	$band = $z_thresh * $sigma;
	$out  = array();
	foreach ( $rows as $r ) {
		$day = (string) ( $r['day'] ?? '' );
		if ( '' === $day || $day < (string) $from ) { continue; }
		$v = (float) ( $r['views'] ?? 0 );
		$z = ( $v - $median ) / $sigma;
		if ( abs( $z ) < $z_thresh ) { continue; }
		$conf  = ( abs( $z ) >= $z_thresh + 1.5 ) ? 'high' : 'medium';
		$out[] = array(
			'id'            => 'anomaly:' . $subject . ':' . $day,
			'tier'          => 'predictive',
			'kind'          => 'anomaly',
			'subject'       => (string) $subject,
			'subject_label' => (string) $label,
			'stat'          => 'median_mad_z',
			'value'         => round( $z, 2 ),
			'direction'     => $z > 0 ? 'up' : 'down',
			'interval'      => array( 'low' => round( $median - $band, 1 ), 'high' => round( $median + $band, 1 ) ),
			'confidence'    => $conf,
			'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $rows ) ),
			'plain_label'   => sprintf(
				'%s ran %s its %d-day norm on %s (%.1f\u{3c3}-robust, median %.0f)',
				(string) $label, $z > 0 ? 'above' : 'below', count( $rows ), $day, abs( $z ), $median
			),
			'severity'      => ( 'high' === $conf ) ? 3 : 2,
		);
	}
	return $out;
}

/** Classify one subject's daily-views series into a trajectory signal, or null if too short. */
function sn_analytics_trajectory_of( $subject, $label, $series, $from, $to, $min_points ) {
	$ys = array_map( static function ( $r ) { return (float) ( $r['views'] ?? 0 ); }, (array) $series );
	if ( count( $ys ) < $min_points ) { return null; }
	$slope = sn_analytics_stat_theil_sen( $ys );
	if ( null === $slope ) { return null; }
	$median = sn_analytics_stat_median( $ys );
	$rel    = ( $median > 0 ) ? ( $slope * count( $ys ) ) / $median : 0.0; // total change ÷ level
	if ( abs( $rel ) < 0.25 ) {
		$kind_t = 'flat'; $dir = 'flat'; $conf = 'medium'; $sev = 1;
	} else {
		$kind_t = $slope > 0 ? 'rising' : 'decaying';
		$dir    = $slope > 0 ? 'up' : 'down';
		$conf   = ( abs( $rel ) >= 0.6 ) ? 'high' : 'medium';
		$sev    = ( 'decaying' === $kind_t ) ? 2 : 1;
	}
	return array(
		'id'            => 'trajectory:' . $subject,
		'tier'          => 'predictive',
		'kind'          => 'trajectory',
		'subject'       => $subject,
		'subject_label' => (string) $label,
		'stat'          => 'theil_sen',
		'value'         => round( $slope, 3 ),
		'direction'     => $dir,
		'interval'      => null,
		'confidence'    => $conf,
		'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => 0 ),
		'plain_label'   => sprintf( '%s is %s (%+.0f%% over the window)', (string) $label, $kind_t, $rel * 100 ),
		'severity'      => $sev,
	);
}

/** Trajectory signals for top content paths + top campaigns. @return array Signal[] */
function sn_analytics_signal_trajectories( $from, $to, $class = 'human', $opts = array() ) {
	$min = max( 2, (int) ( $opts['min_points'] ?? SN_ANALYTICS_TRAJ_MIN_POINTS ) );
	$out = array();
	if ( function_exists( 'sn_analytics_top_paths' ) && function_exists( 'sn_analytics_path_daily_series' ) ) {
		foreach ( (array) sn_analytics_top_paths( (string) $from, (string) $to, $class, (int) ( $opts['paths'] ?? 5 ) ) as $p ) {
			$path = (string) ( $p['path'] ?? '' );
			if ( '' === $path ) { continue; }
			$sig = sn_analytics_trajectory_of( 'path:' . $path, $path, sn_analytics_path_daily_series( $path, (string) $from, (string) $to ), $from, $to, $min );
			if ( $sig ) { $out[] = $sig; }
		}
	}
	if ( function_exists( 'sn_analytics_top_utm_campaigns' ) && function_exists( 'sn_analytics_utm_series' ) ) {
		$camps  = sn_analytics_top_utm_campaigns( (string) $from, (string) $to, $class, (int) ( $opts['campaigns'] ?? 5 ) );
		$values = array_map( static function ( $r ) { return (string) $r['value']; }, (array) $camps );
		$cser   = sn_analytics_utm_series( 'campaign', $values, (string) $from, (string) $to, $class, 'day' );
		foreach ( $values as $v ) {
			$sig = sn_analytics_trajectory_of( 'campaign:' . $v, $v, $cser[ $v ] ?? array(), $from, $to, $min );
			if ( $sig ) { $out[] = $sig; }
		}
	}
	return $out;
}

/** All signals for the window, sorted by severity desc. @return array Signal[] */
function sn_analytics_signals( $from, $to, $class = 'human', $opts = array() ) {
	$signals = array_merge(
		sn_analytics_signal_anomalies( $from, $to, $class, $opts ),
		sn_analytics_signal_trajectories( $from, $to, $class, $opts ),
		sn_analytics_signal_forecasts( $from, $to, $class, $opts )
	);
	usort( $signals, static function ( $a, $b ) { return (int) $b['severity'] - (int) $a['severity']; } );
	return $signals;
}

/**
 * Holt linear (double exponential smoothing) fit with one-step-ahead residuals,
 * or null when the series is too short (<3). Fixed smoothing constants — the
 * honesty mechanism is the backtest (measured coverage), not parameter tuning.
 * @return array{level:float, trend:float, residuals:array<int,float>}|null
 */
function sn_analytics_stat_holt( array $ys, $alpha = 0.5, $beta = 0.3 ) {
	$ys = array_values( array_map( 'floatval', $ys ) );
	$n  = count( $ys );
	if ( $n < 3 ) { return null; }
	$level     = $ys[0];
	$trend     = $ys[1] - $ys[0];
	$residuals = array();
	for ( $t = 1; $t < $n; $t++ ) {
		$fitted      = $level + $trend;
		$residuals[] = $ys[ $t ] - $fitted;
		$prev_level  = $level;
		$level       = $alpha * $ys[ $t ] + ( 1 - $alpha ) * ( $level + $trend );
		$trend       = $beta * ( $level - $prev_level ) + ( 1 - $beta ) * $trend;
	}
	return array( 'level' => $level, 'trend' => $trend, 'residuals' => $residuals );
}

/** h-step-ahead Holt point forecast: level + h×trend. */
function sn_analytics_stat_holt_point( $fit, $h ) {
	return (float) $fit['level'] + (int) $h * (float) $fit['trend'];
}

/** Robust residual scale for intervals: 1.4826×MAD; RMSE fallback when MAD is 0. */
function sn_analytics_forecast_sigma( array $residuals ) {
	if ( empty( $residuals ) ) { return 0.0; }
	$mad = sn_analytics_stat_mad( $residuals );
	if ( null !== $mad && $mad > 0.0 ) { return 1.4826 * (float) $mad; }
	$sq = 0.0;
	foreach ( $residuals as $r ) { $sq += (float) $r * (float) $r; }
	return sqrt( $sq / count( $residuals ) );
}

/**
 * Rolling-origin backtest for the Holt forecaster: for every cutoff from
 * $min_train to n-1, fit on the prefix, forecast up to $horizon steps, and score
 * against the held-out actuals. Accuracy is MEASURED, never asserted (spec §8):
 * the returned coverage is the share of actuals inside the nominal interval and
 * drives the live signal's confidence + calibration note.
 * @return array{mae:float, coverage:float, checks:int}|null null when the series
 *         cannot support a single fold.
 */
function sn_analytics_forecast_backtest( array $ys, $horizon = SN_ANALYTICS_FORECAST_HORIZON, $min_train = 14 ) {
	$ys = array_values( array_map( 'floatval', $ys ) );
	$n  = count( $ys );
	if ( $n < $min_train + 1 ) { return null; }
	$abs_err   = array();
	$abs_naive = array();
	$inside    = 0;
	$checks    = 0;
	for ( $cut = $min_train; $cut < $n; $cut++ ) {
		$fit = sn_analytics_stat_holt( array_slice( $ys, 0, $cut ) );
		if ( null === $fit ) { continue; }
		$sigma = sn_analytics_forecast_sigma( $fit['residuals'] );
		$steps = min( $horizon, $n - $cut );
		// The naive (persistence) baseline scored on the SAME folds and the same
		// held-out actuals: predict the last observed value for every step. Any
		// other baseline, or any other fold set, makes the comparison meaningless.
		$last = $ys[ $cut - 1 ];
		for ( $h = 1; $h <= $steps; $h++ ) {
			$point       = sn_analytics_stat_holt_point( $fit, $h );
			$half        = SN_ANALYTICS_FORECAST_Z * $sigma * sqrt( $h );
			$actual      = $ys[ $cut + $h - 1 ];
			$abs_err[]   = abs( $actual - $point );
			$abs_naive[] = abs( $actual - $last );
			if ( $actual >= $point - $half && $actual <= $point + $half ) { $inside++; }
			$checks++;
		}
	}
	if ( 0 === $checks ) { return null; }
	// Explicit float casts: evenly-divisible int/int division returns int in PHP.
	$mae       = (float) ( array_sum( $abs_err ) / $checks );
	$mae_naive = (float) ( array_sum( $abs_naive ) / $checks );
	// SKILL (v13.75.0): 1 - mae/mae_naive. Positive means the model beat
	// persistence; <= 0 means it did not, and a forecast nobody should act on is
	// worse than an empty panel. NULL when the baseline is perfect (mae_naive 0,
	// a rigid series): the comparison is then undefined, NOT a failure — the same
	// position snt_ml_cadence_deviation_robust takes on a zero-spread history.
	// Undefined, not failure, when persistence is already essentially perfect —
	// either exactly (mae_naive 0) or relative to the series' own level.
	$level = (float) abs( (float) sn_analytics_stat_median( array_map( 'abs', $ys ) ) );
	$floor = SN_ANALYTICS_FORECAST_SKILL_MIN_REL * $level;
	$skill = ( $mae_naive > 0.0 && $mae_naive >= $floor )
		? (float) ( 1.0 - ( $mae / $mae_naive ) )
		: null;
	return array(
		'mae'       => $mae,
		'mae_naive' => $mae_naive,
		'skill'     => $skill,
		'coverage'  => (float) ( $inside / $checks ),
		'checks'    => $checks,
	);
}

/**
 * Compose one subject's daily-views series into a forecast Signal, or null when
 * suppressed. Honesty gates: below SN_ANALYTICS_FORECAST_MIN_POINTS → null; zero
 * median level → null; the ~95% interval is an approximation (residual scale ×√h),
 * so confidence comes from the backtest's MEASURED coverage and every plain_label
 * carries the calibration note. Displayed point + bounds clamp at 0 (views ≥ 0).
 * @return array|null Signal
 */
function sn_analytics_forecast_of( $subject, $label, $series, $from, $to, $opts = array() ) {
	$min     = max( 4, (int) ( $opts['min_points'] ?? SN_ANALYTICS_FORECAST_MIN_POINTS ) );
	$horizon = max( 1, (int) ( $opts['horizon'] ?? SN_ANALYTICS_FORECAST_HORIZON ) );
	$ys      = array_map( static function ( $r ) { return (float) ( $r['views'] ?? 0 ); }, (array) $series );
	if ( count( $ys ) < $min ) { return null; }
	$level = sn_analytics_stat_median( $ys );
	if ( null === $level || $level <= 0 ) { return null; }
	$fit = sn_analytics_stat_holt( $ys );
	if ( null === $fit ) { return null; }
	$backtest = sn_analytics_forecast_backtest( $ys, $horizon, max( 3, (int) floor( count( $ys ) / 2 ) ) );
	if ( null === $backtest ) { return null; }
	// SKILL GATE (v13.75.0). Until now the backtest measured MAE and compared it
	// to nothing, so the panel could report "average error 1.8/day" while being
	// worse than assuming tomorrow equals today. On a series of a few visits a
	// day that is the normal outcome, and a confident line drawn over it is the
	// least honest thing this engine does.
	//
	// Suppress WITH THE REASON rather than returning null: a bare null renders as
	// absence, and absence is indistinguishable from "no data yet". The withheld
	// signal keeps tier predictive so the tier still reports itself, carries no
	// direction (nothing is rising or falling) and confidence 'none'.
	//
	// null skill is NOT a failure — it means persistence was perfect, so the
	// comparison is undefined. A rigid series stays forecastable.
	$skill = $backtest['skill'] ?? null;
	if ( null !== $skill && $skill <= 0.0 ) {
		return array(
			'id'            => 'forecast-withheld:' . $subject . ':' . (string) $to . '+' . $horizon . 'd',
			'tier'          => 'predictive',
			'kind'          => 'forecast_withheld',
			'subject'       => $subject,
			'subject_label' => (string) $label,
			'stat'          => 'holt_linear',
			'value'         => null,
			'confidence'    => 'none',
			'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $ys ) ),
			'plain_label'   => sprintf(
				'%s: no forecast — the model does not beat a same-value baseline on this history (skill %.2f over %d checks)',
				(string) $label, $skill, (int) $backtest['checks']
			),
			'severity'      => 0,
		);
	}
	$sigma = sn_analytics_forecast_sigma( $fit['residuals'] );
	$point = sn_analytics_stat_holt_point( $fit, $horizon );
	$half  = SN_ANALYTICS_FORECAST_Z * $sigma * sqrt( $horizon );
	$shown = max( 0.0, $point );
	$low   = max( 0.0, $point - $half );
	$high  = max( 0.0, $point + $half );
	$move  = $horizon * (float) $fit['trend'];
	$rel   = $move / $level;
	$dir   = ( abs( $rel ) < 0.05 ) ? 'flat' : ( $move > 0 ? 'up' : 'down' );
	$cov   = (float) $backtest['coverage'];
	$conf  = ( $cov >= 0.8 ) ? 'high' : ( ( $cov >= 0.5 ) ? 'medium' : 'low' );
	return array(
		'id'            => 'forecast:' . $subject . ':' . (string) $to . '+' . $horizon . 'd',
		'tier'          => 'predictive',
		'kind'          => 'forecast',
		'subject'       => $subject,
		'subject_label' => (string) $label,
		'stat'          => 'holt_linear',
		'value'         => round( $shown, 1 ),
		'direction'     => $dir,
		'interval'      => array( 'low' => round( $low, 1 ), 'high' => round( $high, 1 ) ),
		'confidence'    => $conf,
		'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $ys ) ),
		'plain_label'   => sprintf(
			'%s: next %d days ≈ %.1f/day (interval %.1f–%.1f; backtest %d%% in-interval)',
			(string) $label, $horizon, $shown, $low, $high, (int) round( $cov * 100 )
		),
		'severity'      => ( 'down' === $dir ) ? 2 : 1,
	);
}

/**
 * Forecast signals for the window: site views+visits (trailing fit history ending
 * $to, decoupled from the display range), top campaigns, and the lifecycle
 * census's refresh candidates (spec §8 reuse — the census picks the subjects, the
 * existing per-path series supplies the data). Engagement metrics are deferred:
 * no per-day engagement series accessor exists yet, and the engine adds no new
 * queries. Every dependency is function_exists-guarded.
 * @return array Signal[]
 */
function sn_analytics_signal_forecasts( $from, $to, $class = 'human', $opts = array() ) {
	$out       = array();
	$history   = max( 1, (int) ( $opts['history_days'] ?? SN_ANALYTICS_FORECAST_HISTORY_DAYS ) );
	$hist_from = gmdate( 'Y-m-d', strtotime( (string) $to . ' -' . ( $history - 1 ) . ' days' ) );
	if ( function_exists( 'sn_analytics_daily_series' ) ) {
		$series = sn_analytics_daily_series( $hist_from, (string) $to, $class, 'day' );
		foreach ( array( 'views', 'visits' ) as $metric ) {
			$ms  = array_map( static function ( $r ) use ( $metric ) { return array( 'views' => (float) ( $r[ $metric ] ?? 0 ) ); }, (array) $series );
			$sig = sn_analytics_forecast_of( $metric, ucfirst( $metric ), $ms, $hist_from, $to, $opts );
			if ( $sig ) { $out[] = $sig; }
		}
	}
	if ( function_exists( 'sn_analytics_top_utm_campaigns' ) && function_exists( 'sn_analytics_utm_series' ) ) {
		$camps  = sn_analytics_top_utm_campaigns( (string) $from, (string) $to, $class, (int) ( $opts['campaigns'] ?? 3 ) );
		$values = array_map( static function ( $r ) { return (string) $r['value']; }, (array) $camps );
		$cser   = sn_analytics_utm_series( 'campaign', $values, $hist_from, (string) $to, $class, 'day' );
		foreach ( $values as $v ) {
			$sig = sn_analytics_forecast_of( 'campaign:' . $v, $v, $cser[ $v ] ?? array(), $hist_from, $to, $opts );
			if ( $sig ) { $out[] = $sig; }
		}
	}
	if ( function_exists( 'sn_analytics_posts_lifecycle' ) && function_exists( 'sn_analytics_path_daily_series' ) && function_exists( 'wp_parse_url' ) ) {
		$bundle = sn_analytics_posts_lifecycle();
		$rows   = is_array( $bundle ) ? (array) ( $bundle['rows'] ?? array() ) : array();
		$done   = 0;
		$cap    = (int) ( $opts['decay_paths'] ?? 3 );
		foreach ( $rows as $r ) {
			if ( empty( $r['refresh_candidate'] ) ) { continue; }
			$path = (string) wp_parse_url( (string) ( $r['permalink'] ?? '' ), PHP_URL_PATH );
			if ( '' === $path ) { continue; }
			$sig = sn_analytics_forecast_of( 'path:' . $path, $path, sn_analytics_path_daily_series( $path, $hist_from, (string) $to ), $hist_from, $to, $opts );
			if ( $sig ) {
				$out[] = $sig;
				if ( ++$done >= $cap ) { break; }
			}
		}
	}
	return $out;
}
