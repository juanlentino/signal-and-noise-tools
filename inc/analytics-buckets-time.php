<?php
/**
 * The time-on-page distribution, bucketed per VIEW (2026-11-14). A view's
 * visible time arrives as one or more `tm` deltas: the leave flush, a flush on
 * each return to the tab, and since theme 2026.11.13 the "still here" ping at
 * 10 seconds. Bucketing each delta on its own would put one 25-second view in
 * two bands (10 s and 15 s) and count it twice. This sums the deltas per
 * visitor-day, page and UTC day first, then buckets that sum in PHP.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rows one read may return; a read at the cap is not written. */
const SN_ANALYTICS_BUCKETS_TIME_LIMIT = 5000;

/**
 * Per visitor-day, page, UTC day and class: the summed visible time and the
 * view's sampling weight. Only shapes already live: sum over a Double, max
 * over the sample interval, GROUP BY aliases. PURE.
 *
 * @param int $days Trailing window in days.
 * @return string AE SQL.
 */
function sn_analytics_buckets_time_view_sql( $days ) {
	$days = max( 1, (int) $days );
	return implode( ' ', array(
		"SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day,",
		sn_analytics_class_select() . ' AS class,',
		'index1 AS vid, blob2 AS path, sum(double2) AS ms, max(_sample_interval) AS w',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( $days ) ),
		"WHERE blob1 = 'tm' AND timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)" . sn_analytics_excluded_path_sql() . sn_analytics_overcap_where() . sn_analytics_window_upper(),
		'GROUP BY day, class, vid, path',
		'LIMIT ' . SN_ANALYTICS_BUCKETS_TIME_LIMIT,
	) );
}

/**
 * Per-view rows into (day, class, band) rows, the shape the wide read melted
 * into. PURE.
 *
 * @param array $rows    sn_analytics_buckets_time_view_sql() rows.
 * @param array $buckets The time metric's bands.
 * @return array<int,array{day:string,metric:string,bucket:string,class:string,views:float}>
 */
function sn_analytics_buckets_time_melt( array $rows, array $buckets ) {
	$acc = array();
	foreach ( $rows as $r ) {
		$r  = (array) $r;
		$ms = (float) ( $r['ms'] ?? 0 );
		foreach ( $buckets as $i => $b ) {
			if ( $ms >= (int) $b['lo'] && ( null === $b['hi'] || $ms < (int) $b['hi'] ) ) {
				$k         = (string) ( $r['day'] ?? '' ) . '|' . (string) ( $r['class'] ?? 'human' ) . '|' . $i;
				$acc[ $k ] = (float) ( $acc[ $k ] ?? 0 ) + max( 1.0, (float) ( $r['w'] ?? 1 ) );
				break;
			}
		}
	}
	$out = array();
	foreach ( $acc as $k => $views ) {
		list( $day, $class, $i ) = explode( '|', $k );
		$out[] = array( 'day' => $day, 'metric' => 'time', 'bucket' => 'b' . $i, 'class' => $class, 'views' => $views );
	}
	return $out;
}
