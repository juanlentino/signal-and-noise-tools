<?php
/**
 * Signal & Noise — first-party analytics "visitors now" realtime tier (P2).
 *
 * The ephemeral counterpart to inc/analytics-rollup.php's durable daily table:
 * per-class "current visitors" counts (human / suspect / bot) — distinct
 * visitor-day hashes seen in the last few minutes — read from a short-lived
 * transient that an admin_init SWR warmer keeps ~30s fresh via non-blocking
 * background single-events. Mirrors the retired Plausible client's realtime-single-events pattern.
 *
 * No table and no recurring cron: a "now" number is only meaningful while
 * someone is looking at the dashboard, so the warmer schedules single events
 * on-demand. Single-event-only also sidesteps the warmer-vs-recurring hook
 * collision that the rollup layer has to split two hooks to avoid.
 *
 * Dormant until AE creds are configured (sn_analytics_query() → null). The
 * accessor never makes a network call; the render path reads only the transient.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_REALTIME_KEY        = 'sn_analytics_realtime';
const SN_ANALYTICS_REALTIME_TTL        = 30;                     // freshness target (seconds)
const SN_ANALYTICS_REALTIME_RETENTION  = 5 * MINUTE_IN_SECONDS;  // stale value survives an API blip
const SN_ANALYTICS_REALTIME_WINDOW_MIN = 5;                      // "now" = a visitor active in the last N minutes
const SN_ANALYTICS_REALTIME_HOOK       = 'sn_analytics_realtime_refresh';

/**
 * Build the AE SQL for the current-visitors count per traffic class: distinct
 * visitor-day hashes with any event in the trailing window, grouped by the
 * blob7 class column (human / suspect / bot). The window is an internal integer
 * constant (cast + floored as defence in depth); no user input is interpolated.
 *
 * @return string AE SQL.
 */
function sn_analytics_realtime_sql() {
	$mins = max( 1, (int) SN_ANALYTICS_REALTIME_WINDOW_MIN );

	return implode( ' ', array(
		'SELECT blob7 AS class, count(DISTINCT index1) AS visitors',
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE timestamp >= now() - INTERVAL '{$mins}' MINUTE",
		'GROUP BY class',
	) );
}

/**
 * Read-only accessor: the last cached "visitors now" count for a given traffic
 * class. Defaults to 'human'. Returns null only when the transient has never
 * been written (unwarmed / unconfigured) — a warmed class with zero hits
 * returns 0 (int), and a class absent from the per-class map also returns 0.
 * Never makes a network call.
 *
 * Cache shape: { counts: { class => int }, fetched: int }
 *
 * @param string $class Traffic class to read ('human', 'bot', 'suspect', …).
 * @return int|null
 */
function sn_analytics_realtime( $class = 'human' ) {
	$cached = get_transient( SN_ANALYTICS_REALTIME_KEY );
	if ( ! is_array( $cached ) || ! isset( $cached['counts'] ) || ! is_array( $cached['counts'] ) ) {
		return null;
	}
	$counts = $cached['counts'];
	if ( isset( $counts[ $class ] ) && is_int( $counts[ $class ] ) ) {
		return $counts[ $class ];
	}
	// Warmed, but this class had no hits in the window → a real 0.
	return 0;
}

/**
 * Cron callback: query AE for per-class visitor counts and cache them. Only
 * writes on a successful, well-shaped result — a null / transport failure
 * leaves any prior counts to age out via retention rather than poisoning the
 * transient. Rows missing both 'class' and 'visitors' keys are silently
 * skipped; if no well-shaped rows exist the transient is not written.
 *
 * Cache shape written: { counts: { class => int }, fetched: int }
 */
function sn_analytics_realtime_refresh() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return;
	}
	if ( ! sn_analytics_config() ) {
		return;
	}

	$rows = sn_analytics_query( sn_analytics_realtime_sql() );
	if ( ! is_array( $rows ) ) {
		return; // transport / non-200 / parse failure — keep prior counts
	}

	$counts = array();
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && isset( $row['class'], $row['visitors'] ) ) {
			$counts[ (string) $row['class'] ] = max( 0, (int) $row['visitors'] );
		}
	}
	if ( empty( $counts ) ) {
		return; // nothing well-shaped — don't poison the transient
	}

	set_transient( SN_ANALYTICS_REALTIME_KEY, array(
		'counts'  => $counts,
		'fetched' => time(),
	), SN_ANALYTICS_REALTIME_RETENTION );
}
add_action( SN_ANALYTICS_REALTIME_HOOK, 'sn_analytics_realtime_refresh' );

/**
 * Admin warmer: schedule a non-blocking background refresh when the cached
 * count is older than the 30s freshness target. Capability-gated, configured-
 * gated, and single-event-only (no recurring backstop — nobody needs a fresh
 * "now" number when no admin is watching). wp_next_scheduled() prevents
 * stacking; single events clear after firing, so the warmer can re-fire.
 */
function sn_analytics_realtime_warm() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return;
	}

	$cached = get_transient( SN_ANALYTICS_REALTIME_KEY );
	$age    = ( is_array( $cached ) && isset( $cached['fetched'] ) )
		? ( time() - (int) $cached['fetched'] )
		: PHP_INT_MAX;

	if ( $age > SN_ANALYTICS_REALTIME_TTL && ! wp_next_scheduled( SN_ANALYTICS_REALTIME_HOOK ) ) {
		wp_schedule_single_event( time(), SN_ANALYTICS_REALTIME_HOOK );
	}
}
add_action( 'admin_init', 'sn_analytics_realtime_warm', 5 );
