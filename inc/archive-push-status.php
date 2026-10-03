<?php
/**
 * Signal & Noise Tools: the Internet Archive push, what is still unresolved.
 * The failures a later push must not hide, and the watch that reads them.
 * Loaded before inc/archive-push.php, which records into it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The unresolved failures, by post, after one more record. PURE. A later
 * push of ANOTHER post must not hide a note that was never captured, so a
 * failure stays until that same post is accepted. Newest 20 kept.
 *
 * @param array<int,array<string,mixed>> $failures Post id => failed record.
 * @param int                            $post_id  The post just pushed.
 * @param array<string,mixed>            $record   Its record.
 * @return array<int,array<string,mixed>>
 */
function sn_archive_push_failures( array $failures, $post_id, array $record ) {
	unset( $failures[ (int) $post_id ] );
	if ( 'failed' === $record['state'] ) {
		$failures[ (int) $post_id ] = $record;
	}
	return array_slice( $failures, -20, null, true );
}

/**
 * Watch: ripe for a week after a push that failed and was not since
 * accepted for that same post, whatever was pushed afterwards. "Not configured" and "never pushed" are not findings.
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
	$open = array();
	foreach ( (array) ( $last['failures'] ?? array() ) as $id => $f ) {
		if ( (int) ( $f['requested_at'] ?? 0 ) > (int) $now - 7 * DAY_IN_SECONDS ) {
			$open[] = sprintf( 'post %d, %s UTC, HTTP %d, attempt %d%s', (int) $id, gmdate( 'Y-m-d H:i', (int) $f['requested_at'] ), (int) ( $f['status'] ?? 0 ), (int) ( $f['attempt'] ?? 1 ), '' !== (string) ( $f['reason'] ?? '' ) ? ' (' . $f['reason'] . ')' : '' );
		}
	}
	if ( $open ) {
		return array( 'ripe' => true, 'note' => 'push FAILED and not since accepted: ' . implode( '; ', array_slice( $open, -5 ) ) );
	}
	// "Requested" is all a 200 proves: the archive took the job. Whether the
	// capture finished is not polled, so this never says captured.
	return array( 'ripe' => false, 'note' => sprintf( 'last push: post %d, %s UTC, %s%s; a requested capture is not confirmed, check web.archive.org', (int) ( $last['post_id'] ?? 0 ), gmdate( 'Y-m-d H:i', (int) $last['requested_at'] ), (string) ( $last['state'] ?? '' ), '' !== (string) ( $last['job_id'] ?? '' ) ? ', job ' . $last['job_id'] : '' ) );
}
