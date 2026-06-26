<?php
/**
 * Signal & Noise Tools: scheduled-content engine (table + row accessors).
 *
 * Phase 1 of the scheduled-content subsystem: a cache-coherent scheduler
 * that flips hand-authored content on/off on a date inside already-published
 * pages, purging only the affected Cloudflare URLs at each boundary.
 *
 * This file owns ONLY the queue table (wp_sn_schedules) plus its row
 * accessors. The render gate, the sn/scheduled dynamic block, the save_post
 * sync, the fire/purge handler, and the admin surface ship in later tasks
 * and reuse the column contract defined here.
 *
 * Schedule rows are addressed two ways:
 *   - schedule_id (a block uuid) for fragment rows mirrored from a post's
 *     sn/scheduled blocks. Upsert is idempotent on this key so a re-sync of
 *     the same block updates its one row rather than duplicating it.
 *   - empty schedule_id for table-canonical rows authored directly against
 *     the queue (page / theme_block / swap targets). Each such insert is its
 *     own row; they are never coalesced.
 *
 * Constrained columns (target_type, action, status) are VARCHAR, NOT MySQL
 * ENUM: dbDelta does not round-trip ENUM cleanly and would try to ALTER the
 * table on every install check (a known WP gotcha). Values are constrained at
 * the application layer in later tasks, not by the schema.
 *
 * Install pattern mirrors inc/analytics-events.php: constants, schema_sql,
 * install, maybe_install (version-gated on init). NOT yet required from the
 * plugin bootstrap; that wiring lands in the final task.
 *
 * @package SignalNoiseTools
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SCHEDULES_TABLE           = 'sn_schedules';
const SN_SCHEDULES_DB_VERSION      = '1';
const SN_SCHEDULES_DB_VERSION_OPT  = 'sn_schedules_db_version';

/**
 * The cron hook fired at each window boundary. The save_post sync (Task 5) arms
 * and clears events on this hook; the flip + Cloudflare purge handler (Task 6)
 * registers against it. It lives in this foundational file, required first in
 * the bootstrap, so the constant is defined before either consumer references
 * it: the sync module references it, and Task 6's fire handler (which ships in
 * THIS file) will add_action on it at file load. One source of truth for the
 * hook name; no second definition.
 */
const SN_SCHEDULE_FIRE_HOOK        = 'sn_schedule_fire';

/**
 * The recurring cron hook for the reconcile tick (Task 6). WP-Cron drops a
 * single boundary event on a quiet site (it only runs on a front-end request),
 * so a 5-minute reconcile sweeps the queue, catches up any boundary that should
 * have fired but did not, and re-arms any future boundary that has lost its
 * single event. The handler ships in THIS file and add_action's on the constant
 * at file load; one source of truth for the hook name.
 */
const SN_SCHEDULE_RECONCILE_HOOK   = 'sn_schedule_reconcile';

/**
 * dbDelta CREATE TABLE statement for wp_sn_schedules.
 *
 * Constrained columns use VARCHAR (not ENUM) so dbDelta is idempotent: a
 * re-run produces no diff and never ALTERs. Indexes: schedule_id powers the
 * upsert lookup; target_ref(191) powers delete_missing (the largest indexable
 * prefix of a TEXT column at utf8mb4, 191 * 4 = 764 < InnoDB's 767-byte legacy
 * limit, so it is safe even without the large-prefix setting).
 *
 * dbDelta formatting requirements honored here: two spaces after PRIMARY KEY,
 * one space between KEY and its name, lowercase column types, no trailing
 * commas. These keep the parser from mis-detecting a schema change.
 *
 * @return string The CREATE TABLE SQL.
 */
function sn_schedules_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_SCHEDULES_TABLE;
	$charset = $wpdb->get_charset_collate();

	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		schedule_id VARCHAR(40) NOT NULL DEFAULT '',
		target_type VARCHAR(20) NOT NULL DEFAULT 'fragment',
		target_ref TEXT NULL,
		action VARCHAR(20) NOT NULL DEFAULT 'reveal',
		starts_at DATETIME NULL,
		ends_at DATETIME NULL,
		recurrence VARCHAR(40) NULL,
		payload LONGTEXT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'queued',
		last_run DATETIME NULL,
		purge_urls LONGTEXT NULL,
		updated DATETIME NULL,
		PRIMARY KEY  (id),
		KEY schedule_id (schedule_id),
		KEY target_ref (target_ref(191))
	) {$charset};";
}

/**
 * Create the table via dbDelta. Brand-new dormant table, no migration path.
 */
function sn_schedules_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_schedules_schema_sql() );
	update_option( SN_SCHEDULES_DB_VERSION_OPT, SN_SCHEDULES_DB_VERSION );
}

