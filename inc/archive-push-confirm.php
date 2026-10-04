<?php
/**
 * Signal & Noise Tools: did the Internet Archive capture what it was asked to?
 *
 * A push records `requested`: the Archive took the job. The crawl runs later.
 * Save Page Now publishes each job's outcome at /save/status/<job id>, with no
 * key needed, so an hourly pass asks about a few requested notes and records
 * the answer beside the request: `captured` with the capture's timestamp, or
 * `failed` with the Archive's reason. A job still pending is asked again next
 * hour; one that never resolves in two days is marked `unconfirmed`.
 *
 * Writes post META only (`_sn_archive_capture`), never content.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ARCHIVE_CONFIRM_HOOK   = 'sn_archive_confirm_hourly';
const SN_ARCHIVE_CAPTURE_META   = '_sn_archive_capture'; // per post: state, timestamp, reason, checked_at.
const SN_ARCHIVE_CONFIRM_STATUS = 'https://web.archive.org/save/status/';
const SN_ARCHIVE_CONFIRM_EXISTS = 'https://archive.org/wayback/available?url='; // the newest capture of a URL, no key needed.
const SN_ARCHIVE_CONFIRM_BUDGET = 30;                     // seconds one pass may spend before it stops drawing notes.
const SN_ARCHIVE_CONFIRM_BATCH  = 5;                      // notes asked about per hour.
const SN_ARCHIVE_CONFIRM_GIVEUP = 2 * DAY_IN_SECONDS;     // a job still pending after this is `unconfirmed`.

/**
 * Whether the pass has anything to confirm: the push is configured.
 *
 * @return bool
 */
function sn_archive_confirm_enabled() {
	return function_exists( 'sn_archive_push_keys' ) && null !== sn_archive_push_keys();
}

/**
 * The outcome of one status answer. PURE.
 *
 * @param int    $code         HTTP status, 0 when the request never completed.
 * @param string $body         Response body.
 * @param int    $requested_at When the push was accepted, unix.
 * @param int    $now          Unix time.
 * @return array{state:string,timestamp:string,reason:string,checked_at:int}|null Null: ask again later.
 */
function sn_archive_confirm_parse( $code, $body, $requested_at, $now ) {
	$json   = 200 === (int) $code ? json_decode( (string) $body, true ) : null;
	$status = is_array( $json ) ? (string) ( $json['status'] ?? '' ) : '';
	$base   = array( 'state' => '', 'timestamp' => '', 'reason' => '', 'checked_at' => (int) $now );
	if ( 'success' === $status && 1 === preg_match( '/^\d{14}$/', (string) ( $json['timestamp'] ?? '' ) ) ) {
		return array( 'state' => 'captured', 'timestamp' => (string) $json['timestamp'] ) + $base;
	}
	if ( 'error' === $status ) {
		$why = (string) ( $json['message'] ?? $json['status_ext'] ?? 'the Archive reported an error' );
		return array( 'state' => 'failed', 'reason' => substr( trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $why ) ) ), 0, 200 ) ) + $base; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- plain text out, bounded; pure for the standalone test.
	}
	// Pending, unreadable, or a failed request: not an answer yet.
	return (int) $now - (int) $requested_at > SN_ARCHIVE_CONFIRM_GIVEUP ? array( 'state' => 'unconfirmed', 'reason' => 'no outcome from the Archive after two days' ) + $base : null;
}

/**
 * Notes with a push on record and no capture outcome yet: all of them oldest
 * first, or a random few when a limit is given.
 *
 * @param int $limit How many ids; -1 for all.
 * @return int[]
 */
/**
 * Read "is there a capture made after we asked" from the availability answer.
 * PURE. The job status can stay `pending` long after the capture exists (seen
 * 2026-10-04: fifty jobs pending for hours, every note captured), so the
 * capture itself is the second witness. A capture from before the request is
 * someone else's crawl and proves nothing about ours.
 *
 * @param int    $code         HTTP status.
 * @param string $body         Response body.
 * @param int    $requested_at Unix time the capture was requested.
 * @return string|null The 14-digit capture timestamp; '' when the Archive answered and there is none to count; null when the answer could not be read.
 */
