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
 * Request-scope memo (D5 §5 perf): the header region
 * (inc/analytics-header-region.php:38), the insights band
 * (inc/analytics-insights.php:75), and the Dashboard-home widget all pull the
 * SAME [$from,$to,$class] window once per page load (audit E§3.8) — cache it
 * per request so that costs one read, not three. $refresh is the re-prime
 * seam (the D2 sn_analytics_recommendations( true ) idiom) for callers that
 * must force a fresh read within the same request (e.g. CLI/tests).
 *
 * @param bool $refresh Bypass and re-prime the memo for this key.
 * @return array{views:int, visits:int, scroll_avg:float, time_avg:float}
 */
function sn_analytics_range_totals( $from, $to, $class = 'human', $refresh = false ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}

	static $memo = array();
	$key = $from . '|' . $to . '|' . $class;
	if ( ! $refresh && isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
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
	$memo[ $key ] = array(
		'views'      => (int) ( $r['views'] ?? 0 ),
		'visits'     => (int) ( $r['visits'] ?? 0 ),
		'scroll_avg' => (float) ( $r['scroll_avg'] ?? 0 ),
		'time_avg'   => (float) ( $r['time_avg'] ?? 0 ),
	);
	return $memo[ $key ];
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
 * Per-day (or per-week) views/visits for one class, ascending — the trend strip.
 *
 * Request-scope memo (D5 §5 perf): sn_analytics_signal_anomalies() and
 * sn_analytics_signal_forecasts() (inc/analytics-signals.php) both pull the
 * SAME [$from,$to,$class,'day'] trailing-baseline window once per page load
 * (QM: 2 reads per page) — cache it per request so that costs one read, not
 * two. $refresh is the re-prime seam (the D2 sn_analytics_recommendations( true )
 * idiom, mirrored in sn_analytics_range_totals()) for callers that must force
 * a fresh read within the same request (e.g. CLI/tests).
 *
 * @param string $granularity 'day' | 'week'  Week granularity floors each day
 *                             to the ISO Monday (DATE_SUB … WEEKDAY) so weekly
 *                             bars line up naturally. Use sn_analytics_granularity()
 *                             to pick the right value for a given date range.
 * @param bool   $refresh     Bypass and re-prime the memo for this key.
 * @return array<int, array{day:string, views:int, visits:int}>
 */
function sn_analytics_daily_series( $from, $to, $class = 'human', $granularity = 'day', $refresh = false ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}

	static $memo = array();
	$key = $from . '|' . $to . '|' . $class . '|' . $granularity;
	if ( ! $refresh && isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}

	$expr = sn_analytics_bucket_expr( $granularity );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $expr is a hardcoded SQL fragment ("day" or a fixed DATE_SUB() expression) from sn_analytics_bucket_expr(); $table is $wpdb->prefix + a plugin constant; every user value is bound via prepare().
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT {$expr} AS day, SUM(views) AS views, SUM(visits) AS visits
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY {$expr}
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
	$memo[ $key ] = $out;
	return $memo[ $key ];
}

/**
 * Per-bucket view series for a set of dimension values, in ONE batched query
 * (avoids an N+1 across table rows). Returns value => [{day,views}, …].
 *
 * @param string   $dim
 * @param string[] $values      already-trusted top-N dimension values
 * @param string   $granularity 'day' | 'week'
 * @return array<string, array<int, array{day:string, views:int}>>
 */
function sn_analytics_dimension_series( $dim, $values, $from, $to, $class = 'human', $granularity = 'day' ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$values = array_values( array_filter( (array) $values, 'is_string' ) );
	if ( empty( $values ) ) {
		return array();
	}
	$expr = sn_analytics_bucket_expr( $granularity );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DIMS_TABLE;

	$in_ph   = implode( ',', array_fill( 0, count( $values ), '%s' ) );
	$args    = array_merge( array( (string) $from, (string) $to, $dim, $class ), $values );
	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $expr is a hardcoded SQL fragment ("day" or a fixed DATE_SUB() expression) from sn_analytics_bucket_expr(); $table is $wpdb->prefix + a plugin constant; every user value is bound via prepare().
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT {$expr} AS day, value, SUM(views) AS views
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND dim = %s AND class = %s AND value IN ({$in_ph})
		 GROUP BY {$expr}, value
		 ORDER BY day ASC",
		$args
	), ARRAY_A );

	$map = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$v         = (string) $r['value'];
			$map[ $v ][] = array( 'day' => (string) $r['day'], 'views' => (int) $r['views'] );
		}
	}
	return $map;
}

