<?php
/**
 * reader-anomalies on the evidence basis: the verified and unverified series.
 *
 * Verification is read through snt_mr_row_verification(), the rights evidence
 * classifier, so a day before the sensor recorded Cloudflare's verified-bot
 * category (or the partial day it began) is in neither series: it is
 * unverifiable, not unverified. The co-movement rule is in
 * inc/ml-reader-anomalies-co-movement.php, which this file loads.
 *
 * @package Signal_And_Noise_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * The first whole UTC day the sensor recorded verification, clamped to $from.
 *
 * @param string $from Window start, YYYY-MM-DD.
 * @return string YYYY-MM-DD.
 */
function snt_ml_reader_verifiable_from( $from ) {
	$since = defined( 'SN_RIGHTS_EVIDENCE_VERIFIED_SINCE' ) ? SN_RIGHTS_EVIDENCE_VERIFIED_SINCE : '9999-12-31T00:00:00Z';
	$day   = substr( $since, 0, 10 );
	if ( $day . 'T00:00:00Z' < $since ) {
		$day = gmdate( 'Y-m-d', strtotime( $day . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
	}
	return max( (string) $from, $day );
}

/**
 * One family's verified and unverified series over the verifiable days.
 *
 * Each split carries its total, days present and baseline, and runs the same
 * tiers as the family series once it is present on SN_MR_SERIES_MIN_DAYS
 * verifiable days; until then its signals are withheld, with the reason.
 *
 * @param array  $rows   Aggregate rows, first-party already removed.
 * @param string $family Family.
 * @param string $from   Window start.
 * @param string $to     Window end.
 * @return array{from:string,to:string,verified:array,unverified:array}
 */
function snt_ml_reader_verification_split( array $rows, $family, $from, $to ) {
	$vfrom = snt_ml_reader_verifiable_from( $from );
	$out   = array( 'from' => $vfrom, 'to' => (string) $to );
	foreach ( array( 'verified', 'unverified' ) as $class ) {
		$sub     = array_filter( $rows, static fn( $r ) => (string) ( $r['family'] ?? '' ) === (string) $family && $class === snt_mr_row_verification( $r ) );
		$series  = snt_mr_daily_series( $sub, $family, $vfrom, $to );
		$values  = array_map( static fn( $r ) => (int) $r['views'], $series );
		$present = count( array_filter( $values ) );
		$median  = ( $values && function_exists( 'sn_analytics_stat_median' ) ) ? sn_analytics_stat_median( $values ) : null;
		$entry   = array(
			'total'        => array_sum( $values ),
			'days_present' => $present,
			'baseline'     => array(
				'median' => $median,
				'mad'    => ( null !== $median && function_exists( 'sn_analytics_stat_mad' ) ) ? sn_analytics_stat_mad( $values, $median ) : null,
			),
			'signals'      => array(),
		);
		if ( $present >= SN_MR_SERIES_MIN_DAYS ) {
			$entry['signals'] = snt_ml_reader_signals_for( $family . '/' . $class, $series, $vfrom, $to );
		} else {
			$entry['withheld'] = sprintf( 'present on %d of %d verifiable days; signals need %d', $present, count( $series ), SN_MR_SERIES_MIN_DAYS );
		}
		$out[ $class ] = $entry;
	}
	return $out;
}

require_once __DIR__ . '/ml-reader-anomalies-co-movement.php'; // one signal per network when three or more families move together.
