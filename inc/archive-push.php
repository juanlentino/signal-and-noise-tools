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
const SN_ARCHIVE_PUSH_META     = '_sn_archive_push';    // per post: requested_at, status, job_id, ok, reason, attempt.
const SN_ARCHIVE_PUSH_LAST_OPT = 'sn_archive_push_last'; // the same record for the last push, plus post_id.
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
 * The record of one answer. PURE. A capture counts only when the archive
 * answered 200 AND named a job; the reason is tag-stripped and bounded.
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
	$ok     = 200 === (int) $code && '' !== $job;
	$reason = $ok ? '' : ( '' !== (string) $error ? (string) $error : ( is_array( $json ) && isset( $json['message'] ) ? (string) $json['message'] : ( 200 === (int) $code ? 'no job id in the answer' : '' ) ) );
	return array(
		'requested_at' => (int) $now,
		'status'       => (int) $code,
		'job_id'       => substr( $job, 0, 80 ),
		'ok'           => $ok,
		'reason'       => substr( trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $reason ) ) ), 0, 200 ), // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- plain text out, bounded; no WordPress needed for the pure test.
		'attempt'      => (int) $attempt,
	);
}

/** A first publish schedules the push; nothing is scheduled without keys. */
function sn_archive_push_on_transition( $new_status, $old_status, $post ) {
	$id = (int) ( $post->ID ?? 0 );
	if ( $id <= 0 || null === sn_archive_push_keys() ) {
		return;
	}
	$pushed = ! empty( get_post_meta( $id, SN_ARCHIVE_PUSH_META, true ) ); // any recorded push, accepted or not.
	if ( sn_archive_push_wanted( $new_status, $old_status, $post->post_type ?? '', $post->post_password ?? '', $pushed ) && ! wp_next_scheduled( SN_ARCHIVE_PUSH_HOOK, array( $id, 1 ) ) ) {
		wp_schedule_single_event( time() + SN_ARCHIVE_PUSH_DELAY, SN_ARCHIVE_PUSH_HOOK, array( $id, 1 ) );
	}
}

/**
 * Cron callback: ask for one capture and record the answer. The post is read
 * again here, so one unpublished or locked in the meantime is not pushed.
 *
 * @param int $post_id Post id.
 * @param int $attempt 1, or 2 for the one retry.
 * @return array<string,mixed>|null The record, null when nothing was asked.
 */
function sn_archive_push_run( $post_id, $attempt = 1 ) {
	$post = get_post( (int) $post_id );
	$keys = sn_archive_push_keys();
	if ( null === $keys || ! $post || (int) $attempt > 2 || ! sn_archive_push_wanted( $post->post_status, '', $post->post_type, $post->post_password, false ) ) {
		return null;
	}
	$resp   = wp_remote_post( SN_ARCHIVE_PUSH_ENDPOINT, sn_archive_push_request( (string) get_permalink( $post ), $keys[0], $keys[1] ) );
	$failed = is_wp_error( $resp );
	$record = sn_archive_push_record( $failed ? 0 : (int) wp_remote_retrieve_response_code( $resp ), $failed ? '' : (string) wp_remote_retrieve_body( $resp ), $failed ? $resp->get_error_message() : '', time(), (int) $attempt );
	update_post_meta( (int) $post_id, SN_ARCHIVE_PUSH_META, $record );
	update_option( SN_ARCHIVE_PUSH_LAST_OPT, $record + array( 'post_id' => (int) $post_id ), false );
	if ( ! $record['ok'] && 1 === (int) $attempt ) {
		wp_schedule_single_event( time() + SN_ARCHIVE_PUSH_RETRY, SN_ARCHIVE_PUSH_HOOK, array( (int) $post_id, 2 ) );
	}
	return $record;
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'transition_post_status', 'sn_archive_push_on_transition', 10, 3 );
	add_action( SN_ARCHIVE_PUSH_HOOK, 'sn_archive_push_run', 10, 2 );
}

/**
 * Watch: ripe for a week after a push that failed, so the brief and the
 * watches read say it. "Not configured" and "never pushed" are not findings.
 *
 * @param array      $watch The watch row.
 * @param int        $now   Unix time.
 * @param array|null $state Test seam: {configured, last}.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_archive_push( $watch, $now, $state = null ) {
	unset( $watch );
	$state = is_array( $state ) ? $state : array( 'configured' => null !== sn_archive_push_keys(), 'last' => get_option( SN_ARCHIVE_PUSH_LAST_OPT, array() ) );
	$last  = (array) $state['last'];
	if ( empty( $state['configured'] ) ) {
		return array( 'ripe' => false, 'note' => 'not configured: add SN_ARCHIVE_ACCESS_KEY and SN_ARCHIVE_SECRET_KEY to wp-config' );
	}
	if ( empty( $last['requested_at'] ) ) {
		return array( 'ripe' => false, 'note' => 'configured; no note pushed yet' );
	}
	$what = sprintf( 'post %d, %s UTC, HTTP %d, attempt %d', (int) ( $last['post_id'] ?? 0 ), gmdate( 'Y-m-d H:i', (int) $last['requested_at'] ), (int) ( $last['status'] ?? 0 ), (int) ( $last['attempt'] ?? 1 ) );
	if ( ! empty( $last['ok'] ) ) {
		return array( 'ripe' => false, 'note' => 'last push accepted: ' . $what . ', job ' . (string) ( $last['job_id'] ?? '' ) );
	}
	return array( 'ripe' => (int) $last['requested_at'] > (int) $now - 7 * DAY_IN_SECONDS, 'note' => 'last push FAILED: ' . $what . ( '' !== (string) ( $last['reason'] ?? '' ) ? ' (' . $last['reason'] . ')' : '' ) );
}
