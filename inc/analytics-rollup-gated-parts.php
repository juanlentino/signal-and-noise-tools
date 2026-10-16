<?php
/**
 * Signal & Noise Tools: Analytics Rollup, the gated visits query and the row helpers (the gated SQL and read, the merge, the row key, the nullable and legacy averages).
 *
 * Split from inc/analytics-rollup.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the SECOND rollup query: pageview-gated distinct visitor-days.
 *
 * P0.1 verdict (live probe, 2026-07-17): the single-query gated distinct
 * count(DISTINCT if(blob1 = 'pv', index1, NULL)) is rejected by live AE
 * (HTTP 422 — IF() branches must share a type), and the dialect guard's ban on
 * count(DISTINCT <expr>) STAYS. Fallback A passed: the existing verified
 * visits shape with AND blob1 = 'pv' in WHERE and a bare-column
 * count(DISTINCT index1). Results merge into the main rows per
 * (day, path, class) in sn_analytics_rollup_merge_gated().
 *
 * ORDER BY uses the pageview_visits alias — AE resolves aliases only, and the
 * `views` alias does not exist in this SELECT (alias-only ORDER BY gotcha).
 *
 * @param int    $days Trailing window in days (floored to >= 1).
 * @param string $tz   Optional IANA zone — MUST match the main query's zone.
 * @param bool   $fold Fold over-cap visitor-days into bot (false: the pre-fold shape, sn_analytics_rollup_class_parts()).
 * @return string AE SQL.
 */
function sn_analytics_rollup_gated_sql( $days, $tz = '', $fold = true ) {
	list( $class, $cap ) = sn_analytics_rollup_class_parts( $fold );
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( $days, $tz );

	return implode( ' ', array(
		"SELECT {$day_col} AS day,",
		'blob2 AS path,',
		$class . ' AS class,',
		'count(DISTINCT index1) AS pageview_visits',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( $days ) ),
		"WHERE timestamp >= {$lower}" . sn_analytics_window_upper( $tz ) . $cap,
		"AND blob1 = 'pv'",
		'GROUP BY day, path, class',
		'ORDER BY day DESC, pageview_visits DESC',
	) );
}

/**
 * Run the gated pageview_visits query, REFUSING a row-cap-truncated result.
 *
 * The merge (sn_analytics_rollup_merge_gated) treats a key missing from a
 * SUCCESSFUL gated result as a REAL 0 (empty is an answer) — sound only while
 * the gated set is COMPLETE. The two rollup queries order differently (views
 * DESC vs pageview_visits DESC), so AE's row cap can truncate them
 * ASYMMETRICALLY: a (day, path, class) present in the main set but cut from
 * the gated tail would be fabricated into a measured-0 pageview_visits. When
 * the client's verdict says truncated (v9.63.1: the result reached the applied
 * cap, rows >= SN_ANALYTICS_AE_ROW_CAP, AND rows_before_limit_at_least > rows
 * — see sn_analytics_last_result_truncated for the GROUP BY quirk), the WHOLE
 * gated result degrades to the FAILED shape (null → keys stay absent → the
 * upsert binds SQL NULL, "never measured") — degrade, don't corrupt; the next
 * roll self-corrects. Shared by the cron (sn_analytics_run_rollup) and the
 * owner-run reroll tool so both inherit the same fail-safe. Never silent: the
 * refusal is error_log'd.
 *
 * @param string $sql Gated query SQL (sn_analytics_rollup_gated_sql() output,
 *                    or the reroll tool's bounded-window transform of it).
 * @return array|null Rows, or null on transport failure OR truncation.
 */
function sn_analytics_rollup_gated_query( $sql ) {
	$rows = sn_analytics_query( $sql );
	if ( ! is_array( $rows ) ) {
		return null;
	}
	// function_exists is defence in depth for a half-wired install; in
	// production the loader requires inc/analytics-api.php before this module.
	if ( function_exists( 'sn_analytics_last_result_truncated' ) && sn_analytics_last_result_truncated() ) {
		error_log( '[sn-analytics] gated pageview_visits result truncated by the AE row cap (hit the applied cap with rows_before_limit_at_least > rows) — treated as failed; pageview_visits stays NULL, never a fabricated 0' );
		return null;
	}
	return $rows;
}

