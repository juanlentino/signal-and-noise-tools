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
const SN_ANALYTICS_FORECAST_HORIZON      = 7;    // days ahead
const SN_ANALYTICS_FORECAST_MIN_POINTS   = 21;   // min history; below → suppressed (never fake precision)
const SN_ANALYTICS_FORECAST_HISTORY_DAYS = 90;   // trailing history ending $to the backtest and the band learn from (2026-10-12: was 30)
const SN_ANALYTICS_FORECAST_WINDOW       = 14;   // the forecast is the median of these trailing days (2026-10-12: replaced Holt)
const SN_ANALYTICS_FORECAST_BAND_Q       = 0.1;  // the band runs from this quantile of past misses to 1 - it: an 80% band
const SN_ANALYTICS_FORECAST_BAND_MIN     = 20;   // past misses needed before a band is measured out of sample
const SN_ANALYTICS_FORECAST_HOLT_MARGIN  = 0.1;  // Holt is used only when its backtest skill beats the median's by this much
const SN_ANALYTICS_FORECAST_HIGH_TOL     = 0.05; // 'high' confidence: the band held within this of its nominal 80%...
const SN_ANALYTICS_FORECAST_HIGH_CHECKS  = 50;   // ...over at least this many out-of-sample checks
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
				"%s ran %s its %d-day norm on %s (%.1f\u{3c3}-robust, median %.0f)",
				(string) $label, $z > 0 ? 'above' : 'below', count( $rows ), $day, abs( $z ), $median
			),
			'severity'      => ( 'high' === $conf ) ? 3 : 2,
		);
	}
	return $out;
}

/**
 * Render a trajectory magnitude in a unit that is TRUE of it.
 *
 * $rel is `slope * n / median` — total modelled change as a MULTIPLE of the
 * typical level, not a share of it. As a percentage that reads correctly below
 * 100% and becomes nonsense above: the live `openai` reader printed "decaying
 * (-231%)" on 2026-09-02, and a positive quantity cannot fall by more than 100%
 * of itself.
 *
 * Nothing about the NUMBER is wrong — only the unit implied a floor the
 * statistic does not have. Above +-100% it is stated as a multiple instead. The
 * classification (flat / rising / decaying), direction, confidence and severity
 * are all computed from $rel upstream and are deliberately untouched.
 *
 * @since 13.80.0
 * @param float $rel Total change over the window, divided by the median level.
 * @return string
 */
function sn_analytics_trajectory_magnitude( $rel ) {
	$rel = (float) $rel;
	if ( abs( $rel ) < 1.0 ) {
		return sprintf( '%+.0f%%', $rel * 100 );
	}
	return sprintf( "%+.1f\u{d7} the typical level", $rel );
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
		// baseline_days was hardcoded 0 (v13.80.0 fix): every trajectory signal
		// reported an empty baseline where the sibling forecast reports the real
		// count. Pre-existing on the human dashboard, surfaced by reader-anomalies.
		'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $ys ) ),
		'plain_label'   => sprintf( '%s is %s (%s over the window)', (string) $label, $kind_t, sn_analytics_trajectory_magnitude( $rel ) ),
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
 * The forecasters (2026-10-12): two models, and the backtest picks per series.
 *
 * The MEDIAN of the last SN_ANALYTICS_FORECAST_WINDOW days is the default. A
 * rolling-origin backtest on this site's real daily series (152 human days, 76
 * crawler days, 7 days ahead) scored Holt alone WORSE than "every day equals
 * the last" on every series (skill -0.13 to -0.28; its gate hid it on 79 to
 * 96% of days) and the median +0.18 to +0.30, level with TimesFM 2.5. Averaging
 * the two was worse than the median on every series (+0.07 to +0.16).
 *
 * HOLT (fixed alpha 0.5, beta 0.3) is kept for what a median cannot do: follow
 * a steady climb or fall (a synthetic climb: median -1.5, Holt +0.56). It is
 * used only when its backtest skill beats the median's by
 * SN_ANALYTICS_FORECAST_HOLT_MARGIN; with that margin, picking per series never
 * scored below the median alone on the real data. Only a Holt forecast states
 * a direction: a median states a level.
 *
 * @param array<int,float> $ys    The series, oldest first.
 * @param int              $h     Days ahead (Holt only; a median is flat).
 * @param string           $model 'median' or 'holt'.
 * @return float|null
 */
function sn_analytics_forecast_point( array $ys, $h = SN_ANALYTICS_FORECAST_HORIZON, $model = 'median' ) {
	$ys = array_values( array_map( 'floatval', $ys ) );
	if ( array() === $ys ) {
		return null;
	}
	if ( 'holt' === $model ) {
		$fit = sn_analytics_stat_holt( $ys );
		return null === $fit ? null : (float) ( $fit['level'] + (int) $h * $fit['trend'] );
	}
	return (float) sn_analytics_stat_median( array_slice( $ys, -SN_ANALYTICS_FORECAST_WINDOW ) );
}