/**
 * One autoloaded-option compare per request; the install runs only on the
 * version delta. Mirrors sn_analytics_events_maybe_install().
 */
function sn_schedules_maybe_install() {
	if ( get_option( SN_SCHEDULES_DB_VERSION_OPT ) !== SN_SCHEDULES_DB_VERSION ) {
		sn_schedules_install();
	}
}
// Installed/version-gated on init once required from the plugin bootstrap
// (wiring lands in the final task of this subsystem).
add_action( 'init', 'sn_schedules_maybe_install' );

/**
 * The persisted column set, minus id (auto) and updated (stamped on write).
 * Used to normalize an arbitrary input row down to known columns.
 *
 * @return array<int, string>
 */
function sn_schedules_columns() {
	return array(
		'schedule_id',
		'target_type',
		'target_ref',
		'action',
		'starts_at',
		'ends_at',
		'recurrence',
		'payload',
		'status',
		'last_run',
		'purge_urls',
	);
}

/**
 * Reduce an arbitrary input row to the known persistable columns, applying
 * the schema defaults for the NOT-NULL constrained columns when absent.
 * NULL-able columns are only set when the caller provided them.
 *
 * @param array $row Caller-supplied row.
 * @return array<string, mixed> Column => value, ready for insert/update.
 */
function sn_schedules_normalize_row( array $row ) {
	$defaults = array(
		'schedule_id' => '',
		'target_type' => 'fragment',
		'action'      => 'reveal',
		'status'      => 'queued',
	);

	$out = array();
	foreach ( sn_schedules_columns() as $col ) {
		if ( array_key_exists( $col, $row ) ) {
			$out[ $col ] = $row[ $col ];
		} elseif ( array_key_exists( $col, $defaults ) ) {
			$out[ $col ] = $defaults[ $col ];
		}
	}
	return $out;
}

/**
 * Insert a schedule row, OR update the existing row that carries the same
 * non-empty schedule_id AND the same target_ref (idempotent on the
 * (schedule_id, target_ref) PAIR: the same scheduleId on the SAME post is ONE
 * row). An empty schedule_id is table-canonical and always inserts a fresh row.
 *
 * Why the PAIR, not schedule_id alone: a Scheduled block copied into a DIFFERENT
 * post carries the same scheduleId. Keying idempotency on schedule_id alone would
 * make the second post's save_post upsert find (and overwrite the target_ref /
 * purge_urls of) the FIRST post's row, so the first post would silently lose its
 * surgical purge. Adding target_ref to the match makes the same scheduleId under
 * two posts resolve to two distinct rows. The existing schedule_id + target_ref(191)
 * indexes both cover this lookup; no composite index or schema bump is needed.
 *
 * @param array $row Row data keyed by column name. Unknown keys are dropped.
 * @return int The row id (inserted or the existing one updated). Returns 0 on
 *             insert failure (a falsy $wpdb->insert_id).
 */
function sn_schedule_upsert( array $row ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_SCHEDULES_TABLE;

	$data                = sn_schedules_normalize_row( $row );
	$data['updated']     = gmdate( 'Y-m-d H:i:s' );
	$schedule_id         = isset( $data['schedule_id'] ) ? (string) $data['schedule_id'] : '';
	$target_ref          = isset( $data['target_ref'] ) ? (string) $data['target_ref'] : '';

	// Non-empty schedule_id: update in place if a row with the same
	// (schedule_id, target_ref) already exists. The pair scopes idempotency to a
	// single post, so the same scheduleId on another post never clobbers this one.
	if ( '' !== $schedule_id ) {
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE schedule_id = %s AND target_ref = %s LIMIT 1",
			$schedule_id,
			$target_ref
		) );

		if ( null !== $existing_id ) {
			$existing_id = (int) $existing_id;
			$wpdb->update(
				$table,
				$data,
				array( 'id' => $existing_id )
			);
			return $existing_id;
		}
	}

	$wpdb->insert( $table, $data );
	return (int) $wpdb->insert_id;
}

/**
 * Update the status (and stamp last_run) of one row, addressed by id.
 *
 * Why a by-id update and not sn_schedule_upsert: upsert keys on schedule_id, and
 * an empty-schedule_id row (a table-canonical page / theme_block / swap row)
 * ALWAYS inserts a fresh row, so feeding such a row back through upsert to flip
 * its status would duplicate it rather than mutate it. The fire handler advances
 * the status of an EXISTING row of either kind, so it must address the row by its
 * stable id. This is the focused, prepare()-bound write the fire/reconcile path
 * uses; no raw SQL leaks into the handler.
 *
 * last_run is always written alongside the status so "when did this row last
 * fire" is one atomic update, never a status change with a stale last_run.
 *
 * @param int    $id       Row id.
 * @param string $status   New status (queued|active|done|error).
 * @param string $last_run UTC datetime 'Y-m-d H:i:s' to stamp as last_run.
 * @return bool True when a row was updated, false otherwise.
 */
