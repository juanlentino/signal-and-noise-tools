<?php
/**
 * Signal & Noise Tools: scroll per view, counted by pageview ID.
 *
 * The beacon sends each scroll milestone at most once per view, so
 * 25 × scroll_events / views is the mean deepest milestone only when every
 * 'sc' event's pageview was stored too. Some are not: on 2026-11-01, 21 of the
 * 500 busiest stored path-days read above 100% a view, up to 400% on one. The
 * second-generation rows carry the view's ID (double11, since 2026-10-05), so
 * this read takes each view's deepest milestone and keeps only views whose
 * pageview row is in the same day, path and class: an 'sc' with no stored
 * pageview counts nowhere. A pv row's double1 is 0, so max(double1) over a
 * view's pv and sc rows is its deepest milestone.
 *
 * It feeds only the stored scroll_events and scroll_sum. The legacy scroll_avg
 * keeps its raw per-event ratio. A legacy-dataset window (no pageview ID), a
 * failed read or one cut by the row cap keeps the plain event count, as before.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row per scrolled pageview ID: its stored views and deepest milestone.
 * Same window, zone and fold as the main rollup query, so the keys merge.
 *
 * @param int    $days Trailing window in days.
 * @param string $tz   IANA zone, the one the main query ran with.
 * @param bool   $fold Fold over-cap visitor-days into bot (sn_analytics_rollup_class_parts()).
 * @return string AE SQL, or '' when the window reads the legacy dataset.
 */
function sn_analytics_rollup_pid_scroll_sql( $days, $tz = '', $fold = true ) {
	$source = sn_analytics_source( sn_analytics_trailing_from( $days ) );
	if ( 'sn_pageviews_v2' !== $source ) {
		return '';
	}
	list( $class, $cap )     = sn_analytics_rollup_class_parts( $fold );
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( $days, $tz );
	return implode( ' ', array(
		"SELECT {$day_col} AS day,",
		'blob2 AS path,',
		$class . ' AS class,',
		'double11 AS pid,',
		"sumIf(_sample_interval, blob1 = 'pv') AS views,",
		'max(double1) AS depth',
		'FROM ' . $source,
		"WHERE timestamp >= {$lower}" . sn_analytics_window_upper( $tz ) . $cap,
		"AND blob1 IN ('pv', 'sc') AND double11 > 0",
		'GROUP BY day, path, class, pid',
		'HAVING depth > 0',
	) );
}

/**
 * Run the per-view read. Null when there is none, it failed, or the row cap
 * cut it (a partial set would undercount the views it dropped).
 *
 * @param string $sql From sn_analytics_rollup_pid_scroll_sql().
 * @return array|null
 */
function sn_analytics_rollup_pid_scroll_query( $sql ) {
	if ( '' === $sql ) {
		return null;
	}
	$rows = sn_analytics_query( $sql );
	if ( ! is_array( $rows ) ) {
		return null;
	}
	if ( function_exists( 'sn_analytics_last_result_truncated' ) && sn_analytics_last_result_truncated() ) {
		error_log( '[sn-analytics] per-view scroll read truncated by the AE row cap; the plain scroll event count stands for this roll' );
		return null;
	}
	return $rows;
}

/**
 * Each main row gains scroll_events_view: the milestones of its stored views,
 * each view counted once at its deepest. PURE.
 *
 * @param array      $rows Main rollup rows.
 * @param array|null $pid  Per-view rows, or null (the rows are returned as they were).
 * @return array
 */
function sn_analytics_rollup_merge_pid_scroll( $rows, $pid ) {
	if ( ! is_array( $rows ) || ! is_array( $pid ) ) {
		return is_array( $rows ) ? $rows : array();
	}
	$step = defined( 'SN_ANALYTICS_SCROLL_MILESTONE_STEP' ) ? SN_ANALYTICS_SCROLL_MILESTONE_STEP : 25;
	$sum  = array();
	foreach ( $pid as $p ) {
		if ( ! is_array( $p ) ) {
			continue;
		}
		// Weighted by the view's stored pageviews: a milestone with none (0) counts nowhere.
		$k         = sn_analytics_rollup_row_key( $p );
		$sum[ $k ] = ( $sum[ $k ] ?? 0 ) + max( 0.0, (float) ( $p['views'] ?? 0 ) ) * min( 4, max( 0, (int) round( (float) ( $p['depth'] ?? 0 ) / $step ) ) );
	}
	$out = array();
	foreach ( $rows as $r ) {
		if ( is_array( $r ) ) {
			$r['scroll_events_view'] = (int) round( $sum[ sn_analytics_rollup_row_key( $r ) ] ?? 0 );
		}
		$out[] = $r;
	}
	return $out;
}
