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
