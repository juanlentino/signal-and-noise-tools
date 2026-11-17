<?php
/**
 * Signal & Noise Tools: the "estimated (sampled)" day label, read from the
 * sampled-day record (inc/analytics-sampled-days.php). Label only: nothing
 * here changes a number.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a site day's figures are estimates: Analytics Engine sampled at least
 * one counted-human row that day. Per-day evidence, not a range from onset to
 * a fix date (no fix date exists yet). Label only; never change a number.
 *
 * @param string $day Y-m-d (site day).
 * @return bool
 */
function sn_analytics_day_estimated( $day ) {
	static $estimated = null;
	if ( null === $estimated ) {
		$report    = sn_analytics_sampled_report( (array) get_option( SN_ANALYTICS_SAMPLED_OPT, array() ) );
		$estimated = array_column( array_filter( $report['days'], static fn( $d ) => $d['estimated'] ), 'estimated', 'day' );
	}
	return isset( $estimated[ (string) $day ] );
}

/**
 * The same rows with `estimated` added to each: true when any day the row
 * covers ($span days from its `day`, 7 for a week bucket) is estimated.
 * Label only: every other key and number is returned as it came.
 *
 * @param array $rows Rows carrying a Y-m-d `day`.
 * @param int   $span Days each row covers.
 * @return array
 */
function sn_analytics_mark_estimated( array $rows, $span = 1 ) {
	$out = array();
	foreach ( $rows as $row ) {
		$start = strtotime( (string) ( $row['day'] ?? '' ) . ' 12:00:00 UTC' );
		$est   = false;
		for ( $i = 0; false !== $start && $i < max( 1, (int) $span ) && ! $est; $i++ ) {
			$est = sn_analytics_day_estimated( gmdate( 'Y-m-d', $start + $i * 86400 ) );
		}
		$out[] = array_merge( (array) $row, array( 'estimated' => $est ) );
	}
	return $out;
}

/**
 * A card title over a window, with " · estimated (sampled)" when any site day
 * from $from to $to (Y-m-d, inclusive) is estimated: a country, source or page
 * total over that window carries the day's weighted-up rows. Label only.
 *
 * @param string $title Card title.
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @return string
 */
function sn_analytics_title_estimated( $title, $from, $to ) {
	$t   = strtotime( (string) $from . ' 12:00:00 UTC' );
	$end = strtotime( (string) $to . ' 12:00:00 UTC' );
	for ( $i = 0; false !== $t && false !== $end && $t <= $end && $i < 400; $t += 86400, $i++ ) {
		if ( sn_analytics_day_estimated( gmdate( 'Y-m-d', $t ) ) ) {
			return $title . ' · estimated (sampled)';
		}
	}
	return $title;
}
