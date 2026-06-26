<?php
/**
 * Signal & Noise Tools: scheduled-content save_post sync.
 *
 * Task 5 of the scheduled-content subsystem: the bridge from the editor to the
 * queue. When a post is saved, its live signal-noise/scheduled blocks are
 * mirrored into wp_sn_schedules (one fragment row per block, keyed on the
 * block's scheduleId), rows for blocks that were removed are swept, and the
 * boundary cron events are (re)armed so the fire/purge handler (Task 6) wakes at
 * each window edge.
 *
 * This file SCHEDULES the boundary events; it does NOT fire or purge. The
 * sn_schedule_fire handler that flips a row + purges its Cloudflare URLs lands
 * in Task 6. Here we only translate "what the post says" into "what the queue
 * holds" and "when WP-Cron should wake".
 *
 * Cache-coherence note: the render gate (Task 3) is what actually reveals or
 * withholds a fragment on each un-cached render, so the queue rows are NOT the
 * thing that gates content. The rows exist so the boundary purge can fire a
 * surgical Cloudflare purge at the exact instant a window opens or closes,
 * rather than waiting for a TTL. A missing or stale row never leaks content (the
 * gate still runs); it only means a purge might be late, which Task 6's
 * reconcile tick repairs.
 *
 * Dormant-until-wired pattern: the save_post add_action at the foot of this file
 * is skipped under SN_SCHEDULE_SYNC_TEST so the contract test can require the
 * module without registering a live hook against a stubbed add_action. The hook
 * goes live only when the plugin bootstrap require's this file (Task 9), the
 * same pattern inc/schedule-block.php uses for its init registration.
 *
 * @package SignalNoiseTools
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The cron hook this module arms / clears, SN_SCHEDULE_FIRE_HOOK, is defined in
// inc/schedule-engine.php (the foundational file, required before this one in
// the bootstrap). It lives there because Task 6's fire handler also ships in
// schedule-engine.php and add_action's on the constant at file load, BEFORE
// this module is required; defining it here would leave that earlier reference
// undefined (a PHP 8 fatal). One source of truth, next to the fire handler.

/**
 * Post statuses for which mirroring makes no sense. An auto-draft is an empty
 * placeholder WP creates before the editor has authored anything; trash is a
 * soft-deleted post; inherit is the status of revisions/attachments (revisions
 * are already guarded, but inherit is bailed here too for belt-and-braces). For
 * any of these we return before touching the queue or cron.
 *
 * @return array<int, string>
 */
function sn_schedule_skip_statuses() {
	return array( 'auto-draft', 'trash', 'inherit' );
}

/**
 * save_post handler: mirror a post's signal-noise/scheduled blocks into the
 * queue and (re)arm their boundary cron events.
 *
 * Guards (conservative, each an early return):
 *   - autosave  : wp_is_post_autosave(). An autosave is not an authored save;
 *                 acting on it would churn the queue on every keystroke-driven
 *                 autosave tick.
 *   - revision  : wp_is_post_revision(). A revision is a historical copy, not
 *                 the live post; its blocks must not register their own rows.
 *   - capability: ! current_user_can( 'edit_post', $post_id ). save_post can
 *                 fire for a user without edit rights (e.g. a crafted REST
 *                 write); a queue mutation is an edit, so it is gated on the
 *                 same cap the editor enforces.
 *   - status    : auto-draft / trash / inherit (see sn_schedule_skip_statuses()).
 *
 * The handler is hooked with 2 args so $post is available without a second
 * get_post() round-trip; $post->post_content is the source the blocks are
 * parsed from and $post->post_status drives the status guard.
 *
 * @param int          $post_id The post being saved.
 * @param WP_Post|null $post    The post object (save_post's 2nd arg). When
 *                              absent (a caller firing the hook with one arg),
 *                              we fetch it so the handler is robust.
 * @return void
 */
