<?php
/**
 * Signal & Noise Tools — Cron History log.
 *
 * Records every WP-Cron firing (both scheduled-tick and Run-now) to a
 * dedicated wp_snt_cron_history table. Surfaces the last N rows per
 * hook in the Cron dashboard's "Last fired" cell expansion, via REST
 * GET /signal-noise/v1/cron/history, and via the read-only
 * signal-noise/get-cron-history ability.
 *
 * Retention: rolling 30-day window, enforced by a daily prune cron.
 * Hard cap of 1000 rows per hook prevents any single misbehaving
 * hook (firing every minute, every second, etc.) from monopolizing
 * the table.
 *
 * Capture mechanism:
 *   - For scheduled firings: pair of pre/post action hooks at
 *     -PHP_INT_MAX (start time stash) and PHP_INT_MAX (record),
 *     registered for every unique hook found in _get_cron_array()
 *     during DOING_CRON requests. This is the same wp_loaded gate
 *     as snt_cron_track_last_fired_cb, extended.
 *   - For Run-now: snt_cron_run_event_impl calls
 *     snt_cron_history_record directly with its own measured
 *     elapsed_ms + success/error from the try-catch. The post-hook
 *     also fires (since add_action is registered ad-hoc) but the
 *     impl-driven write happens first with richer success/error data.
 *
 * @package SignalNoiseTools
 * @since 3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SNT_CRON_HISTORY_TABLE',          'snt_cron_history' );
define( 'SNT_CRON_HISTORY_DB_VERSION',     '1' );
define( 'SNT_CRON_HISTORY_DB_VERSION_OPT', 'snt_cron_history_db_version' );
define( 'SNT_CRON_HISTORY_CRON_HOOK',      'snt_cron_history_prune' );
define( 'SNT_CRON_HISTORY_RETENTION_DAYS', 30 );
define( 'SNT_CRON_HISTORY_PER_HOOK_CAP',   1000 );

/**
 * Schema. hook is VARCHAR(190) because that's the largest indexable
 * varchar at utf8mb4. args_signature is the md5 from cron storage.
 * Composite index (hook, fired_at) is the read path's primary index;
 * (fired_at) alone supports the retention sweep.
 */