/**
 * Holt linear (double exponential smoothing): the final level and trend, or
 * null under 3 points. Fixed smoothing constants: the honesty mechanism is the
 * backtest, not parameter tuning.
 *
 * @param array<int,float> $ys The series.
 * @return array{level:float,trend:float}|null
 */
function sn_analytics_stat_holt( array $ys, $alpha = 0.5, $beta = 0.3 ) {
	$ys = array_values( array_map( 'floatval', $ys ) );
	if ( count( $ys ) < 3 ) { return null; }
	$level = $ys[0];
	$trend = $ys[1] - $ys[0];
	for ( $t = 1, $n = count( $ys ); $t < $n; $t++ ) {
		$prev  = $level;
		$level = $alpha * $ys[ $t ] + ( 1 - $alpha ) * ( $level + $trend );
		$trend = $beta * ( $level - $prev ) + ( 1 - $beta ) * $trend;
	}
	return array( 'level' => $level, 'trend' => $trend );
}

/**
 * The q-quantile of a list (nearest rank on the sorted list), or null if empty.
 *
 * @param array<int,float> $xs Values.
 * @param float            $q  0..1.
 * @return float|null
 */
function sn_analytics_stat_quantile( array $xs, $q ) {
	if ( array() === $xs ) {
		return null;
	}
	$xs = array_map( 'floatval', $xs );
	sort( $xs );
	return (float) $xs[ (int) round( max( 0.0, min( 1.0, (float) $q ) ) * ( count( $xs ) - 1 ) ) ];
}

/**
 * Rolling-origin backtest for the forecaster: for every cutoff from $min_train
 * to n-1, forecast up to $horizon days from the data before it and score the
 * held-out actuals. Accuracy is MEASURED, never asserted (spec §8): skill is
 * 1 - MAE / MAE of the naive "every day equals the last" forecast on the same
 * folds, and coverage is how often the 80% band held, measured OUT OF SAMPLE:
 * each check is scored against a band built only from misses already known at
 * its cutoff, so the band never grades itself. `coverage` is null until
 * SN_ANALYTICS_FORECAST_BAND_MIN misses are known: unmeasured, never 0.
 *
 * @return array{mae:float,mae_naive:float,skill:float|null,coverage:float|null,band_checks:int,checks:int,errors:array<int,float>}|null
 *         null when the series cannot support a single fold.
 */
function sn_analytics_forecast_backtest( array $ys, $horizon = SN_ANALYTICS_FORECAST_HORIZON, $min_train = SN_ANALYTICS_FORECAST_WINDOW, $model = 'median' ) {
	$ys = array_values( array_map( 'floatval', $ys ) );
	$n  = count( $ys );
	if ( $n < $min_train + 1 ) { return null; }
	$misses = array(); // [ target index, origin, signed miss ]
	$abs_naive = array();
	for ( $cut = $min_train; $cut < $n; $cut++ ) {
		$hist = array_slice( $ys, 0, $cut );
		$last = $ys[ $cut - 1 ];
		// One fit per cutoff: the median is flat, Holt is level + h * trend.
		$fit  = 'holt' === $model ? sn_analytics_stat_holt( $hist ) : null;
		$med  = 'holt' === $model ? 0.0 : (float) sn_analytics_forecast_point( $hist );
		for ( $h = 1; $h <= min( $horizon, $n - $cut ); $h++ ) {
			// A forecast is never below zero (views cannot be), Holt's included.
			$point       = max( 0.0, null !== $fit ? (float) ( $fit['level'] + $h * $fit['trend'] ) : $med );
			$actual      = $ys[ $cut + $h - 1 ];
			$misses[]    = array( $cut + $h - 1, $cut, $actual - $point );
			$abs_naive[] = abs( $actual - $last );
		}
	}
	$checks = count( $misses );
	if ( 0 === $checks ) { return null; }
	$errors = array_column( $misses, 2 );
	// Out of sample: at each cutoff the band is built from the misses whose
	// day had already happened. Misses are added as their day passes and the
	// band is read once per cutoff (two sorts each: about 1.6 ms per 90-day
	// subject, 45 ms at 365 days; the history is capped at 90).
	$by_target = array();
	$by_origin = array();
	foreach ( $misses as $m ) {
		$by_target[ $m[0] ][] = $m[2];
		$by_origin[ $m[1] ][] = $m[2];
	}
	$known  = array();
	$next   = 0;
	$inside = 0;
	$scored = 0;
	foreach ( $by_origin as $cut => $at_cut ) {
		for ( ; $next < $cut; $next++ ) {
			foreach ( $by_target[ $next ] ?? array() as $e ) {
				$known[] = $e;
			}
		}
		if ( count( $known ) < SN_ANALYTICS_FORECAST_BAND_MIN ) { continue; }
		$lo = sn_analytics_stat_quantile( $known, SN_ANALYTICS_FORECAST_BAND_Q );
		$hi = sn_analytics_stat_quantile( $known, 1 - SN_ANALYTICS_FORECAST_BAND_Q );
		foreach ( $at_cut as $e ) {
			$inside += ( $e >= $lo && $e <= $hi ) ? 1 : 0;
			$scored++;
		}
	}
	// Explicit float casts: evenly-divisible int/int division returns int in PHP.
	$mae       = (float) ( array_sum( array_map( 'abs', $errors ) ) / $checks );
	$mae_naive = (float) ( array_sum( $abs_naive ) / $checks );
	// SKILL (v13.75.0): 1 - mae/mae_naive. Positive means the model beat
	// persistence; <= 0 means it did not, and a forecast nobody should act on is
	// worse than an empty panel. Undefined (null), not a failure, when
	// persistence is already essentially perfect, exactly (mae_naive 0) or
	// relative to the series' own level: a rigid series stays forecastable.
	$level = (float) abs( (float) sn_analytics_stat_median( array_map( 'abs', $ys ) ) );
	$floor = SN_ANALYTICS_FORECAST_SKILL_MIN_REL * $level;
	$skill = ( $mae_naive > 0.0 && $mae_naive >= $floor )
		? (float) ( 1.0 - ( $mae / $mae_naive ) )
		: null;
	return array(
		'mae'         => $mae,
		'mae_naive'   => $mae_naive,
		'skill'       => $skill,
		'coverage'    => $scored > 0 ? (float) ( $inside / $scored ) : null,
		'band_checks' => $scored,
		'checks'      => $checks,
		'errors'      => $errors,
	);
}

