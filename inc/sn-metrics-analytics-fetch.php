<?php
/**
 * Signal & Noise Tools: the per-day reads behind sn-metrics' analytics_query.
 *
 * The rollups hold one dimension per row: the daily table is (day, path) and
 * the dims table is (day, dim, value). So the only base a query can read is
 * one dimension per day, and the executor groups, filters and compares in PHP
 * (inc/sn-metrics-analytics-query.php). Shaped on
 * sn_analytics_dimension_series(): prepared, null on a failed read.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-day rows for one base dimension.
 *
 * @param string $base  day | path | referrer | country | device.
 * @param string $from  Y-m-d, site-local.
 * @param string $to    Y-m-d, site-local.
 * @param string $class Traffic class.
 * @return array<int,array{day:string,value:string,views:int,visits:int,scroll_w:float,time_w:float}>|null
 */
function snt_mq_fetch( $base, $from, $to, $class ) {
	global $wpdb;
	$class = snt_analytics_resolve_class( $class );
	if ( 'day' === $base || 'path' === $base ) {
		$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
		$value = 'path' === $base ? sn_analytics_canonical_path_sql( 'path' ) : "''";
		$group = 'path' === $base ? 'day, ' . $value : 'day';
		// $value and $group are constant expressions over literal column names;
		// $table is the prefix plus a plugin constant; every input is bound.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = $wpdb->prepare(
			"SELECT day, {$value} AS value, SUM(views) AS views, SUM(visits) AS visits,
			        SUM(scroll_avg * views) AS scroll_w, SUM(time_avg * views) AS time_w
			 FROM {$table}
			 WHERE day >= %s AND day <= %s AND class = %s
			 GROUP BY {$group}",
			(string) $from,
			(string) $to,
			$class
		);
	} else {
		$table = $wpdb->prefix . SN_ANALYTICS_DIMS_TABLE;
		$sql = $wpdb->prepare(
			"SELECT day, value, SUM(views) AS views, SUM(visits) AS visits, 0 AS scroll_w, 0 AS time_w
			 FROM {$table}
			 WHERE day >= %s AND day <= %s AND dim = %s AND class = %s
			 GROUP BY day, value",
			(string) $from,
			(string) $to,
			(string) $base,
			$class
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
	$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	if ( ! is_array( $results ) || '' !== (string) $wpdb->last_error ) {
		return null;
	}
	$out = array();
	foreach ( $results as $r ) {
		$out[] = array(
			'day'      => (string) $r['day'],
			'value'    => (string) $r['value'],
			'views'    => (int) $r['views'],
			'visits'   => (int) $r['visits'],
			'scroll_w' => (float) $r['scroll_w'],
			'time_w'   => (float) $r['time_w'],
		);
	}
	return $out;
}