function snt_cron_history_install() {
	global $wpdb;
	$table   = $wpdb->prefix . SNT_CRON_HISTORY_TABLE;
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		hook VARCHAR(190) NOT NULL,
		args_signature CHAR(32) NOT NULL DEFAULT '',
		fired_at DATETIME NOT NULL,
		elapsed_ms MEDIUMINT UNSIGNED DEFAULT NULL,
		success TINYINT(1) NOT NULL DEFAULT 1,
		error_message TEXT,
		PRIMARY KEY  (id),
		KEY hook_fired (hook, fired_at),
		KEY fired_at (fired_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( SNT_CRON_HISTORY_DB_VERSION_OPT, SNT_CRON_HISTORY_DB_VERSION );
}

/**
 * One autoloaded-option compare per request, install-once cost on the
 * delta. Matches the rss-feed-tracker pattern.
 */
function snt_cron_history_maybe_install() {
	if ( get_option( SNT_CRON_HISTORY_DB_VERSION_OPT ) !== SNT_CRON_HISTORY_DB_VERSION ) {
		snt_cron_history_install();
	}
}
add_action( 'init', 'snt_cron_history_maybe_install' );

/**
 * INSERT a history row.
 *
 * @param string      $hook           The cron hook name.
 * @param array       $args           The event args (any non-array becomes []).
 * @param float|null  $elapsed_ms     Best-effort elapsed time; null if unknown.
 * @param bool        $success        Defaults true; only the Run-now path
 *                                    overrides on Throwable.
 * @param string|null $error_message  Throwable->getMessage() for Run-now
 *                                    failures, else null.
 * @return bool                       INSERT success.
 */
function snt_cron_history_record( $hook, $args = array(), $elapsed_ms = null, $success = true, $error_message = null ) {
	if ( ! is_string( $hook ) || '' === $hook ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . SNT_CRON_HISTORY_TABLE;

	$args_sig = '';
	if ( is_array( $args ) ) {
		// Match WP-Cron's internal signature: md5 of the args array
		// serialized via PHP's serialize(). The args_signature column
		// lets the read surface correlate history rows to live cron-
		// dashboard rows (which use the same md5 from _get_cron_array).
		$args_sig = md5( serialize( $args ) );
	}

	$row = array(
		'hook'           => substr( $hook, 0, 190 ),
		'args_signature' => $args_sig,
		// gmdate to UTC; the read surface wraps with wp_date for display.
		'fired_at'       => gmdate( 'Y-m-d H:i:s' ),
		'elapsed_ms'     => null === $elapsed_ms ? null : (int) min( 16777215, max( 0, round( $elapsed_ms ) ) ),
		'success'        => $success ? 1 : 0,
		'error_message'  => null === $error_message ? null : substr( (string) $error_message, 0, 4096 ),
	);

	$inserted = $wpdb->insert(
		$table,
		$row,
		array( '%s', '%s', '%s', '%d', '%d', '%s' )
	);

	return false !== $inserted;
}

/**
 * Read the last N firings for a hook.
 *
 * @param string $hook  The cron hook name.
 * @param int    $limit Default 10, capped at 100.
 * @return array        Array of rows (newest first), or empty array.
 */
function snt_cron_history_for_hook( $hook, $limit = 10 ) {
	if ( ! is_string( $hook ) || '' === $hook ) {
		return array();
	}
	$limit = max( 1, min( 100, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SNT_CRON_HISTORY_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, hook, args_signature, fired_at, elapsed_ms, success, error_message
		 FROM {$table}
		 WHERE hook = %s
		 ORDER BY fired_at DESC, id DESC
		 LIMIT %d",
		$hook,
		$limit
	), ARRAY_A );

	if ( ! is_array( $results ) ) {
		return array();
	}

	// Normalize types for the JSON layer.
	$out = array();
	foreach ( $results as $r ) {
		$out[] = array(
			'id'             => (int) $r['id'],
			'hook'           => (string) $r['hook'],
			'args_signature' => (string) $r['args_signature'],
			'fired_at'       => (string) $r['fired_at'],
			'fired_at_ts'    => strtotime( $r['fired_at'] . ' UTC' ),
			'elapsed_ms'     => null === $r['elapsed_ms'] ? null : (int) $r['elapsed_ms'],
			'success'        => '1' === (string) $r['success'],
			'error_message'  => null === $r['error_message'] ? null : (string) $r['error_message'],
		);
	}
	return $out;
}

/**
 * Per-firing pre/post action callbacks. The static map keeps start
 * times keyed by hook name; cron core fires events one at a time so
 * a single hook never overlaps with itself within one request.
 */
function snt_cron_history_pre_cb() {
	$hook = current_action();
	if ( '' === $hook ) {
		return;
	}
	$starts =& snt_cron_history_starts_ref();
	$starts[ $hook ] = microtime( true );
}

function snt_cron_history_post_cb() {
	$hook = current_action();
	if ( '' === $hook ) {
		return;
	}
	$starts =& snt_cron_history_starts_ref();
	$start = isset( $starts[ $hook ] ) ? (float) $starts[ $hook ] : null;
	unset( $starts[ $hook ] );

	$elapsed_ms = null === $start ? null : ( ( microtime( true ) - $start ) * 1000 );

	// The Run-now path (snt_cron_run_event_impl) writes history directly
	// with richer success/error info BEFORE this post-cb fires, gated
	// by a static flag the impl sets. Avoid double-writing in that case.
	if ( ! empty( $GLOBALS['__snt_cron_history_skip_auto'] ) ) {
		$GLOBALS['__snt_cron_history_skip_auto'] = false;
		return;
	}

	snt_cron_history_record( $hook, array(), $elapsed_ms );
}

/**
 * Reference accessor for the static start-time map. Using a function-
 * scoped static allows multiple module loads (e.g., during tests) to
 * share state without globals leaking.
 */
function & snt_cron_history_starts_ref() {
	static $starts = array();
	return $starts;
}

/**
 * Daily prune: enforce 30-day window AND 1000-row per-hook cap.
 *
 * Two passes: first the time window (cheap, indexed on fired_at), then
 * a per-hook cap pass (more work but bounded). The per-hook pass uses
 * a NOT IN against the top-1000 ids per hook so it's race-safe under
 * concurrent INSERTs.
 */
function snt_cron_history_prune() {
	global $wpdb;
	// Partial-restore guard, same shape as rss-feed-tracker.
	snt_cron_history_maybe_install();

	$table = $wpdb->prefix . SNT_CRON_HISTORY_TABLE;

	// Window pass.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE fired_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
		SNT_CRON_HISTORY_RETENTION_DAYS
	) );

	// Per-hook cap pass. For each distinct hook, keep newest 1000 rows.
	$hooks = $wpdb->get_col( "SELECT DISTINCT hook FROM {$table}" );
	if ( ! is_array( $hooks ) ) {
		return;
	}
	foreach ( $hooks as $hook ) {
		$keep_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE hook = %s ORDER BY fired_at DESC, id DESC LIMIT %d",
			$hook,
			SNT_CRON_HISTORY_PER_HOOK_CAP
		) );
		if ( empty( $keep_ids ) ) {
			continue;
		}
		// Sanitize ids (defense in depth — they came from the DB but
		// implode'd raw into IN() is asking for trouble).
		$keep_ids = array_map( 'intval', $keep_ids );
		$ids_csv  = implode( ',', $keep_ids );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $ids_csv is a comma-joined list of intval-cast IDs read from this same table; $hook is bound via prepare().
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE hook = %s AND id NOT IN ( {$ids_csv} )",
			$hook
		) );
	}
}
add_action( SNT_CRON_HISTORY_CRON_HOOK, 'snt_cron_history_prune' );

function snt_cron_history_schedule_cron() {
	if ( ! wp_next_scheduled( SNT_CRON_HISTORY_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SNT_CRON_HISTORY_CRON_HOOK );
	}
}
// v4.1.1 (B-04): hook on `init` (not `admin_init`) so the cron is scheduled on
// front-end / WP-CLI requests too. Prior `admin_init` hooking meant the cron
// never registered on installs where the first hit wasn't an admin page.
// wp_next_scheduled() inside the callback makes registration idempotent.
add_action( 'init', 'snt_cron_history_schedule_cron' );