function sn_schedule_update_status( $id, $status, $last_run ) {
	$id = (int) $id;
	if ( $id <= 0 ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . SN_SCHEDULES_TABLE;

	$updated = $wpdb->update(
		$table,
		array(
			'status'   => (string) $status,
			'last_run' => (string) $last_run,
			'updated'  => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'id' => $id )
	);

	return is_int( $updated ) && $updated > 0;
}

/**
 * Fetch one schedule row by id.
 *
 * @param int $id Row id.
 * @return array<string, mixed>|null The row as an assoc array, or null if absent.
 */
function sn_schedule_get( $id ) {
	$id = (int) $id;
	if ( $id <= 0 ) {
		return null;
	}

	global $wpdb;
	$table = $wpdb->prefix . SN_SCHEDULES_TABLE;

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
		$id
	), ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * Fetch every schedule row, ordered by id ascending.
 *
 * @return array<int, array<string, mixed>> All rows (possibly empty).
 */
function sn_schedule_all() {
	global $wpdb;
	$table = $wpdb->prefix . SN_SCHEDULES_TABLE;

	// Bare interpolation: $table is a constant and there are no values to bind.
	// Matches inc/cron-history.php:246; do NOT wrap in $wpdb->prepare() (WP 6.2+
	// flags a placeholder-free prepare() via _doing_it_wrong).
	$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Delete one schedule row by id.
 *
 * @param int $id Row id.
 * @return bool True when a row was deleted, false otherwise.
 */
function sn_schedule_delete( $id ) {
	$id = (int) $id;
	if ( $id <= 0 ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . SN_SCHEDULES_TABLE;

	$deleted = $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE id = %d",
		$id
	) );

	return is_int( $deleted ) && $deleted > 0;
}

/**
 * For a fragment-bearing post, delete the fragment rows whose schedule_id is
 * NOT in $keep_ids. Fragment rows store the post id in target_ref, so the
 * filter is target_type='fragment' AND target_ref=$post_id. When $keep_ids is
 * empty, every fragment row for that post is deleted.
 *
 * This is the save_post reconciliation primitive: after mirroring a post's
 * live sn/scheduled blocks into the queue, the rows for blocks that no longer
 * exist (or were removed) are swept away.
 *
 * @param int|string $post_id  The post id stored in target_ref.
 * @param array      $keep_ids schedule_id values to preserve. Empty = delete all.
 * @return int Count of rows deleted.
 */
function sn_schedule_delete_missing( $post_id, array $keep_ids ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_SCHEDULES_TABLE;

	$post_ref = (string) $post_id;

	// Normalize the keep list to unique, non-empty strings. An empty input, or
	// one whose entries were all empty, collapses to the purge-all path.
	$keep = array();
	foreach ( $keep_ids as $kid ) {
		$kid = (string) $kid;
		if ( '' !== $kid ) {
			$keep[ $kid ] = true;
		}
	}
	$keep = array_keys( $keep );

	// Empty keep set: delete every fragment row for this post (one query path).
	if ( empty( $keep ) ) {
		return sn_schedule_delete_all_fragments( $post_ref );
	}

	// Build the NOT IN (...) placeholder list. Every value is bound via
	// prepare; the placeholders are generated from the array length only.
	$placeholders = implode( ', ', array_fill( 0, count( $keep ), '%s' ) );
	$args         = array_merge( array( 'fragment', $post_ref ), $keep );

	$deleted = $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE target_type = %s AND target_ref = %s AND schedule_id NOT IN ( {$placeholders} )",
		$args
	) );

	return is_int( $deleted ) ? $deleted : 0;
}

/**
 * Delete every fragment row for a post (the empty-keep-set path of
 * sn_schedule_delete_missing). Factored out so there is exactly one copy of
 * this query.
 *
 * @param string $post_ref The post id stored in target_ref.
 * @return int Count of rows deleted.
 */
function sn_schedule_delete_all_fragments( $post_ref ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_SCHEDULES_TABLE;

	$deleted = $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE target_type = %s AND target_ref = %s",
		'fragment',
		(string) $post_ref
	) );

	return is_int( $deleted ) ? $deleted : 0;
}

