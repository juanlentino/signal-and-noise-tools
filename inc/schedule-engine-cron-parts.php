<?php
/**
 * Signal & Noise Tools: schedule-engine.php, the fire and reconcile
 * callbacks, their hooks and the cron schedule.
 *
 * Split from inc/schedule-engine.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boundary fire handler: flip one schedule row across its window boundaries and
 * surgically purge the affected Cloudflare URLs. Registered on
 * SN_SCHEDULE_FIRE_HOOK; armed per-boundary by the save_post sync (Task 5) and
 * caught-up by the reconcile tick.
 *
 * State machine (status: queued -> active -> done; `error` is a retry holding
 * state, NOT a terminal one). $now is UTC unix via current_time('timestamp',
 * true) so tests can stub it:
 *
 *   - REVEAL (queued/error, with no start OR start already reached): the window
 *     has opened. Purge; on a dispatched purge advance to `active`.
 *   - HIDE (active/error/just-revealed, with an end that has been reached): the
 *     window has closed. Purge; on a dispatched purge advance to `done`.
 *   - BOTH boundaries already past on a single (late/missed) fire: advance all
 *     the way to `done` with exactly ONE purge. The net visible change is "now
 *     hidden", and the purge is by-URL (the same URL list either way), so a
 *     second purge would be redundant work for no additional invalidation.
 *
 * Purge / retry coupling: every transition calls sn_schedule_purge_urls with the
 * row's purge_urls. Because that wrapper is fire-and-forget, it returns FALSE
 * only when Cloudflare is unconfigured or the URL list is empty. On FALSE the
 * boundary is NOT advanced: the status is set to `error` and last_run is
 * stamped, so the row is held for a later re-fire / reconcile to retry (when
 * creds appear, or a re-arm re-runs it). On TRUE the status advances as above.
 * The error state is therefore mainly the unconfigured path plus the retry hook;
 * on a configured site the purge dispatches and the status advances.
 *
 * Persistence is by id via sn_schedule_update_status (NOT upsert): the handler
 * mutates an existing row, and empty-schedule_id rows would duplicate under
 * upsert. last_run is always stamped, even on the error path, so "last attempt"
 * is recorded.
 *
 * @param int $row_id The schedule row id (the cron event's payload).
 * @return void
 */
function sn_schedule_fire( $row_id ) {
	$row = sn_schedule_get( (int) $row_id );
	if ( null === $row ) {
		return; // row swept since the event was armed: nothing to do.
	}

	$now      = (int) current_time( 'timestamp', true ); // UTC unix; stubbable.
	$last_run = gmdate( 'Y-m-d H:i:s', $now );

	$start_ts = sn_schedule_boundary_ts( $row['starts_at'] ?? null );
	$end_ts   = sn_schedule_boundary_ts( $row['ends_at'] ?? null );
	$status   = (string) ( $row['status'] ?? 'queued' );

	// Has the reveal boundary been reached? A null start means "open from the
	// start of time", so a queued/error row with no start is already revealed.
	$reveal_due = ( null === $start_ts || $now >= $start_ts );
	// Has the hide boundary been reached? A null end never closes.
	$hide_due   = ( null !== $end_ts && $now >= $end_ts );

	// HIDE wins when its boundary has passed, regardless of the reveal step: the
	// net state is "now hidden". This single branch covers both the plain
	// active->done hide AND the both-boundaries-past missed-event case (a queued
	// row whose start AND end are both in the past advances straight to done),
	// firing exactly ONE purge for the one fire call. v7.3.0: purging goes
	// through sn_schedule_fire_purge (inc/schedule-cache.php) — snapshot ∪
	// current permalink for post fragments, zone escalation for reused
	// containers — same TRUE=dispatched / FALSE=retry contract.
	if ( $hide_due && in_array( $status, array( 'queued', 'active', 'error' ), true ) ) {
		if ( sn_schedule_fire_purge( $row ) ) {
			sn_schedule_update_status( (int) $row['id'], 'done', $last_run );
		} else {
			sn_schedule_update_status( (int) $row['id'], 'error', $last_run );
		}
		return;
	}

	// REVEAL: the window has opened but not yet closed. Only a not-yet-active row
	// (queued, or an error row retrying the reveal) transitions here.
	if ( $reveal_due && in_array( $status, array( 'queued', 'error' ), true ) ) {
		if ( sn_schedule_fire_purge( $row ) ) {
			sn_schedule_update_status( (int) $row['id'], 'active', $last_run );
		} else {
			sn_schedule_update_status( (int) $row['id'], 'error', $last_run );
		}
		return;
	}

	// No boundary is due for this row's current status (e.g. an event fired early,
	// or the row already reached its terminal state). Stamp last_run only, leaving
	// the status untouched, so the attempt is recorded without a spurious flip.
	sn_schedule_update_status( (int) $row['id'], $status, $last_run );
}
add_action( SN_SCHEDULE_FIRE_HOOK, 'sn_schedule_fire' );

