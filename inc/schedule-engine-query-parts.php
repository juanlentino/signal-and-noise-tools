<?php
/**
 * Signal & Noise Tools: schedule-engine.php, the list, count and delete
 * reads, and the open-window and boundary math.
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
 * How many rows target one target_type.
 *
 * sn_schedule_all() is unbounded and returns every column of every row; a
 * caller that only wants a figure for a folder tile would read the whole queue
 * to count part of it, on every paint. Counting in SQL is the difference
 * between a number and a table scan carried into PHP.
 *
 * @param string $target_type One of the target_type vocabulary ('fragment' is
 *                            the only value anything writes today).
 * @return int Rows with that target_type; 0 is a measured zero.
 */
function sn_schedule_count( $target_type ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_SCHEDULES_TABLE;

	// Bare interpolation of a constant-derived identifier; the VALUE is bound.
	$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE target_type = %s", (string) $target_type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

	return (int) $n;
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

