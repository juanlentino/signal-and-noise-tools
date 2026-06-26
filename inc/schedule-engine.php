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
 * non-empty schedule_id (idempotent: the same schedule_id twice is ONE row).
 * An empty schedule_id is table-canonical and always inserts a fresh row.
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

	// Non-empty schedule_id: update in place if a row already exists.
	if ( '' !== $schedule_id ) {
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE schedule_id = %s LIMIT 1",
			$schedule_id
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
