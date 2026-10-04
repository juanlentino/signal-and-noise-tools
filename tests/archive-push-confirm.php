<?php
/**
 * Standalone test: the hourly confirmation that the Internet Archive captured
 * what it was asked to (inc/archive-push-confirm.php).
 *
 * Run: php tests/archive-push-confirm.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
const SN_ARCHIVE_PUSH_META = '_sn_archive_push';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
class WP_Error {}
$GLOBALS['meta'] = array(); $GLOBALS['http'] = array(); $GLOBALS['resp'] = array(); $GLOBALS['keys'] = true;
function add_action() {}
function sn_archive_push_keys() { return $GLOBALS['keys'] ? array( 'a', 's' ) : null; }
function get_post_meta( $id, $k ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['avail'] = array(); $GLOBALS['http_exists'] = array();
function wp_remote_get( $u, $a ) {
	if ( 0 === strpos( $u, 'https://archive.org/wayback/available' ) ) { $GLOBALS['http_exists'][] = array( $u, $a ); return array_shift( $GLOBALS['avail'] ) ?? array( 'code' => 200, 'body' => '{"archived_snapshots":{}}' ); }
	$GLOBALS['http'][] = array( $u, $a ); return array_shift( $GLOBALS['resp'] );
}
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return 'https://juanlentino.com/notes/n' . $id . '/'; } }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
// The two queries the module makes, answered from the meta store.
function get_posts( $q ) {
	$ids = array();
	foreach ( $GLOBALS['meta'] as $id => $m ) {
		if ( isset( $q['meta_key'] ) ) { if ( isset( $m[ $q['meta_key'] ] ) ) { $ids[] = $id; } continue; }
		if ( isset( $m['_sn_archive_push'] ) && ! isset( $m['_sn_archive_capture'] ) ) { $ids[] = $id; }
	}
	sort( $ids );
	return $q['posts_per_page'] > 0 ? array_slice( $ids, 0, $q['posts_per_page'] ) : $ids;
}
require __DIR__ . '/../inc/archive-push-confirm.php';

$now = 2000000000;
echo "\nOne answer\n";
$ok = sn_archive_confirm_parse( 200, '{"status":"success","timestamp":"20261004014512","original_url":"https://x/"}', $now - 600, $now );
ok( 'captured' === $ok['state'] && '20261004014512' === $ok['timestamp'] && $now === $ok['checked_at'], 'success with a 14-digit timestamp is captured' );
ok( null === sn_archive_confirm_parse( 200, '{"status":"success","timestamp":"<b>x</b>"}', $now - 600, $now ), 'success with a timestamp that is not one is not an answer' );
ok( null === sn_archive_confirm_parse( 200, '{"status":"pending"}', $now - 600, $now ), 'pending: ask again later' );
$err = sn_archive_confirm_parse( 200, '{"status":"error","message":"<i>Blocked</i>  by   robots"}', $now - 600, $now );
ok( 'failed' === $err['state'] && 'Blocked by robots' === $err['reason'], 'an error is failed, its reason plain text and bounded' );
ok( null === sn_archive_confirm_parse( 0, '', $now - 600, $now ) && null === sn_archive_confirm_parse( 429, 'slow down', $now - 600, $now ) && null === sn_archive_confirm_parse( 200, 'not json', $now - 600, $now ), 'a request that failed, was refused, or read garbage is not an outcome' );
$old = sn_archive_confirm_parse( 200, '{"status":"pending"}', $now - 3 * 86400, $now );
ok( 'unconfirmed' === $old['state'], 'still pending after two days: unconfirmed, so it is not asked forever' );
ok( 'captured' === sn_archive_confirm_parse( 200, '{"status":"success","timestamp":"20261004014512"}', $now - 3 * 86400, $now )['state'], 'a late success is still a capture' );

echo "\nThe hourly pass\n";
$req = static fn( $job, $at ) => array( 'state' => 'requested', 'job_id' => $job, 'requested_at' => $at );
foreach ( range( 1, 7 ) as $i ) { $GLOBALS['meta'][ $i ]['_sn_archive_push'] = $req( "spn2-$i", time() - 600 ); }
$GLOBALS['meta'][2]['_sn_archive_push'] = array( 'state' => 'failed', 'job_id' => '', 'requested_at' => time() - 600 );
$GLOBALS['resp'] = array( array( 'code' => 200, 'body' => '{"status":"success","timestamp":"20261004000000"}' ), array( 'code' => 200, 'body' => '{"status":"pending"}' ), array( 'code' => 200, 'body' => '{"status":"error","message":"no"}' ), new WP_Error() );
ok( 6 === sn_archive_confirm_counts()['waiting'], 'before any pass: six accepted requests are waiting; the push the Archive refused is not' );
$n = sn_archive_confirm_run();
ok( 2 === $n && 4 === count( $GLOBALS['http'] ), 'five notes a pass; a failed push is skipped without a request; two outcomes recorded' );
ok( 'not_requested' === $GLOBALS['meta'][2]['_sn_archive_capture']['state'], 'the failed push is marked as having nothing to confirm, so it does not take a place in every later pass' );
ok( 'https://web.archive.org/save/status/spn2-1' === $GLOBALS['http'][0][0] && ! isset( $GLOBALS['http'][0][1]['headers']['Authorization'] ) && 0 === $GLOBALS['http'][0][1]['redirection'], 'the status read carries no key and follows no redirect' );
ok( 'captured' === $GLOBALS['meta'][1]['_sn_archive_capture']['state'] && ! isset( $GLOBALS['meta'][3]['_sn_archive_capture'] ) && 'failed' === $GLOBALS['meta'][4]['_sn_archive_capture']['state'] && ! isset( $GLOBALS['meta'][5]['_sn_archive_capture'] ), 'captured and failed are recorded; pending and an unreachable Archive leave nothing, to be asked again' );
ok( array( 'captured' => 1, 'failed' => 1, 'unconfirmed' => 0, 'waiting' => 4 ) === sn_archive_confirm_counts(), 'the counts: a push that was never accepted is in none of them' );
ok( 'Captures: 1 captured, 4 asked and not yet confirmed, 1 the Archive could not capture.' === sn_archive_confirm_line(), 'the sentence' );
$GLOBALS['resp'] = array( array( 'code' => 200, 'body' => '{"status":"pending"}' ), array( 'code' => 200, 'body' => '{"status":"pending"}' ), array( 'code' => 200, 'body' => '{"status":"success","timestamp":"20261004000001"}' ), array( 'code' => 200, 'body' => '{"status":"success","timestamp":"20261004000002"}' ) );
$GLOBALS['http'] = array(); sn_archive_confirm_run();
ok( 4 === count( $GLOBALS['http'] ) && 'captured' === $GLOBALS['meta'][7]['_sn_archive_capture']['state'], 'the next pass reaches the notes behind the ones still pending' );
echo "\nThe capture itself is the second witness\n";
$asked = gmmktime( 6, 25, 0, 10, 4, 2026 );
$snap  = static fn( $ts, $status = '200', $avail = true ) => json_encode( array( 'archived_snapshots' => array( 'closest' => array( 'available' => $avail, 'status' => $status, 'timestamp' => $ts, 'url' => 'x' ) ) ) );
ok( '20261004063000' === sn_archive_confirm_exists_parse( 200, $snap( '20261004063000' ), $asked ), 'a capture made after the request counts, by its own timestamp' );
ok( '20261004062200' === sn_archive_confirm_exists_parse( 200, $snap( '20261004062200' ), $asked ) && '' === sn_archive_confirm_exists_parse( 200, $snap( '20261003120000' ), $asked ), 'five minutes of slack between two clocks; a capture from the day before is someone else\'s crawl' );
ok( '' === sn_archive_confirm_exists_parse( 200, '{"archived_snapshots":{}}', $asked ) && '' === sn_archive_confirm_exists_parse( 200, $snap( '20261004063000', '404' ), $asked ) && '' === sn_archive_confirm_exists_parse( 200, $snap( '20261004063000', '200', false ), $asked ), 'no snapshot, a capture of an error page, or one not available: answered, nothing to count' );
ok( null === sn_archive_confirm_exists_parse( 429, 'slow down', $asked ) && null === sn_archive_confirm_exists_parse( 200, 'not json', $asked ) && null === sn_archive_confirm_exists_parse( 200, '{"other":1}', $asked ), 'a refused request or a 200 that is not the answer: not read, which is not "no capture"' );
$GLOBALS['meta'] = array( 9 => array( '_sn_archive_push' => $req( 'spn2-9', $asked ) ) );
$GLOBALS['resp'] = array( array( 'code' => 200, 'body' => '{"status":"pending"}' ) ); $GLOBALS['avail'] = array( array( 'code' => 200, 'body' => $snap( '20261004063000' ) ) ); $GLOBALS['http_exists'] = array();
sn_archive_confirm_run();
$cap = $GLOBALS['meta'][9]['_sn_archive_capture'] ?? array();
ok( 'captured' === ( $cap['state'] ?? '' ) && '20261004063000' === $cap['timestamp'] && 1 === count( $GLOBALS['http_exists'] ) && false !== strpos( $GLOBALS['http_exists'][0][0], rawurlencode( 'https://juanlentino.com/notes/n9/' ) ) && ! isset( $GLOBALS['http_exists'][0][1]['headers']['Authorization'] ), 'a job still pending whose capture exists is recorded as captured, from a keyless read of the note\'s own URL' );
$GLOBALS['meta'] = array( 10 => array( '_sn_archive_push' => $req( 'spn2-10', time() - 3 * 86400 ) ) );
$GLOBALS['resp'] = array( array( 'code' => 200, 'body' => '{"status":"pending"}' ) ); $GLOBALS['avail'] = array( array( 'code' => 429, 'body' => 'slow down' ) );
sn_archive_confirm_run();
ok( ! isset( $GLOBALS['meta'][10]['_sn_archive_capture'] ), 'two days pending and the capture read refused: not given up on, asked again next hour' );
$GLOBALS['resp'] = array( array( 'code' => 200, 'body' => '{"status":"pending"}' ) ); $GLOBALS['avail'] = array( array( 'code' => 200, 'body' => '<html>maintenance</html>' ) );
sn_archive_confirm_run();
ok( ! isset( $GLOBALS['meta'][10]['_sn_archive_capture'] ), 'nor when the capture read came back 200 and unreadable' );
$GLOBALS['resp'] = array( array( 'code' => 200, 'body' => '{"status":"pending"}' ) ); $GLOBALS['avail'] = array();
sn_archive_confirm_run();
ok( 'unconfirmed' === ( $GLOBALS['meta'][10]['_sn_archive_capture']['state'] ?? '' ), 'two days pending and the Archive answers that it holds no capture: unconfirmed' );
$GLOBALS['keys'] = false;
ok( false === sn_archive_confirm_enabled(), 'no keys: the pass is off, and the cron registry reads the same predicate' );
$src = static fn( $f ) => (string) file_get_contents( __DIR__ . '/../' . $f );
ok( false !== strpos( $src( 'inc/cron-dashboard.php' ), "'sn_archive_confirm_hourly', 'sn_archive_confirm_enabled'" ) && false !== strpos( $src( 'inc/cron-lifecycle.php' ), 'SN_ARCHIVE_CONFIRM_HOOK,' ), 'the hourly hook is in the opt-in gates and the deactivation list' );
ok( false === strpos( $src( 'inc/archive-push-confirm.php' ), 'wp_update_post' ) && false === strpos( $src( 'inc/archive-push-confirm.php' ), 'Authorization' ), 'the module writes meta only and sends no key' );
ok( false !== strpos( $src( 'inc/archive-push-confirm.php' ), 'microtime( true ) - $start > SN_ARCHIVE_CONFIRM_BUDGET' ) && 30 === SN_ARCHIVE_CONFIRM_BUDGET, 'a pass stops drawing notes after thirty seconds, so a slow Archive cannot hold the cron request' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
