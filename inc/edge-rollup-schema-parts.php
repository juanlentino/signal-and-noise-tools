<?php
/**
 * Signal & Noise: edge-analytics rollup, the tables and the schedule (the two schema SQLs, the installer and its init hook, the one-shot resample repair and its hooks, the daily rollup hooks, SN_EDGE_ROLLUP_AT, the next-run time and the scheduler).
 *
 * Split from inc/edge-rollup.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** dbDelta CREATE for the exact daily totals (one row per day). */
function sn_edge_daily_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_EDGE_DAILY_TABLE;
	$charset = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
		cached_requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
		bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
		cached_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
		threats BIGINT UNSIGNED NOT NULL DEFAULT 0,
		page_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
		status_2xx BIGINT UNSIGNED NOT NULL DEFAULT 0,
		status_3xx BIGINT UNSIGNED NOT NULL DEFAULT 0,
		status_4xx BIGINT UNSIGNED NOT NULL DEFAULT 0,
		status_5xx BIGINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day (day)
	) {$charset};";
}

/** dbDelta CREATE for the breakdown rows (country / colo / threat). */
function sn_edge_dims_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$charset = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		dim VARCHAR(16) NOT NULL,
		value VARCHAR(160) NOT NULL,
		requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
		bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_dim_value (day, dim, value)
	) {$charset};";
}

function sn_edge_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_edge_daily_schema_sql() );
	dbDelta( sn_edge_dims_schema_sql() );
	update_option( SN_EDGE_DB_VERSION_OPT, SN_EDGE_DB_VERSION );
}

function sn_edge_maybe_install() {
	if ( get_option( SN_EDGE_DB_VERSION_OPT ) !== SN_EDGE_DB_VERSION ) {
		sn_edge_install();
	}
}
add_action( 'init', 'sn_edge_maybe_install' );

/**
 * 17.9.1: the one-shot repair of the sampled dims (threat, colo, atk_*, err_*),
 * which were counted twice over (see sn_edge_corrected()). Every day the
 * adaptive dataset still retains is re-rolled with the fixed arithmetic; the
 * upsert replaces a row's values, so the re-roll overwrites the inflated ones.
 * Days older than the retention cannot be recomputed (the per-row sample
 * intervals are gone), so they stay as stored and SN_EDGE_HONEST_FROM_OPT
 * records where the honest counts begin, rather than deleting history.
 * Scheduled once, from init, on the first request after the upgrade.
 */
function sn_edge_resample_repair() {
	if ( false !== get_option( SN_EDGE_HONEST_FROM_OPT, false ) || ! function_exists( 'sn_edge_config' ) || ! sn_edge_config() ) {
		return;
	}
	// 17.9.2: WP-Cron removes a single event from the queue BEFORE running it,
	// and a re-roll of a month takes longer than a minute, so the init hook
	// below saw "not done, nothing queued" mid-run and queued a second repair,
	// every minute until the first finished (measured 2026-09-23). The lock
	// is what the init hook reads; it expires, so a run that dies is retried.
	set_transient( SN_EDGE_RESAMPLE_LOCK, time(), 30 * MINUTE_IN_SECONDS );
	$days  = function_exists( 'sn_edge_adaptive_retention' ) ? (int) floor( (int) sn_edge_adaptive_retention() / DAY_IN_SECONDS ) : 0;
	$days  = max( 1, min( 31, $days ) );
	$today = strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' );
	for ( $d = 0; $d < $days; $d++ ) {
		sn_edge_run_rollup( gmdate( 'Y-m-d', $today - $d * DAY_IN_SECONDS ), true );
	}
	update_option( SN_EDGE_HONEST_FROM_OPT, gmdate( 'Y-m-d', $today - $days * DAY_IN_SECONDS ), false );
	delete_transient( SN_EDGE_RESAMPLE_LOCK );
}
add_action( SN_EDGE_RESAMPLE_HOOK, 'sn_edge_resample_repair' );
/**
 * Queue the repair once: not when it is done, not while one is running
 * (SN_EDGE_RESAMPLE_LOCK), not when one is already queued.
 *
 * @return bool Whether an event was queued.
 */
function sn_edge_resample_maybe_schedule() {
	if ( false !== get_option( SN_EDGE_HONEST_FROM_OPT, false ) || false !== get_transient( SN_EDGE_RESAMPLE_LOCK ) || ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( SN_EDGE_RESAMPLE_HOOK ) ) {
		return false;
	}
	wp_schedule_single_event( time() + 60, SN_EDGE_RESAMPLE_HOOK );
	return true;
}
add_action( 'init', 'sn_edge_resample_maybe_schedule' );

/** Daily cron: re-pull + upsert. WP passes no args → today defaults to now (UTC). */
add_action( SN_EDGE_ROLLUP_HOOK, 'sn_edge_run_rollup' );
add_action( 'init', 'sn_edge_maybe_schedule' );

const SN_EDGE_ROLLUP_AT = '01:15'; // UTC: the day just closed is stored about an hour after, so a break mails within ~2h, not ~21h.

/**
 * The next SN_EDGE_ROLLUP_AT (UTC) strictly after $now. PURE.
 *
 * @param int $now Unix time.
 * @return int
 */
function sn_edge_rollup_next_run( $now ) {
	$at = strtotime( gmdate( 'Y-m-d', (int) $now ) . ' ' . SN_EDGE_ROLLUP_AT . ':00 UTC' );
	return $at > (int) $now ? $at : $at + DAY_IN_SECONDS;
}

/**
 * Keep the daily rollup at SN_EDGE_ROLLUP_AT. It used to be scheduled at
 * whatever moment it was first seen unscheduled (20:43 UTC on this site), so
 * the hourly alert read a day's 5xx about 21 hours after the day closed. A run
 * at another time is moved once; when the move would leave yesterday unread
 * until tomorrow's run, one run now stores it.
 */
function sn_edge_maybe_schedule() {
	if ( ! function_exists( 'wp_get_scheduled_event' ) ) {
		return;
	}
	$event = wp_get_scheduled_event( SN_EDGE_ROLLUP_HOOK );
	$next  = $event ? (int) $event->timestamp : 0;
	// The soonest event is a one-off catch-up queued by the move below: leave it
	// and the daily alone, or every request in its minute would move again and
	// push the catch-up back (review on #1980).
	if ( $event && empty( $event->schedule ) ) {
		return;
	}
	if ( $next && SN_EDGE_ROLLUP_AT === gmdate( 'H:i', $next ) ) {
		return;
	}
	if ( $next ) {
		wp_clear_scheduled_hook( SN_EDGE_ROLLUP_HOOK );
		$read = function_exists( 'get_option' ) ? (array) get_option( SN_EDGE_ERRORS_READ_OPT, array() ) : array();
		if ( ! array_key_exists( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), $read ) ) {
			wp_schedule_single_event( time() + 60, SN_EDGE_ROLLUP_HOOK );
		}
	}
	wp_schedule_event( sn_edge_rollup_next_run( time() ), 'daily', SN_EDGE_ROLLUP_HOOK );
}

