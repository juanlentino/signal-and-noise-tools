<?php
/**
 * Signal & Noise — dashboard read accessors over the path rollup table.
 *
 * Aggregation cuts the Analytics admin tab + the re-pointed dashboard widgets
 * need: top pages (views-weighted engagement), range totals (the stat cards),
 * and the per-day series (the trend strip). Kept separate from
 * inc/analytics-rollup.php (which owns the table + cron) to keep each file small.
 *
 * All read the durable wp_sn_analytics_daily table — never AE — so they never
 * block a render and return [] / zeros while the table is dormant (no creds).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top pages over [$from,$to] for one class, with views-weighted scroll/time.
 *
 * @return array<int, array{path:string, views:int, visits:int, scroll_avg:float, time_avg:float}>
 */
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$limit = max( 1, min( 500, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT path,
		        SUM(views)  AS views,
		        SUM(visits) AS visits,
		        SUM(scroll_avg * views) / NULLIF(SUM(views), 0) AS scroll_avg,
		        SUM(time_avg  * views) / NULLIF(SUM(views), 0) AS time_avg
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY path
		 ORDER BY views DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		$class,
		$limit
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'path'       => (string) $r['path'],
				'views'      => (int) $r['views'],
				'visits'     => (int) $r['visits'],
				'scroll_avg' => (float) $r['scroll_avg'],
				'time_avg'   => (float) $r['time_avg'],
			);
		}
	}
	return $out;
}

/**
 * Range totals for the stat cards: summed views/visits + views-weighted
 * scroll/time across all paths for one class.
 *
 * @return array{views:int, visits:int, scroll_avg:float, time_avg:float}
 */
function sn_analytics_range_totals( $from, $to, $class = 'human' ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	$row = $wpdb->get_results( $wpdb->prepare(
		"SELECT SUM(views)  AS views,
		        SUM(visits) AS visits,
		        SUM(scroll_avg * views) / NULLIF(SUM(views), 0) AS scroll_avg,
		        SUM(time_avg  * views) / NULLIF(SUM(views), 0) AS time_avg
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s",
		(string) $from,
		(string) $to,
		$class
	), ARRAY_A );

	$r = ( is_array( $row ) && isset( $row[0] ) && is_array( $row[0] ) ) ? $row[0] : array();
	return array(
		'views'      => (int) ( $r['views'] ?? 0 ),
		'visits'     => (int) ( $r['visits'] ?? 0 ),
		'scroll_avg' => (float) ( $r['scroll_avg'] ?? 0 ),
		'time_avg'   => (float) ( $r['time_avg'] ?? 0 ),
	);
}

/**
 * Pick a time-bucket granularity for a window length.
 * Ranges ≤90d render daily; longer ranges roll up to ISO weeks so the trend
 * strip stays legible (≤~52 bars/year) and fast.
 *
 * @param int $days
 * @return string 'day' | 'week'
 */
function sn_analytics_granularity( $days ) {
	return ( (int) $days > 90 ) ? 'week' : 'day';
}

/**
 * SQL expression that maps a `day` DATE to its bucket key.
 * Week granularity floors to the ISO Monday (WEEKDAY: Mon=0). The returned
 * fragment is a fixed literal — never user data — so it is safe to interpolate.
 *
 * @param string $granularity 'day' | 'week'
 * @return string
 */
function sn_analytics_bucket_expr( $granularity ) {
	return ( 'week' === $granularity )
		? 'DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)'
		: 'day';
}

/**
 * Per-day views/visits for one class, ascending — the trend strip.
 *
 * @return array<int, array{day:string, views:int, visits:int}>
 */
function sn_analytics_daily_series( $from, $to, $class = 'human' ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, SUM(views) AS views, SUM(visits) AS visits
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY day
		 ORDER BY day ASC",
		(string) $from,
		(string) $to,
		$class
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'day'    => (string) $r['day'],
				'views'  => (int) $r['views'],
				'visits' => (int) $r['visits'],
			);
		}
	}
	return $out;
}