/**
 * A forecast's confidence, judged against its band's own target (80%), not
 * the old 95% interval's: 'high' when the band held within
 * SN_ANALYTICS_FORECAST_HIGH_TOL of target over at least
 * SN_ANALYTICS_FORECAST_HIGH_CHECKS out-of-sample checks; 'medium' from half;
 * 'low' below that or while unmeasured (null).
 *
 * @param float|null $coverage    Out-of-sample coverage, or null.
 * @param int        $band_checks Checks it was measured over.
 * @return string
 */
function sn_analytics_forecast_confidence( $coverage, $band_checks ) {
	if ( null === $coverage ) {
		return 'low';
	}
	if ( $coverage >= ( 1 - 2 * SN_ANALYTICS_FORECAST_BAND_Q ) - SN_ANALYTICS_FORECAST_HIGH_TOL && $band_checks >= SN_ANALYTICS_FORECAST_HIGH_CHECKS ) {
		return 'high';
	}
	return $coverage >= 0.5 ? 'medium' : 'low';
}

/**
 * Compose one subject's daily-views series into a forecast Signal, or null when
 * suppressed. Both models are backtested on the series; the median is used
 * unless Holt beats it by SN_ANALYTICS_FORECAST_HOLT_MARGIN (see
 * sn_analytics_forecast_point()). Honesty gates: below
 * SN_ANALYTICS_FORECAST_MIN_POINTS → null; zero median level → null; the
 * chosen model's skill <= 0 → forecast_withheld with its score. The 80% band
 * is the 10th to 90th percentile of the chosen model's past misses, and
 * confidence is how often that band held out of sample ('low', and said so,
 * while unmeasured). Bounds clamp at 0. Only a Holt forecast carries a
 * `direction`: a median states a level, not a trend.
 * @return array|null Signal
 */