/**
 * Reconcile tick: the safety net for boundaries WP-Cron dropped. Registered on
 * SN_SCHEDULE_RECONCILE_HOOK and scheduled every 5 minutes (see below). Two jobs:
 *
 *   1. CATCH UP. Scan every row; for any whose boundary has passed but whose
 *      status has not advanced to match, call sn_schedule_fire( id ) to run the
 *      missed transition NOW. The predicate is the inverse of the fire state
 *      machine's "is anything due":
 *        - a queued/error row whose start has passed (reveal overdue), OR
 *        - an active/error row whose end has passed (hide overdue), OR
 *        - a queued row whose end has passed (both-passed missed event).
 *      A `done` row is terminal and never re-fires; an `active` row with no end
 *      (or a future end) is correctly open and is left alone. fire() is itself
 *      idempotent on a caught-up row (its status no longer matches a due branch),
 *      so a second reconcile pass is a no-op.
 *
 *   2. RE-ARM. For any FUTURE non-null boundary with no currently-scheduled
 *      single event, re-arm it. This repairs a row whose event was dropped while
 *      still in the future (so catch-up would not fire it yet).
 *
 * @return void
 */
function sn_schedule_reconcile() {
	$now = (int) current_time( 'timestamp', true );

	foreach ( sn_schedule_all() as $row ) {
		$row_id   = (int) ( $row['id'] ?? 0 );
		if ( $row_id <= 0 ) {
			continue;
		}
		$status   = (string) ( $row['status'] ?? 'queued' );
		$start_ts = sn_schedule_boundary_ts( $row['starts_at'] ?? null );
		$end_ts   = sn_schedule_boundary_ts( $row['ends_at'] ?? null );

		// CATCH UP a missed boundary: is anything due that the status has not
		// advanced past? Mirrors the fire state machine's due-tests.
		$reveal_overdue = ( null !== $start_ts && $now >= $start_ts && in_array( $status, array( 'queued', 'error' ), true ) );
		$hide_overdue   = ( null !== $end_ts && $now >= $end_ts && in_array( $status, array( 'queued', 'active', 'error' ), true ) );
		if ( $reveal_overdue || $hide_overdue ) {
			sn_schedule_fire( $row_id );
		}

		// RE-ARM a future boundary that lost its single event. Only future,
		// non-null boundaries: a past boundary is handled by catch-up above, not
		// by arming an event that would fire immediately anyway.
		foreach ( array( $start_ts, $end_ts ) as $boundary_ts ) {
			if ( null === $boundary_ts || $boundary_ts <= $now ) {
				continue;
			}
			if ( ! wp_next_scheduled( SN_SCHEDULE_FIRE_HOOK, array( $row_id ) ) ) {
				wp_schedule_single_event( $boundary_ts, SN_SCHEDULE_FIRE_HOOK, array( $row_id ) );
			}
		}
	}
}
add_action( SN_SCHEDULE_RECONCILE_HOOK, 'sn_schedule_reconcile' );

/**
 * Defensively register the 5-minute cron recurrence this subsystem schedules the
 * reconcile tick on. inc/insights.php registers an identical
 * `sn_five_minutes` interval, but that module may not be loaded (it is opt-in),
 * so the reconcile MUST NOT depend on it: WP-Cron silently refuses to schedule an
 * event on an unknown recurrence. The `! isset` guard makes the two registrations
 * idempotent (whichever runs first wins, the other is a no-op), so they coexist
 * without a "schedule already registered" conflict.
 *
 * @param array $schedules Existing cron schedules.
 * @return array
 */
function sn_schedule_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['sn_five_minutes'] ) ) {
		$schedules['sn_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Signal & Noise)', 'signal-and-noise-tools' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'sn_schedule_cron_schedules' );

/**
 * Schedule the recurring reconcile tick, idempotently, on init. The
 * wp_next_scheduled guard prevents a second event from stacking on re-run. Like
 * sn_schedules_maybe_install above, this is dormant until the plugin bootstrap
 * require's this file (Task 9) and is stubbed in CLI tests.
 *
 * @return void
 */
function sn_schedule_reconcile_schedule() {
	if ( ! wp_next_scheduled( SN_SCHEDULE_RECONCILE_HOOK ) ) {
		wp_schedule_event( time(), 'sn_five_minutes', SN_SCHEDULE_RECONCILE_HOOK );
	}
}
add_action( 'init', 'sn_schedule_reconcile_schedule' );
