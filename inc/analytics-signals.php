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