function sn_schedule_sync_post( $post_id, $post = null ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}

	// Guard: autosave + revision. Neither represents an authored, live save.
	if ( wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	// Guard: capability. A queue mutation is an edit; gate it on edit_post.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Resolve the post object if the hook fired with one arg.
	if ( ! is_object( $post ) ) {
		$post = get_post( $post_id );
	}
	if ( ! is_object( $post ) ) {
		return;
	}

	// Guard: skip statuses where mirroring is meaningless.
	$status = isset( $post->post_status ) ? (string) $post->post_status : '';
	if ( in_array( $status, sn_schedule_skip_statuses(), true ) ) {
		return;
	}

	// Collect every signal-noise/scheduled block, recursing into wrappers so a
	// scheduled block nested inside a group/columns is still mirrored.
	$content = isset( $post->post_content ) ? (string) $post->post_content : '';
	$blocks  = sn_schedule_collect_scheduled_blocks( parse_blocks( $content ) );

	// Upsert one row per block that carries a (non-empty) scheduleId. An empty
	// scheduleId means the block has not been initialized by the editor yet
	// (Task 4 stamps a uuid on insert); mirroring it would create an anonymous,
	// un-addressable row that no future re-sync could match or sweep, so it is
	// SKIPPED here. The kept scheduleIds become the delete_missing keep-list.
	$keep_ids   = array();
	$permalink  = (string) get_permalink( $post_id );
	$purge_urls = wp_json_encode( array( $permalink ) );

	foreach ( $blocks as $block ) {
		$attrs       = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$schedule_id = isset( $attrs['scheduleId'] ) ? (string) $attrs['scheduleId'] : '';
		if ( '' === $schedule_id ) {
			continue; // uninitialized block: no anonymous row.
		}

		$starts_at = sn_schedule_normalize_boundary( $attrs['from'] ?? '' );
		$ends_at   = sn_schedule_normalize_boundary( $attrs['until'] ?? '' );

		sn_schedule_upsert( array(
			'schedule_id' => $schedule_id,
			'target_type' => 'fragment',
			'target_ref'  => (string) $post_id,
			'action'      => 'reveal',
			'starts_at'   => $starts_at,
			'ends_at'     => $ends_at,
			'purge_urls'  => $purge_urls,
			'status'      => 'queued',
		) );

		$keep_ids[] = $schedule_id;
	}

	// BEFORE sweeping removed rows, clear the cron events of every fragment row
	// for this post that is ABOUT to be dropped (its scheduleId is not in the
	// keep-list). delete_missing deletes by WHERE clause, not by id, so it can
	// not clear the events itself; the row ids must be read here while the rows
	// still exist. This makes a date-edit-then-remove re-arm idempotent: the
	// removed row leaves no orphaned cron event behind.
	sn_schedule_clear_removed_crons( $post_id, $keep_ids );

	// Sweep rows for blocks that no longer exist in the post.
	sn_schedule_delete_missing( $post_id, $keep_ids );

	// (Re)arm the boundary cron for every surviving fragment row. Reading the
	// rows back from the queue (rather than from $blocks) gives us the row id
	// (the cron event's payload) and the canonical normalized datetimes.
	sn_schedule_arm_post_crons( $post_id );
}

/**
 * Recursively collect every signal-noise/scheduled block from a parse_blocks
 * tree, descending into each block's innerBlocks so a scheduled block nested
 * inside a group/columns/any wrapper is found.
 *
 * Order is a depth-first pre-order walk, but callers must not depend on the
 * order: the rows are keyed on scheduleId, not position.
 *
 * @param array<int, array<string, mixed>> $blocks A parse_blocks node list.
 * @return array<int, array<string, mixed>> The flat list of scheduled blocks.
 */
function sn_schedule_collect_scheduled_blocks( array $blocks ) {
	$found = array();
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
		if ( 'signal-noise/scheduled' === $name ) {
			$found[] = $block;
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$found = array_merge( $found, sn_schedule_collect_scheduled_blocks( $block['innerBlocks'] ) );
		}
	}
	return $found;
}