const SN_ANALYTICS_LOWENGAGE_SCROLL    = 25;     // page-weighted scroll % below this …
const SN_ANALYTICS_LOWENGAGE_TIME_MS   = 10000;  // … AND time below this (ms) …
const SN_ANALYTICS_LOWENGAGE_MIN_VIEWS = 20;     // … on pages with at least this many views.

/**
 * Pages with real traffic but weak engagement (low scroll AND low dwell) —
 * "pages losing readers". Page-weighted averages match range_totals/top_paths.
 *
 * @return array<int, array{path:string, views:int, scroll_avg:float, time_avg:float}>
 */
function sn_analytics_low_engagement_paths( $from, $to, $class = 'human', $limit = 15 ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$limit = max( 1, min( 200, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT path,
		        SUM(views) AS views,
		        SUM(scroll_avg * views) / NULLIF(SUM(views), 0) AS scroll_avg,
		        SUM(time_avg  * views) / NULLIF(SUM(views), 0) AS time_avg
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY path
		 HAVING views >= %d
		        AND scroll_avg < %d
		        AND time_avg  < %d
		 ORDER BY views DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		$class,
		SN_ANALYTICS_LOWENGAGE_MIN_VIEWS,
		SN_ANALYTICS_LOWENGAGE_SCROLL,
		SN_ANALYTICS_LOWENGAGE_TIME_MS,
		$limit
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'path'       => (string) $r['path'],
				'views'      => (int) $r['views'],
				'scroll_avg' => (float) $r['scroll_avg'],
				'time_avg'   => (float) $r['time_avg'],
			);
		}
	}
	return $out;
}

/**
 * Earliest day present in the durable rollup — the lower bound for the
 * "All-time" range. Cached for an hour (the table only grows by one day/run).
 *
 * @return string Y-m-d (today if the table is empty).
 */
function sn_analytics_min_day() {
	$cached = get_transient( 'sn_analytics_min_day' );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}
	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	$min   = $wpdb->get_var( "SELECT MIN(day) FROM {$table}" );
	$min   = ( is_string( $min ) && '' !== $min ) ? $min : gmdate( 'Y-m-d' );
	set_transient( 'sn_analytics_min_day', $min, HOUR_IN_SECONDS );
	return $min;
}

/**
 * Per-bucket bot share over the window (durable — no AE). Sums views across all
 * classes per bucket and exposes bot_pct for the Quality-tab trend line.
 *
 * @param string $from        YYYY-MM-DD start of window (inclusive).
 * @param string $to          YYYY-MM-DD end of window (inclusive).
 * @param string $granularity 'day' | 'week'
 * @return array<int, array{day:string, total:int, bot:int, bot_pct:int}>
 */
function sn_analytics_class_series( $from, $to, $granularity = 'day' ) {
	$expr = sn_analytics_bucket_expr( $granularity );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $expr is a hardcoded SQL fragment ("day" or a fixed DATE_SUB() expression) from sn_analytics_bucket_expr(); $table is $wpdb->prefix + a plugin constant; every user value is bound via prepare().
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT {$expr} AS day, class, SUM(views) AS views
		 FROM {$table}
		 WHERE day >= %s AND day <= %s
		 GROUP BY {$expr}, class
		 ORDER BY day ASC",
		(string) $from,
		(string) $to
	), ARRAY_A );

	$acc = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$d = (string) $r['day'];
			if ( ! isset( $acc[ $d ] ) ) {
				$acc[ $d ] = array( 'day' => $d, 'total' => 0, 'bot' => 0 );
			}
			$v                  = (int) $r['views'];
			$acc[ $d ]['total'] += $v;
			if ( 'bot' === (string) $r['class'] ) {
				$acc[ $d ]['bot'] += $v;
			}
		}
	}
	$out = array();
	foreach ( $acc as $row ) {
		$row['bot_pct'] = ( $row['total'] > 0 ) ? (int) round( $row['bot'] / $row['total'] * 100 ) : 0;
		$out[]          = $row;
	}
	return $out;
}
