<?php
/**
 * Signal & Noise Tools: analytics-signals.php, the trajectories, the signals
 * bundle and the forecast point, Holt, quantile, backtest and confidence.
 *
 * Split from inc/analytics-signals.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

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