function sn_analytics_forecast_of( $subject, $label, $series, $from, $to, $opts = array() ) {
	$min     = max( 4, (int) ( $opts['min_points'] ?? SN_ANALYTICS_FORECAST_MIN_POINTS ) );
	$horizon = max( 1, (int) ( $opts['horizon'] ?? SN_ANALYTICS_FORECAST_HORIZON ) );
	$ys      = array_map( static function ( $r ) { return (float) ( $r['views'] ?? 0 ); }, (array) $series );
	if ( count( $ys ) < $min ) { return null; }
	$level = sn_analytics_stat_median( $ys );
	if ( null === $level || $level <= 0 ) { return null; }
	$train  = min( SN_ANALYTICS_FORECAST_WINDOW, max( 3, (int) floor( count( $ys ) / 2 ) ) );
	$by_med = sn_analytics_forecast_backtest( $ys, $horizon, $train, 'median' );
	if ( null === $by_med ) { return null; }
	$by_hol = sn_analytics_forecast_backtest( $ys, $horizon, $train, 'holt' );
	// Holt only on a clear, measured win: both skills defined, Holt ahead by the margin.
	$holt     = is_array( $by_hol ) && null !== $by_hol['skill'] && null !== $by_med['skill']
		&& $by_hol['skill'] > $by_med['skill'] + SN_ANALYTICS_FORECAST_HOLT_MARGIN;
	$model    = $holt ? 'holt' : 'median';
	$backtest = $holt ? $by_hol : $by_med;
	$stat     = $holt ? 'holt_linear' : 'rolling_median';
	$named    = $holt ? 'Holt trend' : sprintf( '%d-day median', SN_ANALYTICS_FORECAST_WINDOW );
	// SKILL GATE (v13.75.0). Suppress WITH THE REASON rather than returning
	// null: a bare null renders as absence, and absence is indistinguishable
	// from "no data yet". null skill is NOT a failure: persistence was perfect,
	// so the comparison is undefined, and a rigid series stays forecastable.
	$skill = $backtest['skill'] ?? null;
	if ( null !== $skill && $skill <= 0.0 ) {
		return array(
			'id'            => 'forecast-withheld:' . $subject . ':' . (string) $to . '+' . $horizon . 'd',
			'tier'          => 'predictive',
			'kind'          => 'forecast_withheld',
			'subject'       => $subject,
			'subject_label' => (string) $label,
			'stat'          => $stat,
			'value'         => null,
			'confidence'    => 'none',
			'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $ys ) ),
			// Both scores, as measured: Holt can beat the baseline and still not
			// clear the lead it needs over the median, so "neither" would be false.
			'plain_label'   => null !== ( $by_hol['skill'] ?? null )
				? sprintf(
					'%s: no forecast: against a same-value baseline the %d-day median scores %.2f and a Holt trend %.2f over %d checks; a forecast needs a score above 0, and Holt a lead of %.1f over the median',
					(string) $label, SN_ANALYTICS_FORECAST_WINDOW, (float) $by_med['skill'], (float) $by_hol['skill'], (int) $backtest['checks'], SN_ANALYTICS_FORECAST_HOLT_MARGIN
				)
				: sprintf(
					'%s: no forecast: the %d-day median does not beat a same-value baseline on this history (skill %.2f over %d checks)',
					(string) $label, SN_ANALYTICS_FORECAST_WINDOW, (float) $skill, (int) $backtest['checks']
				),
			'severity'      => 0,
		);
	}
	$point = (float) sn_analytics_forecast_point( $ys, $horizon, $model );
	$lo    = (float) sn_analytics_stat_quantile( $backtest['errors'], SN_ANALYTICS_FORECAST_BAND_Q );
	$hi    = (float) sn_analytics_stat_quantile( $backtest['errors'], 1 - SN_ANALYTICS_FORECAST_BAND_Q );
	$shown = max( 0.0, $point );
	$low   = max( 0.0, $shown + $lo );
	$high  = max( 0.0, $shown + $hi );
	$cov     = $backtest['coverage'];
	$nominal = 1 - 2 * SN_ANALYTICS_FORECAST_BAND_Q;
	$conf    = sn_analytics_forecast_confidence( $cov, (int) $backtest['band_checks'] );
	// Plain English, as every engine label is (the narrator and MCP read them).
	$held    = null === $cov ? 'band not yet measured on this history' : sprintf( 'held on %d%% of %d past checks', (int) round( $cov * 100 ), (int) $backtest['band_checks'] );
	$signal = array(
		'id'            => 'forecast:' . $subject . ':' . (string) $to . '+' . $horizon . 'd',
		'tier'          => 'predictive',
		'kind'          => 'forecast',
		'subject'       => $subject,
		'subject_label' => (string) $label,
		'stat'          => $stat,
		'value'         => round( $shown, 1 ),
		'interval'      => array( 'low' => round( $low, 1 ), 'high' => round( $high, 1 ) ),
		'confidence'    => $conf,
		'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $ys ) ),
		'plain_label'   => sprintf(
			'%s: next %d days about %.1f/day (%s; %d%% band %.1f to %.1f; %s)',
			(string) $label, $horizon, $shown, $named, (int) round( $nominal * 100 ), $low, $high, $held
		),
		'severity'      => 1,
	);
	if ( $holt ) {
		// A trend the backtest chose over the median says which way it runs.
		$fit  = sn_analytics_stat_holt( $ys );
		$move = $horizon * (float) $fit['trend'];
		$signal['direction'] = ( abs( $move / $level ) < 0.05 ) ? 'flat' : ( $move > 0 ? 'up' : 'down' );
		$signal['severity']  = 'down' === $signal['direction'] ? 2 : 1;
	}
	return $signal;
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