/**
 * Normalize a block's wall-clock boundary attribute to a canonical MySQL UTC
 * DATETIME string for the queue's starts_at / ends_at columns.
 *
 * The editor stores from/until as an ISO-ish wall-clock value (e.g.
 * '2026-07-01T00:00:00'), interpreted as UTC. We trim it, then parse it
 * EXPLICITLY as UTC via strtotime( $value . ' UTC' ) and re-emit it with
 * gmdate( 'Y-m-d H:i:s' ). This is the EXACT inverse of how the render gate
 * (sn_schedule_is_open) reads the column back: it parses the stored string with
 * strtotime( $s . ' UTC' ) too, so sync and gate agree on the same instant.
 *
 * An empty / whitespace-only / unparseable value normalizes to NULL (the
 * column's unbounded case), matching the gate's "absent boundary = unbounded"
 * reading. Trimming first guards the strtotime( ' UTC' ) trap where a lone
 * space resolves to the CURRENT time.
 *
 * @param mixed $value The raw block attribute (string or absent).
 * @return string|null MySQL UTC DATETIME 'Y-m-d H:i:s', or null when empty /
 *                     unparseable.
 */
function sn_schedule_normalize_boundary( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return null;
	}
	$ts = strtotime( $value . ' UTC' );
	if ( ! $ts ) {
		return null;
	}
	return gmdate( 'Y-m-d H:i:s', $ts );
}

/**
 * Clear the boundary cron events of every fragment row for $post_id whose
 * scheduleId is NOT in $keep_ids (the rows delete_missing is about to drop).
 *
 * Must run BEFORE delete_missing: once a row is gone its id is unrecoverable, so
 * its events could never be cleared and would dangle until WP-Cron fired them
 * against a non-existent row. Reading the live rows here, computing the dropped
 * set, and clearing each dropped row's hook by id keeps the queue + cron in
 * lockstep.
 *
 * @param int               $post_id  The post whose fragment rows are reconciled.
 * @param array<int, string> $keep_ids scheduleIds that will survive the sweep.
 * @return void
 */
function sn_schedule_clear_removed_crons( $post_id, array $keep_ids ) {
	$keep = array();
	foreach ( $keep_ids as $kid ) {
		$keep[ (string) $kid ] = true;
	}

	foreach ( sn_schedule_all() as $row ) {
		if ( 'fragment' !== ( $row['target_type'] ?? '' ) ) {
			continue;
		}
		if ( (string) ( $row['target_ref'] ?? '' ) !== (string) $post_id ) {
			continue;
		}
		$sid = (string) ( $row['schedule_id'] ?? '' );
		if ( isset( $keep[ $sid ] ) ) {
			continue; // survives the sweep; its cron is re-armed later.
		}
		// About to be dropped: clear its events so nothing dangles.
		wp_clear_scheduled_hook( SN_SCHEDULE_FIRE_HOOK, array( (int) $row['id'] ) );
	}
}

/**
 * (Re)arm the boundary cron for every fragment row of $post_id.
 *
 * For each row, one single event is armed PER non-null boundary (starts_at and
 * ends_at), keyed on the row id so the fire handler (Task 6) can look the row up.
 * Each boundary's event is cleared first (wp_clear_scheduled_hook) so a re-save
 * after a date edit re-arms cleanly rather than stacking a second event for the
 * same row: the clear is by row id, which removes BOTH that row's prior
 * boundary events at once, and we then re-arm the current boundaries.
 *
 * Past boundaries are NOT pre-filtered out: we arm every non-null boundary,
 * including ones already in the past. WP-Cron fires a past-due single event on
 * its next run, and Task 6's reconcile tick repairs any boundary that was
 * missed entirely (e.g. while WP-Cron was idle). Arming all boundaries is the
 * simpler, safer choice: pruning past boundaries here would be a second place
 * that has to agree with the reconcile about what "past" means, and getting it
 * wrong would silently skip a purge. The reconcile owns "did this boundary
 * already happen"; the sync just declares the boundaries.
 *
 * @param int $post_id The post whose fragment rows are (re)armed.
 * @return void
 */
