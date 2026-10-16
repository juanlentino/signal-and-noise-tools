<?php
/**
 * Signal & Noise Tools: analytics-signals.php, the options, the robust
 * statistics and the anomaly detector.
 *
 * Split from inc/analytics-signals.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

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

