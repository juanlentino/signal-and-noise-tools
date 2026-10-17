<?php
/**
 * Signal & Noise Tools: re-enqueue a provenance commit whose dispatch event
 * was lost.
 *
 * A save records its commit as 'unanchored' ("Recording") and schedules one
 * sn_prov_dispatch_async event a settle window later (inc/provenance-webhook.php).
 * That event lives in WordPress's single `cron` option, which every request
 * that schedules or reschedules anything reads and writes whole. Two requests
 * that overlap can each write back the copy they loaded, and the later write
 * erases the earlier one's events. The only backstop was the hourly
 * sn_prov_reconcile sweep, so a lost event left a note in Recording for up to
 * an hour.
 *
 * It happened on 2026-10-16: note 2430 v2 was written at 02:15:10 UTC; its
 * dispatch event never fired and was not scheduled afterwards, and the edge
 * probe scheduled in the same request was gone too. Four saves 10 to 35
 * seconds later dispatched normally.
 *
 * Every five minutes this looks for exactly that shape and calls
 * sn_prov_enqueue_dispatch(), the same path a save takes, so the debounce and
 * the supersede gate keep working and the stored commit is dispatched as it
 * is: no new version, no content touched. It stays out of everything else:
 * a commit whose dispatch was attempted, refused or signed has left the
 * settle path and is the hourly sweep's.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PROV_ORPHAN_HOOK = 'sn_prov_orphan_sweep';

/** Seconds past the settle window before a missing event counts as lost. */
const SN_PROV_ORPHAN_GRACE = 120;

/** How far back a post's last modification may be to be checked here. */
const SN_PROV_ORPHAN_WINDOW = 2 * HOUR_IN_SECONDS;

const SN_PROV_ORPHAN_LOG_OPTION = 'sn_prov_orphan_log';
const SN_PROV_ORPHAN_LOG_MAX    = 20;

/**
 * Is this chain entry an unanchored commit whose dispatch event is gone?
 *
 * Unknown never triggers: a malformed entry or an unreadable committed_at
 * reads as not orphaned.
 *
 * @param mixed    $commit    A chain entry.
 * @param int|bool $scheduled wp_next_scheduled() for its post's dispatch event.
 * @param int      $now       Unix time.
 * @param int      $settle    The settle window in seconds.
 * @return bool
 */
function sn_prov_commit_is_orphaned( $commit, $scheduled, $now, $settle ) {
	if ( ! is_array( $commit ) || false !== $scheduled ) {
		return false;
	}
	if ( 'unanchored' !== (string) ( $commit['status'] ?? '' ) || (int) ( $commit['version'] ?? 0 ) < 1 ) {
		return false;
	}
	if ( ! empty( $commit['dispatch_attempted'] ) || ! empty( $commit['dispatch_refused'] ) || '' !== (string) ( $commit['signature'] ?? '' ) ) {
		return false;
	}
	$at = strtotime( (string) ( $commit['committed_at'] ?? '' ) );
	if ( false === $at || $at <= 0 ) {
		return false;
	}
	return (int) $now - $at >= (int) $settle + SN_PROV_ORPHAN_GRACE;
}

/**
 * Find recently modified signed subjects with an orphaned commit and re-enqueue
 * their dispatch.
 *
 * @param int $now Unix time.
 * @return array<int,array{post_id:int,version:int}> What was re-enqueued.
 */
function sn_prov_orphan_sweep( $now ) {
	// A Worker the dispatch cannot reach makes it return before it marks the
	// commit, so the commit would stay orphaned and be re-enqueued every five
	// minutes for as long as the configuration is missing. Read nothing.
	$url = sn_prov_worker_url();
	if ( '' === $url || '' === sn_prov_hmac_secret() || ! sn_prov_url_allowed( $url ) ) {
		return array();
	}

	$ids = get_posts( array(
		'post_type'      => function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_key'       => SN_PROV_UID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded by the date window; mirrors sn_prov_reconcile_sweep().
		'date_query'     => array(
			array(
				'column' => 'post_modified_gmt',
				'after'  => gmdate( 'Y-m-d H:i:s', (int) $now - SN_PROV_ORPHAN_WINDOW ),
			),
		),
	) );

	$settle = sn_prov_settle_seconds();
	$out    = array();
	foreach ( (array) $ids as $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		// The same gate sn_prov_reconcile_post() applies: a protected post is
		// never dispatched, so enqueuing it would loop.
		if ( ! is_object( $post ) || '' !== (string) ( $post->post_password ?? '' ) ) {
			continue;
		}
		$scheduled = wp_next_scheduled( SN_PROV_DISPATCH_ASYNC_HOOK, array( $post_id ) );
		foreach ( sn_prov_get_chain( $post_id ) as $commit ) {
			if ( sn_prov_commit_is_orphaned( $commit, $scheduled, $now, $settle ) ) {
				sn_prov_enqueue_dispatch( $post_id );
				sn_prov_orphan_log( $post_id, (int) $commit['version'], $now );
				$out[] = array( 'post_id' => $post_id, 'version' => (int) $commit['version'] );
				break; // One enqueue re-dispatches every unanchored commit of the post.
			}
		}
	}
	return $out;
}

/**
 * Remember a recovery, newest first, in a bounded ring that is not autoloaded.
 *
 * @param int $post_id
 * @param int $version
 * @param int $at Unix time.
 */
function sn_prov_orphan_log( $post_id, $version, $at ) {
	$log = get_option( SN_PROV_ORPHAN_LOG_OPTION, array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array( 'post_id' => (int) $post_id, 'version' => (int) $version, 'at' => (int) $at ) );
	update_option( SN_PROV_ORPHAN_LOG_OPTION, array_slice( $log, 0, SN_PROV_ORPHAN_LOG_MAX ), false );
}

/** Cron callback. */
function sn_prov_orphan_sweep_cb() {
	sn_prov_orphan_sweep( time() );
}
add_action( SN_PROV_ORPHAN_HOOK, 'sn_prov_orphan_sweep_cb' );

/**
 * Keep the five-minute event scheduled. Writes the cron option only when the
 * event is missing, never on an ordinary request. The sn_five_minutes interval
 * is registered by inc/deploy-workers-status-parts.php.
 */
function sn_prov_orphan_schedule() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_PROV_ORPHAN_HOOK ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'sn_five_minutes', SN_PROV_ORPHAN_HOOK );
	}
}
add_action( 'init', 'sn_prov_orphan_schedule' );
