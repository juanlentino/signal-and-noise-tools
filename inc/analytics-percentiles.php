<?php
/**
 * Signal & Noise — on-demand scroll/time percentiles (p50/p75/p90).
 *
 * A companion to inc/analytics-buckets.php. Where buckets stores per-day band
 * COUNTS (additive — SUM at read time), percentiles are ORDER STATISTICS: the
 * p90 of a window is not any function of daily p90s, so the store-daily-SUM
 * pattern is invalid here. Instead we query Cloudflare AE on demand for the
 * EXACT resolved [from,to] window, sample-weighted via quantileExactWeighted,
 * and cache the three-stat result in a short transient. This honors v6.7.0's
 * arbitrary custom ranges + the page's class filter for free (both are just
 * WHERE clauses on one query) and needs no table, no dbDelta, no rollup.
 *
 * AE specifics (CF docs-confirmed; live-validated before tag):
 *   - quantileExactWeighted(q)(value, weight) — PARAMETRIC level, value first,
 *     weight (_sample_interval) second. One scalar per call → three SELECT cols.
 *   - timestamp bounded by explicit toDateTime('YYYY-MM-DD ...') literals (NOT a
 *     trailing INTERVAL, which would mis-handle past-ending presets like "last
 *     quarter").
 *
 * Both forms are NEW to this codebase (the v5.2.0/v5.3.0 422 lesson) — a failed
 * query returns null and the panel shows an empty-state, never a fatal.
 *
 * @package SignalNoiseTools
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The percentile metrics: source event + double column + label + display format.
 * Single source of truth for the SQL builder, the accessor, and the engagement
 * wiring. format: 'pct' (integer %) | 'time' (ms → snt_analytics_fmt_time).
 *
 * @return array<string, array{event:string, col:string, label:string, format:string}>
 */
function sn_analytics_percentiles_metrics() {
	return array(
		'scroll' => array( 'event' => 'sc', 'col' => 'double1', 'label' => 'Scroll depth', 'format' => 'pct' ),
		'time'   => array( 'event' => 'tm', 'col' => 'double2', 'label' => 'Time on page', 'format' => 'time' ),
	);
}

/**
 * AE SQL: p50/p75/p90 for one metric over the explicit [from,to] window, weighted
 * by _sample_interval. quantileExactWeighted(q)(value, weight) is parametric —
 * one scalar per call, so three SELECT columns. $event/$col are regex-sanitised,
 * $class is allowlisted, $from/$to are re-validated as YYYY-MM-DD (defence in depth;
 * the accessor validates too) — all interpolated into SQL string literals.
 *
 * @param string $event Event filter ('sc'|'tm').
 * @param string $col   Double column ('double1'|'double2').
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class.
 * @return string AE SQL.
 */
function sn_analytics_percentiles_sql( $event, $col, $from, $to, $class ) {
	$event = preg_replace( '/[^a-z]/', '', (string) $event );
	$col   = preg_replace( '/[^a-z0-9]/', '', (string) $col );
	$class = in_array( $class, SN_ANALYTICS_CLASSES, true ) ? $class : 'human';
	$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? (string) $from : '1970-01-01';
	$to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? (string) $to : '1970-01-01';

	return implode( ' ', array(
		'SELECT',
		"quantileExactWeighted(0.5)({$col}, _sample_interval) AS p50,",
		"quantileExactWeighted(0.75)({$col}, _sample_interval) AS p75,",
		"quantileExactWeighted(0.9)({$col}, _sample_interval) AS p90",
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE blob1 = '{$event}' AND blob7 = '{$class}'",
		"AND timestamp >= toDateTime('{$from} 00:00:00')",
		"AND timestamp <= toDateTime('{$to} 23:59:59')",
	) );
}