/**
 * Merge the gated second-query result into the main rollup rows.
 *
 * Null discipline (the realtime-zero-vs-null rule, both directions):
 *   - Gated query FAILED (non-array): pageview_visits stays ABSENT on every
 *     row → the upsert binds SQL NULL ("never measured") — a failure must
 *     never fabricate a 0.
 *   - Gated query SUCCEEDED: a (day, path, class) with no gated row had zero
 *     pageview-gated visitor-days — a REAL 0 (an empty result is an ANSWER;
 *     this is exactly the viewless srv-beacon class) — never null.
 *
 * Pure and immutable: returns a new array; the inputs are not mutated.
 *
 * @param array      $rows  Main rollup rows (day/path/class/... keys).
 * @param array|null $gated Gated query rows, or null/non-array on failure.
 * @return array Merged rows.
 */
function sn_analytics_rollup_merge_gated( $rows, $gated ) {
	if ( ! is_array( $rows ) ) {
		return array();
	}
	if ( ! is_array( $gated ) ) {
		return $rows; // gated query failed — leave pageview_visits absent (NULL), never 0.
	}

	$map = array();
	foreach ( $gated as $g ) {
		if ( is_array( $g ) && array_key_exists( 'pageview_visits', $g ) ) {
			$map[ sn_analytics_rollup_row_key( $g ) ] = $g['pageview_visits'];
		}
	}

	$merged = array();
	foreach ( $rows as $r ) {
		if ( is_array( $r ) ) {
			$key                   = sn_analytics_rollup_row_key( $r );
			$r['pageview_visits']  = array_key_exists( $key, $map ) ? $map[ $key ] : 0;
		}
		$merged[] = $r;
	}
	return $merged;
}

/**
 * The merge join key. Mirrors the upsert's normalization (class defaults to
 * 'human') so both queries' rows key identically.
 *
 * @param array $row AE row with day/path/class keys.
 * @return string
 */
function sn_analytics_rollup_row_key( $row ) {
	$class = isset( $row['class'] ) && '' !== (string) $row['class'] ? (string) $row['class'] : 'human';
	return trim( (string) ( $row['day'] ?? '' ) ) . '|' . (string) ( $row['path'] ?? '' ) . '|' . $class;
}

/**
 * Read one of the five v5 nullable metrics from an AE row, preserving the
 * absent/null/non-numeric ⇒ null ("never measured") distinction. Uses
 * array_key_exists — `??`/isset() cannot tell a present-but-null key from an
 * absent one and would silently conflate the two. Numeric strings (AE returns
 * UInt64 as JSON strings) pass through untouched; the caller casts per column.
 *
 * @param array  $row AE result row.
 * @param string $key Column key.
 * @return int|float|string|null Numeric value (possibly a numeric string), or null.
 */
function sn_analytics_rollup_nullable_num( $row, $key ) {
	if ( ! array_key_exists( $key, $row ) ) {
		return null;
	}
	$value = $row[ $key ];
	return ( null === $value || ! is_numeric( $value ) ) ? null : $value;
}

/**
 * The legacy scroll_avg/time_avg value for one row.
 *
 * When BOTH the weighted sum and the weighted event count are known, the
 * stored mean is the weighted ratio sum/events — identical to the transported
 * avgIf at sample interval 1 (no visible change) and correct under sampling,
 * where the unweighted avgIf is wrong. Zero events ⇒ the ratio is undefined
 * (null), which the NOT NULL legacy column stores as 0 — exactly what the old
 * `avgIf → null → ?? 0` path produced. When either weighted input is unknown
 * (legacy caller shape), the transported value passes through as before.
 *
 * @param int|float|string|null $sum         Weighted sum, or null.
 * @param int|float|string|null $events      Weighted event count, or null.
 * @param mixed                 $transported The row's transported avg (legacy fallback).
 * @return float Rounded to 2dp.
 */
function sn_analytics_rollup_legacy_avg( $sum, $events, $transported ) {
	if ( null !== $sum && null !== $events ) {
		$ratio = (float) $events > 0 ? (float) $sum / (float) $events : null; // guard ÷0 — null when no events.
		return round( null === $ratio ? 0.0 : $ratio, 2 );
	}
	return round( (float) ( is_numeric( $transported ) ? $transported : 0 ), 2 );
}