function sn_schedule_arm_post_crons( $post_id ) {
	foreach ( sn_schedule_all() as $row ) {
		if ( 'fragment' !== ( $row['target_type'] ?? '' ) ) {
			continue;
		}
		if ( (string) ( $row['target_ref'] ?? '' ) !== (string) $post_id ) {
			continue;
		}

		$row_id = (int) $row['id'];
		$args   = array( $row_id );

		// Clear any prior events for this row id (idempotent re-arm). One clear
		// removes every event previously armed on this hook for this row, so a
		// date edit does not leave a stale boundary armed.
		wp_clear_scheduled_hook( SN_SCHEDULE_FIRE_HOOK, $args );

		foreach ( array( $row['starts_at'] ?? null, $row['ends_at'] ?? null ) as $boundary ) {
			$boundary = (string) $boundary;
			if ( '' === $boundary ) {
				continue; // NULL/empty boundary: nothing to fire on this side.
			}
			$ts = strtotime( $boundary . ' UTC' );
			if ( ! $ts ) {
				continue; // unparseable: skip (the gate treats it as unbounded too).
			}
			wp_schedule_single_event( $ts, SN_SCHEDULE_FIRE_HOOK, $args );
		}
	}
}

/**
 * before_delete_post handler: when a post is PERMANENTLY deleted (not trashed),
 * sweep its fragment rows and the cron events armed for them so neither dangles.
 *
 * A trashed post keeps its rows (the gate still withholds the content and a
 * restore re-syncs on the next save). But a permanent delete removes the post
 * for good, leaving its wp_sn_schedules rows + their armed single events with no
 * post behind them: the rows become un-addressable orphans and each armed event
 * would fire against a row whose post no longer exists. This handler closes that
 * gap by reusing the same two save_post reconciliation primitives, in the same
 * order the sync uses them:
 *
 *   1. sn_schedule_clear_removed_crons( $post_id, array() ) clears the cron of
 *      EVERY fragment row for the post: an empty keep-list means no scheduleId
 *      survives the sweep, so the helper clears each fragment row's hook by row
 *      id. It MUST run first, while the rows still exist, because it reads them
 *      to recover the ids (delete cannot, it works by WHERE clause).
 *   2. sn_schedule_delete_all_fragments( (string) $post_id ) then deletes those
 *      rows.
 *
 * Guard: before_delete_post fires only on a real, permanent delete (WP does not
 * fire it for a trash), so no autosave/revision guard is needed here; an
 * id-sanity guard is kept so a bogus id is a no-op.
 *
 * @param int $post_id The post being permanently deleted.
 * @return void
 */
function sn_schedule_before_delete_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}

	// Clear every fragment row's cron BEFORE deleting the rows: the empty
	// keep-list marks all of this post's fragment rows as "removed", so each one's
	// hook is cleared by row id while the rows are still readable.
	sn_schedule_clear_removed_crons( $post_id, array() );

	// Then delete every fragment row for the post.
	sn_schedule_delete_all_fragments( (string) $post_id );
}

// Hook on save_post with 2 args so $post is available, and on before_delete_post
// for orphan cleanup. Both are skipped under the contract-test constant so the
// test can require this file without registering live hooks against the stubbed
// add_action. They go live when the plugin bootstrap require's this module.
if ( ! defined( 'SN_SCHEDULE_SYNC_TEST' ) || ! SN_SCHEDULE_SYNC_TEST ) {
	add_action( 'save_post', 'sn_schedule_sync_post', 10, 2 );
	add_action( 'before_delete_post', 'sn_schedule_before_delete_post', 10, 1 );
}
