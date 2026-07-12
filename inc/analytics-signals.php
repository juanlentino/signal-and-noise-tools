<?php
/**
 * Signal & Noise — analytics signal engine (predictive tier).
 * Pure statistics over the existing durable rollups. Produces typed Signal[]
 * (spec §5.1); no LLM, no new AE queries. @package SignalNoiseTools @since 9.30.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SN_ANALYTICS_SIGNAL_BASELINE_DAYS = 30;
const SN_ANALYTICS_SIGNAL_FLOOR_DAYS    = 14;
const SN_ANALYTICS_ANOMALY_Z            = 3.5;   // MAD-based robust z (~3σ for normal data)
const SN_ANALYTICS_TRAJ_MIN_POINTS      = 14;
const SN_ANALYTICS_FORECAST_HORIZON      = 7;    // days ahead (point at horizon; interval widens with √h)
const SN_ANALYTICS_FORECAST_MIN_POINTS   = 21;   // min history; below → suppressed (never fake precision)
const SN_ANALYTICS_FORECAST_HISTORY_DAYS = 30;   // trailing fit window ending $to (decoupled from display)
const SN_ANALYTICS_FORECAST_Z            = 1.96; // ~95% nominal interval; the backtest MEASURES real coverage

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
	$z_thresh      = (float) ( $opts['z'] ?? SN_ANALYTICS_ANOMALY_Z );
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
		sn_analytics_signal_trajectories( $from, $to, $class, $opts )
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
