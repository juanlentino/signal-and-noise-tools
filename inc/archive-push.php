<?php
/**
 * Signal & Noise Tools: a note's first publish is pushed to the Internet
 * Archive (Save Page Now 2), so a dated outside copy exists from day one.
 *
 * On a `post` entering `publish` for the first time, one single cron event a
 * few minutes later POSTs the permalink to web.archive.org/save. Pages,
 * updates, private and password-protected posts are never pushed. One retry,
 * as a second single event; never a loop.
 *
 * The keys are two keyring rows read from wp-config (SN_ARCHIVE_ACCESS_KEY,
 * SN_ARCHIVE_SECRET_KEY; archive.org/account/s3.php). With either missing the
 * module schedules nothing and reports "not configured", like the Cloudways
 * leg. A key is never logged or echoed: a failed call records the status and
 * a bounded, tag-stripped reason.
 *
 * Writes post META only. Nothing the provenance ledger signs (content, title,
 * excerpt) is touched, and no ability schema carries the result.
 *
 * The endpoint and auth shape are the documented public ones, not verified
 * against a live call from here: sn_archive_push_request() is pure and pinned
 * in tests/archive-push.php, and the first publish after the keys are added
 * is the real test.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ARCHIVE_PUSH_ENDPOINT = 'https://web.archive.org/save';
const SN_ARCHIVE_PUSH_HOOK     = 'sn_archive_push_one';
const SN_ARCHIVE_PUSH_META     = '_sn_archive_push';    // per post: requested_at, status, job_id, state, reason, attempt.
const SN_ARCHIVE_PUSH_SEEN     = '_sn_archive_push_seen'; // per post: it has been published before.
const SN_ARCHIVE_PUSH_LAST_OPT = 'sn_archive_push_last'; // the last record, plus post_id and the unresolved failures (20 at most).
const SN_ARCHIVE_PUSH_DELAY    = 5 * MINUTE_IN_SECONDS;  // let the publish-side purges settle first.
const SN_ARCHIVE_PUSH_RETRY    = 30 * MINUTE_IN_SECONDS;

/**
 * Both keys, or null when either is missing.
 *
 * @return array{0:string,1:string}|null
 */
function sn_archive_push_keys() {
	$access = function_exists( 'sn_credential' ) ? sn_credential( 'archive_access_key' ) : '';
	$secret = function_exists( 'sn_credential' ) ? sn_credential( 'archive_secret_key' ) : '';
	return '' !== $access && '' !== $secret ? array( $access, $secret ) : null;
}

/**
 * The request, PURE: wp_remote_post() arguments for one capture.
 *
 * @param string $url    The permalink to capture.
 * @param string $access Access key.
 * @param string $secret Secret key.
 * @return array<string,mixed>
 */
function sn_archive_push_request( $url, $access, $secret ) {
	return array(
		'timeout'     => 15,
		'redirection' => 0, // the Authorization header never follows a redirect.
		'headers'     => array(
			'Authorization' => 'LOW ' . $access . ':' . $secret,
			'Accept'        => 'application/json',
			'User-Agent'    => 'signal-and-noise-tools',
		),
		'body'        => array( 'url' => (string) $url ),
	);
}

/**
 * Whether a status change is a first publish to push. PURE.
 *
 * @param string $new_status New status.
 * @param string $old_status Old status.
 * @param string $type       Post type.
 * @param string $password   Post password.
 * @param bool   $pushed     Whether a push is already recorded for the post.
 * @return bool
 */
function sn_archive_push_wanted( $new_status, $old_status, $type, $password, $pushed ) {
	return 'publish' === (string) $new_status && 'publish' !== (string) $old_status && 'post' === (string) $type && '' === (string) $password && ! $pushed;
}

/**
 * The record of one answer. PURE. A 200 that names a job is recorded as
 * `requested`, never as captured: Save Page Now takes the job and crawls
 * later, and that outcome is not polled. Anything else is `failed`, with a
 * tag-stripped, bounded reason.
 *
 * @param int    $code    HTTP status, 0 when the request never completed.
 * @param string $body    Response body.
 * @param string $error   Transport error, '' when none.
 * @param int    $now     Unix time.
 * @param int    $attempt 1 or 2.
 * @return array<string,mixed>
 */
function sn_archive_push_record( $code, $body, $error, $now, $attempt ) {
	$json   = json_decode( (string) $body, true );
	$job    = is_array( $json ) ? (string) preg_replace( '/[^A-Za-z0-9._:-]/', '', (string) ( $json['job_id'] ?? '' ) ) : '';
	$asked  = 200 === (int) $code && '' !== $job;
	$reason = $asked ? '' : ( '' !== (string) $error ? (string) $error : ( is_array( $json ) && isset( $json['message'] ) ? (string) $json['message'] : ( 200 === (int) $code ? 'no job id in the answer' : '' ) ) );
	return array(
		'requested_at' => (int) $now,
		'status'       => (int) $code,
		'job_id'       => substr( $job, 0, 80 ),
		'state'        => $asked ? 'requested' : 'failed',
		'reason'       => substr( trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $reason ) ) ), 0, 200 ), // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- plain text out, bounded; no WordPress needed for the pure test.
		'attempt'      => (int) $attempt,
	);
}

