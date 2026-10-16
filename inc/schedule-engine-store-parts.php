<?php
/**
 * Signal & Noise Tools: schedule-engine.php, the table, its install, and the
 * row writes and single read.
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