/**
 * The pure window gate for a scheduled fragment: is the half-open interval
 * [from, until) open at the instant $now_utc?
 *
 * This is a side-effect-free predicate (no DB, no WP calls, no globals) so it
 * is independently testable and reusable by the render gate (Task 3), the
 * fire/purge handler, and the admin surface. The render callback asks this one
 * question to decide whether to emit a fragment's content.
 *
 * Semantics: a HALF-OPEN interval. The start is INCLUSIVE and the end is
 * EXCLUSIVE, so a fragment that opens at T and a fragment that closes at T do
 * not both claim the instant T. Open iff:
 *   ( from is null/empty OR now_utc >= from_ts ) AND
 *   ( until is null/empty OR now_utc <  until_ts ).
 * An absent boundary is unbounded on that side: a null/empty $from opens from
 * the start of time, a null/empty $until never closes, and both absent is
 * always open.
 *
 * Timezone safety: $from / $until are stored as UTC datetime strings (the
 * table's DATETIME columns hold UTC), so they are parsed EXPLICITLY as UTC via
 * strtotime( $s . ' UTC' ). A bare strtotime( $s ) would parse against the
 * server's default timezone and shift the boundary by that offset, which is
 * why it is avoided here.
 *
 * Unparseable-boundary policy (fail safe): a non-empty $from that cannot be
 * parsed is treated as "not yet open" (the gate stays CLOSED) so a malformed
 * start can never leak content early. A non-empty $until that cannot be parsed
 * is treated as "no end" (unbounded), matching the null/empty case so a
 * malformed end does not accidentally hide already-open content.
 *
 * @param string|null $from    UTC datetime in MySQL `Y-m-d H:i:s` form, or null,
 *                             or '' (empty string is treated the same as null).
 * @param string|null $until   UTC datetime in MySQL `Y-m-d H:i:s` form, or null,
 *                             or '' (empty string is treated the same as null).
 * @param int         $now_utc The current instant as a Unix timestamp (UTC seconds).
 * @return bool True when the window is open at $now_utc, false otherwise.
 */
function sn_schedule_is_open( $from, $until, $now_utc ) {
	$now_utc = (int) $now_utc;

	// Start boundary: unbounded when null/empty. When a non-empty value fails
	// to parse, fail safe to CLOSED (treat as a start that has not arrived).
	$from = (string) $from;
	if ( '' !== $from ) {
		$from_ts = strtotime( $from . ' UTC' );
		if ( false === $from_ts || $now_utc < $from_ts ) {
			return false;
		}
	}

	// End boundary: unbounded when null/empty. When a non-empty value fails to
	// parse, treat it as no end (unbounded), so it does not gate the window.
	$until = (string) $until;
	if ( '' !== $until ) {
		$until_ts = strtotime( $until . ' UTC' );
		if ( false !== $until_ts && $now_utc >= $until_ts ) {
			return false;
		}
	}

	return true;
}

/**
 * Parse a stored UTC DATETIME boundary string into a Unix timestamp, or null.
 *
 * The columns hold UTC, so the value is parsed EXPLICITLY as UTC
 * (strtotime( $s . ' UTC' )) to match how sync wrote it and how the gate reads
 * it. A null / empty / unparseable boundary returns null (an unbounded side).
 *
 * @param mixed $value The stored starts_at / ends_at value.
 * @return int|null Unix timestamp (UTC seconds), or null when absent/unparseable.
 */
function sn_schedule_boundary_ts( $value ) {
	$value = (string) $value;
	if ( '' === $value ) {
		return null;
	}
	$ts = strtotime( $value . ' UTC' );
	return false === $ts ? null : $ts;
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

	$urls = (array) json_decode( (string) ( $row['purge_urls'] ?? '' ), true );

	// Has the reveal boundary been reached? A null start means "open from the
	// start of time", so a queued/error row with no start is already revealed.
	$reveal_due = ( null === $start_ts || $now >= $start_ts );
	// Has the hide boundary been reached? A null end never closes.
	$hide_due   = ( null !== $end_ts && $now >= $end_ts );

	// HIDE wins when its boundary has passed, regardless of the reveal step: the
	// net state is "now hidden". This single branch covers both the plain
	// active->done hide AND the both-boundaries-past missed-event case (a queued
	// row whose start AND end are both in the past advances straight to done),
	// firing exactly ONE purge for the one fire call.
	if ( $hide_due && in_array( $status, array( 'queued', 'active', 'error' ), true ) ) {
		if ( sn_schedule_purge_urls( $urls ) ) {
			sn_schedule_update_status( (int) $row['id'], 'done', $last_run );
		} else {
			sn_schedule_update_status( (int) $row['id'], 'error', $last_run );
		}
		return;
	}

	// REVEAL: the window has opened but not yet closed. Only a not-yet-active row
	// (queued, or an error row retrying the reveal) transitions here.
	if ( $reveal_due && in_array( $status, array( 'queued', 'error' ), true ) ) {
		if ( sn_schedule_purge_urls( $urls ) ) {
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
 * reconcile tick on. inc/uptime-heartbeat.php registers an identical
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
			'display'  => __( 'Every 5 minutes (Signal & Noise)', 'signal-noise-tools' ),
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