/**
 * A first publish schedules the push; nothing is scheduled without keys.
 * "First" is kept apart from the push record: any transition into or out of
 * publish stamps the post as published before, keys or no keys, so a note
 * that predates the keys (or this module) is not pushed when it is drafted
 * and published again. Not knowable: a note unpublished before this module
 * ever ran.
 */
function sn_archive_push_on_transition( $new_status, $old_status, $post ) {
	$id = (int) ( $post->ID ?? 0 );
	if ( $id <= 0 || 'post' !== (string) ( $post->post_type ?? '' ) || ! in_array( 'publish', array( (string) $new_status, (string) $old_status ), true ) ) {
		return;
	}
	$before = ! empty( get_post_meta( $id, SN_ARCHIVE_PUSH_SEEN, true ) ) || ! empty( get_post_meta( $id, SN_ARCHIVE_PUSH_META, true ) );
	if ( null !== sn_archive_push_keys() && sn_archive_push_wanted( $new_status, $old_status, $post->post_type, $post->post_password ?? '', $before ) && ! wp_next_scheduled( SN_ARCHIVE_PUSH_HOOK, array( $id, 1 ) )
		&& true !== wp_schedule_single_event( time() + SN_ARCHIVE_PUSH_DELAY, SN_ARCHIVE_PUSH_HOOK, array( $id, 1 ) ) ) {
		// The event could not be booked. The note is NOT stamped, so its next
		// publish tries again, and the loss is held as a failure the watch reads.
		sn_archive_push_store( $id, sn_archive_push_record( 0, '', 'the push could not be scheduled', time(), 0 ), false );
		return;
	}
	if ( ! $before ) {
		update_post_meta( $id, SN_ARCHIVE_PUSH_SEEN, 1 );
	}
}

/**
 * Keep one record: on the post (unless it never ran), as the last result,
 * and in or out of the unresolved failures.
 *
 * @param int                 $post_id Post id.
 * @param array<string,mixed> $record  From sn_archive_push_record().
 * @param bool                $on_post Whether to write the post meta too.
 * @return void
 */
function sn_archive_push_store( $post_id, array $record, $on_post = true ) {
	$last = (array) get_option( SN_ARCHIVE_PUSH_LAST_OPT, array() );
	if ( $on_post ) {
		update_post_meta( (int) $post_id, SN_ARCHIVE_PUSH_META, $record );
		// A new request needs a new outcome: an earlier one (a failed first
		// attempt) must not stand for this one (inc/archive-push-confirm.php).
		if ( defined( 'SN_ARCHIVE_CAPTURE_META' ) && function_exists( 'delete_post_meta' ) ) {
			delete_post_meta( (int) $post_id, SN_ARCHIVE_CAPTURE_META );
		}
	}
	update_option( SN_ARCHIVE_PUSH_LAST_OPT, $record + array( 'post_id' => (int) $post_id, 'failures' => sn_archive_push_failures( (array) ( $last['failures'] ?? array() ), (int) $post_id, $record ) ), false );
}

/**
 * Cron callback: ask for one capture and record the answer. The post is read
 * again here, so one unpublished or locked in the meantime is not pushed, and
 * so is its record: an event run twice (Run now on the Cron leaf leaves the
 * scheduled row in place) asks nothing once this attempt, or an accepted
 * request, is already recorded.
 *
 * @param int $post_id Post id.
 * @param int $attempt 1, or 2 for the one retry.
 * @return array<string,mixed>|null The record, null when nothing was asked.
 */
function sn_archive_push_run( $post_id, $attempt = 1 ) {
	$post = get_post( (int) $post_id );
	$keys = sn_archive_push_keys();
	$had  = (array) get_post_meta( (int) $post_id, SN_ARCHIVE_PUSH_META, true );
	$done = 'requested' === ( $had['state'] ?? '' ) || (int) ( $had['attempt'] ?? 0 ) >= (int) $attempt;
	if ( null === $keys || ! $post || $done || (int) $attempt > 2 || ! sn_archive_push_wanted( $post->post_status, '', $post->post_type, $post->post_password, false ) ) {
		return null;
	}
	$resp   = wp_remote_post( SN_ARCHIVE_PUSH_ENDPOINT, sn_archive_push_request( (string) get_permalink( $post ), $keys[0], $keys[1] ) );
	$failed = is_wp_error( $resp );
	$record = sn_archive_push_record( $failed ? 0 : (int) wp_remote_retrieve_response_code( $resp ), $failed ? '' : (string) wp_remote_retrieve_body( $resp ), $failed ? $resp->get_error_message() : '', time(), (int) $attempt );
	sn_archive_push_store( (int) $post_id, $record );
	if ( 'failed' === $record['state'] && 1 === (int) $attempt ) {
		wp_schedule_single_event( time() + SN_ARCHIVE_PUSH_RETRY, SN_ARCHIVE_PUSH_HOOK, array( (int) $post_id, 2 ) );
	}
	return $record;
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'transition_post_status', 'sn_archive_push_on_transition', 10, 3 );
	add_action( SN_ARCHIVE_PUSH_HOOK, 'sn_archive_push_run', 10, 2 );
}