function sn_archive_confirm_exists_parse( $code, $body, $requested_at ) {
	$json = 200 === (int) $code ? json_decode( (string) $body, true ) : null;
	if ( ! is_array( $json ) || ! array_key_exists( 'archived_snapshots', $json ) ) {
		return null; // refused, failed, or not the answer's shape: not "no capture".
	}
	$snap = $json['archived_snapshots']['closest'] ?? null;
	$ts   = is_array( $snap ) ? (string) ( $snap['timestamp'] ?? '' ) : '';
	if ( ! is_array( $snap ) || empty( $snap['available'] ) || '200' !== (string) ( $snap['status'] ?? '' ) || 1 !== preg_match( '/^\d{14}$/', $ts ) ) {
		return '';
	}
	$at = gmmktime( (int) substr( $ts, 8, 2 ), (int) substr( $ts, 10, 2 ), (int) substr( $ts, 12, 2 ), (int) substr( $ts, 4, 2 ), (int) substr( $ts, 6, 2 ), (int) substr( $ts, 0, 4 ) );
	// Five minutes of slack: the two clocks are not one clock.
	return $at >= (int) $requested_at - 5 * MINUTE_IN_SECONDS ? $ts : '';
}

function sn_archive_confirm_pending( $limit = -1 ) {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limit,
				// A batch is drawn at random: a few jobs that stay pending must not
				// hold the same places every hour while newer requests wait behind them.
				'orderby'        => (int) $limit > 0 ? 'rand' : 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- hourly, five rows.
					'relation' => 'AND',
					array( 'key' => SN_ARCHIVE_PUSH_META, 'compare' => 'EXISTS' ),
					array( 'key' => SN_ARCHIVE_CAPTURE_META, 'compare' => 'NOT EXISTS' ),
				),
			)
		)
	);
}

/**
 * Hourly: ask the Archive about a few requested notes.
 *
 * @return int How many outcomes were recorded.
 */
function sn_archive_confirm_run() {
	$done  = 0;
	$start = microtime( true );
	foreach ( sn_archive_confirm_pending( SN_ARCHIVE_CONFIRM_BATCH ) as $id ) {
		// Two reads a note, six seconds each at worst: stop early on a slow
		// Archive so the cron request ends well inside its limit. The notes
		// not reached are drawn again next hour.
		if ( microtime( true ) - $start > SN_ARCHIVE_CONFIRM_BUDGET ) {
			break;
		}
		$push = (array) get_post_meta( $id, SN_ARCHIVE_PUSH_META, true );
		$job  = (string) ( $push['job_id'] ?? '' );
		if ( 'requested' !== ( $push['state'] ?? '' ) || '' === $job ) {
			// A push the Archive never accepted has nothing to confirm. Say so on
			// the note, or it would hold one of this pass's few places every hour.
			// Its failure and its one retry live in the push record; a retry that
			// is accepted clears this (sn_archive_push_store()).
			update_post_meta( $id, SN_ARCHIVE_CAPTURE_META, array( 'state' => 'not_requested', 'timestamp' => '', 'reason' => '', 'checked_at' => time() ) );
			continue;
		}
		$resp = wp_remote_get( SN_ARCHIVE_CONFIRM_STATUS . rawurlencode( $job ), array( 'timeout' => 6, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'signal-and-noise-tools' ) ) );
		$bad  = is_wp_error( $resp );
		$out  = sn_archive_confirm_parse( $bad ? 0 : (int) wp_remote_retrieve_response_code( $resp ), $bad ? '' : (string) wp_remote_retrieve_body( $resp ), (int) ( $push['requested_at'] ?? 0 ), time() );
		// Did the job status answer at all? A failed or unreadable status read
		// is one witness missing, however old the request is.
		$answered = ! $bad && 200 === (int) wp_remote_retrieve_response_code( $resp ) && is_array( json_decode( (string) wp_remote_retrieve_body( $resp ), true ) );
		// No answer from the job (or none after two days): ask whether the
		// capture exists. One more keyless request, only for these notes.
		if ( null === $out || 'unconfirmed' === $out['state'] ) {
			// Both spellings of the URL: the endpoint answers "no snapshot" for
			// one form of a URL whose other form it holds. Seen both ways round:
			// the https:// form found this site's captures on 2026-10-04 while
			// the scheme-less form read empty; internetarchive/wayback#296
			// reports the reverse. The second read happens only when the first
			// answered and found nothing.
			$link = (string) get_permalink( $id );
			$ts   = '';
			foreach ( array( $link, (string) preg_replace( '#^https?://#i', '', $link ) ) as $form ) {
				$seen = wp_remote_get( SN_ARCHIVE_CONFIRM_EXISTS . rawurlencode( $form ), array( 'timeout' => 6, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'signal-and-noise-tools' ) ) );
				$got  = is_wp_error( $seen ) ? null : sn_archive_confirm_exists_parse( (int) wp_remote_retrieve_response_code( $seen ), (string) wp_remote_retrieve_body( $seen ), (int) ( $push['requested_at'] ?? 0 ) );
				if ( is_string( $got ) && '' !== $got ) {
					$ts = $got;
					break;
				}
				if ( null === $got ) {
					$ts = null; // one form unread: absence is not established.
				}
			}
			if ( is_string( $ts ) && '' !== $ts ) {
				$out = array( 'state' => 'captured', 'timestamp' => $ts, 'reason' => 'found in the Wayback Machine; the job status had not answered', 'checked_at' => time() );
			} elseif ( null === $ts || ! $answered ) {
				// Giving up needs both witnesses to have answered. A refused or
				// failed read here is not "no capture"; ask again next hour.
				$out = null;
			}
		}
		if ( null !== $out ) {
			update_post_meta( $id, SN_ARCHIVE_CAPTURE_META, $out );
			++$done;
		}
	}
	return $done;
}

