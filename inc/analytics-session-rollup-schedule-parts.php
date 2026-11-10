<?php
/**
 * Signal & Noise: when the nightly session rollup runs (SN_SESSION_ROLLUP_AT,
 * the next-run time, the day a move would skip, and the scheduler).
 *
 * Loaded by require_once from inc/analytics-session-rollup.php, which owns the
 * hook and the run.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// UTC. The run writes yesterday's UTC day once and never rewrites it, and the
// human class leaves out visitor-days over the page-view cap through a list
// cached for an hour. A visitor-day starts at New York midnight (the worker's
// SN_ROTATE_TZ, SN_ANALYTICS_SPLIT_TZ here): 05:00 UTC in winter, 04:00 in
// summer. 06:15 is after both plus the cache, so the list is final for the
// day written. 00:30 was not: a visitor-day still open could cross the cap
// after the run and stay stored as human. Pinned by tests/session-rollup-schedule.php.
const SN_SESSION_ROLLUP_AT        = '06:15';
const SN_SESSION_ROLLUP_BACKFILL_OPT = 'sn_session_rollup_backfill'; // {day, at}: the last day queued by sn_session_rollup_schedule(), so none is queued twice.

/**
 * The next SN_SESSION_ROLLUP_AT (UTC) strictly after $now. PURE.
 *
 * @param int $now Unix time.
 * @return int
 */
function sn_session_rollup_next_run( $now ) {
	$at = strtotime( gmdate( 'Y-m-d', (int) $now ) . ' ' . SN_SESSION_ROLLUP_AT . ':00 UTC' );
	return $at > (int) $now ? $at : $at + DAY_IN_SECONDS;
}

/**
 * The UTC day that moving the daily from $next to SN_SESSION_ROLLUP_AT would
 * leave unwritten, or ''. PURE. Each run writes the day before it fires: the
 * old run still owes the day before its next firing (now, if it is overdue),
 * and the new schedule starts with the day before its first run. At most one
 * day: yesterday, when the move lands after today's SN_SESSION_ROLLUP_AT and
 * before the old run fires.
 *
 * @param int $next The old event's next run, Unix time.
 * @param int $now  Unix time.
 * @return string Y-m-d or ''.
 */
function sn_session_rollup_skipped_day( $next, $now ) {
	$owed  = gmdate( 'Y-m-d', max( (int) $next, (int) $now ) - DAY_IN_SECONDS );
	$first = gmdate( 'Y-m-d', sn_session_rollup_next_run( $now ) - DAY_IN_SECONDS );
	return $owed < $first ? $owed : '';
}

/**
 * Keep the daily rollup at SN_SESSION_ROLLUP_AT. It used to be scheduled an
 * hour after it was first seen unscheduled (21:43 UTC on this site). A run at
 * another time is moved once. When the move would skip yesterday, or an absent
 * event (reactivation, a cleared event) is re-armed after today's run time,
 * one run for yesterday is queued with the day as its argument; the option
 * holds the day queued, so no day is queued twice. The run replaces a stored
 * day, so running it again is safe.
 *
 * @param int|null $now Unix time; empty reads the clock (init passes '').
 */
function sn_session_rollup_schedule( $now = null ) {
	if ( ! function_exists( 'wp_get_scheduled_event' ) ) {
		return;
	}
	$now   = $now ? (int) $now : time();
	$event = wp_get_scheduled_event( SN_SESSION_ROLLUP_HOOK );
	// A one-off with no arguments (a "Run now" from a cron tool) sorts before
	// the daily: leave both alone, or the clear below would drop it (as in
	// sn_edge_maybe_schedule(), review on #1980).
	if ( $event && empty( $event->schedule ) ) {
		return;
	}
	$next = $event ? (int) $event->timestamp : 0;
	if ( $next && SN_SESSION_ROLLUP_AT === gmdate( 'H:i', $next ) ) {
		return;
	}
	if ( $next ) {
		// Clears the daily only: a queued day carries an argument and stays.
		wp_clear_scheduled_hook( SN_SESSION_ROLLUP_HOOK );
	}
	// An absent event owes yesterday as well: it was re-armed an hour out,
	// which wrote yesterday; at 06:15 tomorrow it would not.
	$day  = sn_session_rollup_skipped_day( $next ? $next : $now, $now );
	$last = (array) get_option( SN_SESSION_ROLLUP_BACKFILL_OPT, array() );
	if ( '' !== $day && ( $last['day'] ?? '' ) !== $day ) {
		update_option( SN_SESSION_ROLLUP_BACKFILL_OPT, array( 'day' => $day, 'at' => $now ), false );
		wp_schedule_single_event( $now + MINUTE_IN_SECONDS, SN_SESSION_ROLLUP_HOOK, array( $day ) );
	}
	wp_schedule_event( sn_session_rollup_next_run( $now ), 'daily', SN_SESSION_ROLLUP_HOOK );
}
add_action( 'init', 'sn_session_rollup_schedule' );
