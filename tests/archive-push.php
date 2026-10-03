<?php
/**
 * Guard: the Internet Archive push on a note's first publish (Unreleased).
 * wp_remote_post is a recorder: no request leaves this test. The request
 * builder is pinned as the documented Save Page Now 2 shape; the first live
 * publish after the keys are added is the real test.
 *
 * Run: php tests/archive-push.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['opt'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['http'] = array(); $GLOBALS['resp'] = null; $GLOBALS['keys'] = array(); $GLOBALS['posts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function get_post_meta( $id, $k ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function wp_next_scheduled( $h, $a = array() ) { foreach ( $GLOBALS['cron'] as $e ) { if ( $e['hook'] === $h && $e['args'] === $a ) { return $e['at']; } } return false; }
$GLOBALS['sched_ok'] = true;
function wp_schedule_single_event( $at, $h, $a = array() ) { if ( ! $GLOBALS['sched_ok'] ) { return false; } $GLOBALS['cron'][] = array( 'at' => $at, 'hook' => $h, 'args' => $a ); return true; }
function sn_credential( $id ) { return $GLOBALS['keys'][ $id ] ?? ''; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_permalink( $p ) { return 'https://example.test/notes/' . $p->post_name . '/'; }
function wp_remote_post( $u, $a ) { $GLOBALS['http'][] = array( 'url' => $u, 'args' => $a ); return $GLOBALS['resp']; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { function get_error_message() { return 'cURL error 28: <b>timed out</b>'; } }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
require __DIR__ . '/../inc/archive-push-status.php';
require __DIR__ . '/../inc/archive-push.php';

$note = static fn( $o = array() ) => (object) ( $o + array( 'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_name' => 'a-note' ) );
$GLOBALS['posts'][7] = $note();
$both = array( 'archive_access_key' => 'AKEY', 'archive_secret_key' => 'SKEY' );

echo "The request (the documented Save Page Now 2 shape)\n";
$r = sn_archive_push_request( 'https://example.test/notes/a-note/', 'AKEY', 'SKEY' );
ok( 'https://web.archive.org/save' === SN_ARCHIVE_PUSH_ENDPOINT, 'the endpoint is web.archive.org/save' );
ok( array( 'url' => 'https://example.test/notes/a-note/' ) === $r['body'], 'the body is url=<permalink> and nothing else' );
ok( array( 'Authorization' => 'LOW AKEY:SKEY', 'Accept' => 'application/json', 'User-Agent' => 'signal-and-noise-tools' ) === $r['headers'], 'Authorization is LOW access:secret, Accept is JSON, the User-Agent is named' );
ok( 0 === $r['redirection'] && 15 === $r['timeout'], 'redirects are off, so the keys never follow one; the call is bounded' );

echo "\nWhat is a first publish\n";
ok( sn_archive_push_wanted( 'publish', 'draft', 'post', '', false ) && sn_archive_push_wanted( 'publish', 'future', 'post', '', false ), 'a draft or a scheduled note entering publish is pushed' );
ok( ! sn_archive_push_wanted( 'publish', 'publish', 'post', '', false ), 'an update of a published note is not' );
ok( ! sn_archive_push_wanted( 'publish', 'draft', 'page', '', false ), 'a page is not' );
ok( ! sn_archive_push_wanted( 'private', 'draft', 'post', '', false ) && ! sn_archive_push_wanted( 'publish', 'draft', 'post', 'pw', false ), 'a private or password-protected note is not' );
ok( ! sn_archive_push_wanted( 'publish', 'draft', 'post', '', true ), 'a note with a recorded push is not pushed again on a re-publish' );

echo "\nScheduling\n";
sn_archive_push_on_transition( 'publish', 'draft', $note() );
ok( array() === $GLOBALS['cron'] && null === sn_archive_push_run( 7 ) && array() === $GLOBALS['http'], 'no keys: nothing is scheduled and a run asks nothing (a silent no-op)' );
$GLOBALS['keys'] = array( 'archive_access_key' => 'AKEY' ); $GLOBALS['meta'] = array();
sn_archive_push_on_transition( 'publish', 'draft', $note() );
ok( array() === $GLOBALS['cron'], 'one key of the pair is still not configured' );
$GLOBALS['keys'] = $both; $GLOBALS['meta'] = array();
sn_archive_push_on_transition( 'publish', 'publish', $note() );
ok( array() === $GLOBALS['cron'], 'keys set: an update schedules nothing' );
$GLOBALS['meta'] = array();
sn_archive_push_on_transition( 'publish', 'draft', $note() );
sn_archive_push_on_transition( 'publish', 'draft', $note() );
ok( 1 === count( $GLOBALS['cron'] ) && array( 7, 1 ) === $GLOBALS['cron'][0]['args'] && $GLOBALS['cron'][0]['at'] >= time() + 290, 'a first publish schedules ONE single event, a few minutes out, with the post id and attempt 1' );

echo "\nFirst means first, whatever this module has recorded\n";
$GLOBALS['meta'] = array(); $GLOBALS['cron'] = array();
sn_archive_push_on_transition( 'draft', 'publish', $note( array( 'post_status' => 'draft' ) ) );
sn_archive_push_on_transition( 'publish', 'draft', $note() );
ok( array() === $GLOBALS['cron'], 'a note published before this module, drafted and published again, is not pushed: leaving publish stamps it' );
$GLOBALS['meta'] = array(); $GLOBALS['keys'] = array();
sn_archive_push_on_transition( 'publish', 'draft', $note() );
$GLOBALS['keys'] = $both;
sn_archive_push_on_transition( 'draft', 'publish', $note() );
sn_archive_push_on_transition( 'publish', 'draft', $note() );
ok( array() === $GLOBALS['cron'], 'a note first published before the keys existed is not pushed on a later re-publish' );
$GLOBALS['meta'] = array();
sn_archive_push_on_transition( 'publish', 'draft', $note( array( 'post_type' => 'page' ) ) );
ok( array() === $GLOBALS['meta'], 'a page is never stamped' );
sn_archive_push_on_transition( 'publish', 'draft', $note() );

echo "\nA first publish that cannot be scheduled is not lost\n";
$GLOBALS['meta'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['opt'] = array(); $GLOBALS['sched_ok'] = false;
sn_archive_push_on_transition( 'publish', 'draft', $note() );
$held = $GLOBALS['opt'][ SN_ARCHIVE_PUSH_LAST_OPT ]['failures'][7] ?? array();
ok( array() === $GLOBALS['meta'] && 'the push could not be scheduled' === ( $held['reason'] ?? '' ), 'the note is not stamped as seen, and the loss is held as a failure' );
ok( snt_watch_ripe_archive_push( array(), time(), array( 'configured' => true, 'last' => $GLOBALS['opt'][ SN_ARCHIVE_PUSH_LAST_OPT ] ) )['ripe'], 'which the watch reads' );
$GLOBALS['sched_ok'] = true;
sn_archive_push_on_transition( 'publish', 'draft', $note() );
ok( 1 === count( $GLOBALS['cron'] ) && isset( $GLOBALS['meta'][7][ SN_ARCHIVE_PUSH_SEEN ] ), 'and its next publish schedules the push' );
$GLOBALS['opt'] = array();

echo "\nThe run\n";
$GLOBALS['resp'] = array( 'code' => 200, 'body' => '{"url":"https://example.test/notes/a-note/","job_id":"spn2-0123abcd"}' );
$rec = sn_archive_push_run( 7, 1 );
ok( 1 === count( $GLOBALS['http'] ) && SN_ARCHIVE_PUSH_ENDPOINT === $GLOBALS['http'][0]['url'] && 'https://example.test/notes/a-note/' === $GLOBALS['http'][0]['args']['body']['url'], 'the run posts the permalink to the endpoint' );
ok( 'requested' === $rec['state'] && ! array_key_exists( 'ok', $rec ) && 200 === $rec['status'] && 'spn2-0123abcd' === $rec['job_id'] && $rec === $GLOBALS['meta'][7][ SN_ARCHIVE_PUSH_META ], 'a 200 with a job id is recorded as REQUESTED, never as a success or a capture' );
ok( 7 === $GLOBALS['opt'][ SN_ARCHIVE_PUSH_LAST_OPT ]['post_id'] && 1 === count( $GLOBALS['cron'] ), 'and as the last result; an accepted request schedules no retry' );
ok( array( SN_ARCHIVE_PUSH_SEEN, SN_ARCHIVE_PUSH_META ) === array_keys( $GLOBALS['meta'][7] ), 'two post meta keys are all that is written to the post' );
$n = count( $GLOBALS['http'] );
ok( null === sn_archive_push_run( 7, 1 ) && null === sn_archive_push_run( 7, 2 ) && $n === count( $GLOBALS['http'] ), 'the same event run again (Run now leaves the scheduled row) asks nothing once a request was accepted' );
$GLOBALS['cron'] = array(); // the event has fired and cleared.
sn_archive_push_on_transition( 'publish', 'draft', $note() );
ok( array() === $GLOBALS['cron'], 'unpublish then publish again: the recorded push stops a second one' );

echo "\nFailure, and one retry at most\n";
$GLOBALS['meta'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['opt'] = array();
$GLOBALS['resp'] = array( 'code' => 401, 'body' => '{"message":"You need to be <i>logged in</i> to use Save Page Now."}' );
$rec = sn_archive_push_run( 7, 1 );
ok( 'failed' === $rec['state'] && 401 === $rec['status'] && 'You need to be logged in to use Save Page Now.' === $rec['reason'], 'a refusal records the status and a tag-stripped reason' );
ok( 1 === count( $GLOBALS['cron'] ) && array( 7, 2 ) === $GLOBALS['cron'][0]['args'], 'and schedules one retry, as attempt 2' );
$n = count( $GLOBALS['http'] );
ok( null === sn_archive_push_run( 7, 1 ) && $n === count( $GLOBALS['http'] ) && 1 === count( $GLOBALS['cron'] ), 'attempt 1 run again after it failed asks nothing and books no second retry' );
$rec = sn_archive_push_run( 7, 2 );
ok( 'failed' === $rec['state'] && 2 === $rec['attempt'] && 1 === count( $GLOBALS['cron'] ), 'the retry runs after a failed attempt 1, and failing schedules nothing more' );
$n = count( $GLOBALS['http'] );
ok( null === sn_archive_push_run( 7, 3 ) && null === sn_archive_push_run( 7, 2 ) && $n === count( $GLOBALS['http'] ), 'an attempt past 2, or attempt 2 again, is refused without a request: never a loop' );
$GLOBALS['meta'] = array(); $GLOBALS['resp'] = new WP_Error();
$rec = sn_archive_push_run( 7, 2 );
ok( 0 === $rec['status'] && 'cURL error 28: timed out' === $rec['reason'], 'a transport error records status 0 and the stripped message' );
$long = sn_archive_push_record( 500, '{"message":"' . str_repeat( 'x', 900 ) . '"}', '', 1, 1 );
ok( 200 === strlen( $long['reason'] ), 'the reason is bounded at 200 characters' );
ok( 'failed' === sn_archive_push_record( 200, '<html>login</html>', '', 1, 1 )['state'], 'a 200 that names no job is a failure' );
ok( false === strpos( json_encode( array( $GLOBALS['meta'], $GLOBALS['opt'] ) ), 'AKEY' ) && false === strpos( json_encode( array( $GLOBALS['meta'], $GLOBALS['opt'] ) ), 'SKEY' ), 'no key reaches anything stored' );
$GLOBALS['meta'] = array();
$GLOBALS['posts'][7] = $note( array( 'post_status' => 'draft' ) );
ok( null === sn_archive_push_run( 7, 1 ), 'a note unpublished before the event fires is not pushed' );
$GLOBALS['posts'][7] = $note( array( 'post_password' => 'pw' ) );
ok( null === sn_archive_push_run( 7, 1 ) && null === sn_archive_push_run( 99, 1 ), 'nor one locked with a password in the meantime, nor a post that is gone' );

echo "\nA failure outlives a later push of another note\n";
$GLOBALS['meta'] = array(); $GLOBALS['opt'] = array(); $GLOBALS['posts'][7] = $note(); $GLOBALS['posts'][8] = $note( array( 'ID' => 8, 'post_name' => 'b-note' ) );
$GLOBALS['resp'] = array( 'code' => 401, 'body' => '{"message":"refused"}' );
sn_archive_push_run( 7, 1 ); sn_archive_push_run( 7, 2 );
$GLOBALS['resp'] = array( 'code' => 200, 'body' => '{"job_id":"spn2-b"}' );
sn_archive_push_run( 8, 1 );
$last = $GLOBALS['opt'][ SN_ARCHIVE_PUSH_LAST_OPT ];
ok( 8 === $last['post_id'] && 'requested' === $last['state'] && array( 7 ) === array_keys( $last['failures'] ), 'post 7 failed twice, post 8 was accepted after: 7 is still held as unresolved' );
$w = snt_watch_ripe_archive_push( array(), time(), array( 'configured' => true, 'last' => $last ) );
ok( $w['ripe'] && false !== strpos( $w['note'], 'post 7' ) && false !== strpos( $w['note'], 'HTTP 401' ) && false !== strpos( $w['note'], 'refused' ), 'and the watch stays ripe for it, naming the post, the status and the reason' );
ok( array() === sn_archive_push_failures( $last['failures'], 7, array( 'state' => 'requested' ) ), 'the same post accepted later clears its failure' );
$many = array();
foreach ( range( 1, 30 ) as $i ) { $many = sn_archive_push_failures( $many, $i, array( 'state' => 'failed' ) ); }
ok( 20 === count( $many ) && array( 11, 30 ) === array( array_key_first( $many ), array_key_last( $many ) ), 'the held failures are bounded at the newest 20' );

echo "\nThe watch\n";
$now = 1000000000;
$w = snt_watch_ripe_archive_push( array(), $now, array( 'configured' => false, 'last' => array() ) );
ok( ! $w['ripe'] && 0 === strpos( $w['note'], 'not configured' ), 'no keys reads "not configured" and is not a finding' );
ok( ! snt_watch_ripe_archive_push( array(), $now, array( 'configured' => true, 'last' => array() ) )['ripe'], 'configured and never pushed is not a finding' );
$bad = array( 'requested_at' => $now - 3600, 'status' => 401, 'state' => 'failed', 'reason' => 'refused', 'attempt' => 2 );
$st  = array( 'configured' => true, 'last' => $bad + array( 'post_id' => 7, 'failures' => array( 7 => $bad ) ) );
ok( snt_watch_ripe_archive_push( array(), $now, $st )['ripe'] && ! snt_watch_ripe_archive_push( array(), $now + 8 * 86400, $st )['ripe'], 'a failed push is ripe, and stops being ripe after a week' );
$w = snt_watch_ripe_archive_push( array(), $now, array( 'configured' => false, 'last' => $st['last'] ) );
ok( $w['ripe'] && false !== strpos( $w['note'], 'post 7' ), 'keys removed after a failed push: the failure is still reported, not hidden behind "not configured"' );
$w = snt_watch_ripe_archive_push( array(), $now, array( 'configured' => true, 'last' => array( 'post_id' => 7, 'requested_at' => $now, 'state' => 'requested', 'job_id' => 'spn2-x', 'failures' => array() ) ) );
ok( ! $w['ripe'] && false !== strpos( $w['note'], 'requested, job spn2-x' ) && false !== strpos( $w['note'], 'not confirmed' ) && false === stripos( $w['note'], 'captured' ) && false === stripos( $w['note'], 'success' ), 'an accepted request is quiet and says requested, not confirmed: never captured' );

echo "\nRegistration\n";
$src = static fn( $f ) => (string) file_get_contents( __DIR__ . '/../' . $f );
ok( false !== strpos( $src( 'inc/watches.php' ), "'ripe'      => 'snt_watch_ripe_archive_push'" ), 'the watch is registered' );
ok( false !== strpos( $src( 'inc/cron-lifecycle.php' ), 'SN_ARCHIVE_PUSH_HOOK,' ), 'the single event is in the deactivation list' );
$ring = $src( 'inc/keyring.php' );
ok( false !== strpos( $ring, "'constant' => 'SN_ARCHIVE_ACCESS_KEY'" ) && false !== strpos( $ring, "'constant' => 'SN_ARCHIVE_SECRET_KEY'" ), 'both keys are keyring rows read from wp-config constants' );
$mod = $src( 'inc/archive-push.php' );
ok( false === strpos( $mod, 'wp_update_post' ) && false === strpos( $mod, 'wp_insert_post' ) && false === strpos( $mod, 'error_log' ), 'the module never writes a post and never logs' );
ok( false === strpos( $src( 'inc/abilities-remote-set.php' ), 'archive_push' ), 'no remote-twinned schema carries the result' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
