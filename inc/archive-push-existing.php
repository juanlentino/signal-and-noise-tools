<?php
/**
 * Signal & Noise Tools: push the notes that predate the Internet Archive keys
 * (21.1.0). inc/archive-push.php pushes a note's FIRST publish only, so a note
 * published before the keys were added is never asked for. This is the
 * one-time, owner-started run over those notes: one note per tick, minutes
 * apart, through the same sn_archive_push_run() a first publish uses.
 *
 * The push record on the post is the cursor: a note with any record (asked,
 * or failed and left to its own one retry) is not picked again, so a second
 * press asks for nothing twice. Any failed answer halts the run; the owner
 * resumes it. Nothing here starts by itself.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ARCHIVE_EXISTING_HOOK  = 'sn_archive_push_existing_tick';
const SN_ARCHIVE_EXISTING_OPT   = 'sn_archive_push_existing'; // started_at, state, reason, last_tick, asked.
const SN_ARCHIVE_EXISTING_EVERY = 5 * MINUTE_IN_SECONDS;      // Save Page Now rate-limits; 50 notes take about four hours.

/**
 * The published, unlocked notes with no push record, oldest first.
 *
 * @param int $limit How many ids to return; -1 for all.
 * @return int[]
 */
function sn_archive_existing_pending( $limit = -1 ) {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => (int) $limit,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => SN_ARCHIVE_PUSH_META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an owner-started run, one row per tick.
			)
		)
	);
}

/**
 * What a tick does next. PURE.
 *
 * @param array<string,mixed>|null $record    What the push returned; null when it asked nothing (the note changed since it was picked).
 * @param int                      $remaining Notes still without a record after this one.
 * @param bool                     $booked    Whether the next tick could be scheduled (ignored when none is needed).
 * @return array{state:string,reason:string}
 */
function sn_archive_existing_next( $record, $remaining, $booked ) {
	if ( is_array( $record ) && 'failed' === ( $record['state'] ?? '' ) ) {
		return array( 'state' => 'halted', 'reason' => trim( sprintf( 'HTTP %d %s', (int) ( $record['status'] ?? 0 ), (string) ( $record['reason'] ?? '' ) ) ) );
	}
	if ( (int) $remaining <= 0 ) {
		return array( 'state' => 'done', 'reason' => '' );
	}
	return $booked ? array( 'state' => 'running', 'reason' => '' ) : array( 'state' => 'halted', 'reason' => 'the next push could not be scheduled' );
}

/**
 * Book one tick. The argument is the clock, so two ticks minutes apart are
 * not the "same event" WordPress refuses inside ten minutes.
 *
 * @param int $in Seconds from now.
 * @return bool
 */
function sn_archive_existing_book( $in ) {
	return true === wp_schedule_single_event( time() + (int) $in, SN_ARCHIVE_EXISTING_HOOK, array( time() ) );
}

/**
 * Start, or resume after a halt. Asks for nothing itself: the first push is
 * a minute out, on cron.
 *
 * @return array{result:string,pending:int}
 */
function sn_archive_existing_start() {
	$pending = count( sn_archive_existing_pending() );
	if ( null === sn_archive_push_keys() ) {
		return array( 'result' => 'unconfigured', 'pending' => $pending );
	}
	if ( 0 === $pending ) {
		return array( 'result' => 'nothing', 'pending' => 0 );
	}
	if ( sn_archive_existing_booked( SN_ARCHIVE_EXISTING_HOOK ) ) {
		return array( 'result' => 'running', 'pending' => $pending );
	}
	if ( ! sn_archive_existing_book( MINUTE_IN_SECONDS ) ) {
		return array( 'result' => 'unscheduled', 'pending' => $pending );
	}
	$was = (array) get_option( SN_ARCHIVE_EXISTING_OPT, array() );
	update_option( SN_ARCHIVE_EXISTING_OPT, array( 'started_at' => time(), 'state' => 'running', 'reason' => '', 'last_tick' => 0, 'asked' => (int) ( $was['asked'] ?? 0 ) ), false );
	return array( 'result' => 'started', 'pending' => $pending );
}

/**
 * Whether any tick is booked, whatever its argument.
 *
 * @param string $hook Hook name.
 * @return bool
 */
function sn_archive_existing_booked( $hook ) {
	foreach ( (array) _get_cron_array() as $hooks ) {
		if ( isset( $hooks[ $hook ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Cron callback: push one note, then book the next tick or stop.
 *
 * @return array{state:string,reason:string}|null Null when the run is not running.
 */
function sn_archive_existing_tick() {
	$run = (array) get_option( SN_ARCHIVE_EXISTING_OPT, array() );
	if ( 'running' !== ( $run['state'] ?? '' ) ) {
		return null;
	}
	$ids    = sn_archive_existing_pending( 1 );
	$record = $ids ? sn_archive_push_run( $ids[0], 1 ) : null;
	if ( $ids && null === $record && empty( get_post_meta( $ids[0], SN_ARCHIVE_PUSH_META, true ) ) ) {
		// The push asked nothing and left no record (keys removed since the
		// start): without this the same note would be picked forever.
		$record = array( 'state' => 'failed', 'status' => 0, 'reason' => 'the push did not run' );
	}
	$left = count( sn_archive_existing_pending() );
	$halt = is_array( $record ) && 'failed' === ( $record['state'] ?? '' );
	$next = sn_archive_existing_next( $record, $left, ! $halt && $left > 0 ? sn_archive_existing_book( SN_ARCHIVE_EXISTING_EVERY ) : false );
	update_option( SN_ARCHIVE_EXISTING_OPT, array( 'last_tick' => time(), 'asked' => (int) ( $run['asked'] ?? 0 ) + ( is_array( $record ) && 'requested' === ( $record['state'] ?? '' ) ? 1 : 0 ) ) + $next + $run, false );
	return $next;
}

/**
 * One sentence for the leaf and the ability.
 *
 * @return string
 */
function sn_archive_existing_status_line() {
	$line = sn_archive_existing_run_line();
	// What the Archive itself says happened to the requests (inc/archive-push-confirm.php).
	return function_exists( 'sn_archive_confirm_line' ) && null !== sn_archive_push_keys() ? $line . ' ' . sn_archive_confirm_line() : $line;
}

/**
 * The run alone: configured, running, halted, or how many were never pushed.
 *
 * @return string
 */
function sn_archive_existing_run_line() {
	$run     = (array) get_option( SN_ARCHIVE_EXISTING_OPT, array() );
	$pending = count( sn_archive_existing_pending() );
	$state   = (string) ( $run['state'] ?? '' );
	if ( null === sn_archive_push_keys() ) {
		return 'Not configured: add SN_ARCHIVE_ACCESS_KEY and SN_ARCHIVE_SECRET_KEY to wp-config.';
	}
	if ( 'running' === $state ) {
		return sprintf( 'Running: %d asked so far, %d to go, about one every five to ten minutes.', (int) ( $run['asked'] ?? 0 ), $pending );
	}
	if ( 'halted' === $state ) {
		return sprintf( 'Halted after %d (%s). %d to go; the note that failed keeps its one retry and is not picked again.', (int) ( $run['asked'] ?? 0 ), (string) ( $run['reason'] ?? '' ), $pending );
	}
	return 0 === $pending ? 'Every published note has a push on record.' : sprintf( '%d published notes have never been pushed.', $pending );
}

if ( function_exists( 'add_action' ) ) {
	add_action( SN_ARCHIVE_EXISTING_HOOK, 'sn_archive_existing_tick', 10, 0 );
}