/**
 * How the captures stand: counts by outcome, over published notes.
 *
 * @return array{captured:int,failed:int,unconfirmed:int,waiting:int}
 */
function sn_archive_confirm_counts() {
	// Waiting is a request the Archive accepted and has not answered for yet. A
	// push it never accepted is not waiting, whether or not the hourly pass has
	// reached it to say so.
	$waiting = 0;
	foreach ( sn_archive_confirm_pending() as $id ) {
		$waiting += 'requested' === ( ( (array) get_post_meta( $id, SN_ARCHIVE_PUSH_META, true ) )['state'] ?? '' ) ? 1 : 0;
	}
	$out = array( 'captured' => 0, 'failed' => 0, 'unconfirmed' => 0, 'waiting' => $waiting );
	$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => SN_ARCHIVE_CAPTURE_META ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- on demand, one site's notes.
	foreach ( $ids as $id ) {
		$state = (string) ( ( (array) get_post_meta( (int) $id, SN_ARCHIVE_CAPTURE_META, true ) )['state'] ?? '' );
		if ( isset( $out[ $state ] ) ) {
			++$out[ $state ];
		}
	}
	return $out;
}

/**
 * One sentence for the leaf, the widget and the ability.
 *
 * @return string
 */
function sn_archive_confirm_line() {
	$c    = sn_archive_confirm_counts();
	$bits = array( sprintf( '%d captured', $c['captured'] ) );
	if ( $c['waiting'] > 0 ) {
		$bits[] = sprintf( '%d asked and not yet confirmed', $c['waiting'] );
	}
	if ( $c['failed'] > 0 ) {
		$bits[] = sprintf( '%d the Archive could not capture', $c['failed'] );
	}
	if ( $c['unconfirmed'] > 0 ) {
		$bits[] = sprintf( '%d with no outcome after two days', $c['unconfirmed'] );
	}
	return 'Captures: ' . implode( ', ', $bits ) . '.';
}

if ( function_exists( 'add_action' ) ) {
	add_action( SN_ARCHIVE_CONFIRM_HOOK, 'sn_archive_confirm_run', 10, 0 );
	// The schedule equals the predicate: on with the keys, off without them
	// (snt_cron_opt_in_gates() reads the same predicate, so "off" is not "missing").
	add_action( 'init', static function () {
		$on   = sn_archive_confirm_enabled();
		$next = wp_next_scheduled( SN_ARCHIVE_CONFIRM_HOOK );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', SN_ARCHIVE_CONFIRM_HOOK );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( SN_ARCHIVE_CONFIRM_HOOK );
		}
	} );
}
