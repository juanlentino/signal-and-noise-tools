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
