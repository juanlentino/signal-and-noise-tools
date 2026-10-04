<?php
/**
 * Standalone test: the owner-started Internet Archive run over notes that
 * predate the keys (inc/archive-push-existing.php, 21.1.0).
 *
 * Run: php tests/archive-push-existing.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
class WP_Error { public function get_error_message() { return 'timeout'; } }
$GLOBALS['opt'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['http'] = array(); $GLOBALS['posts'] = array(); $GLOBALS['keys'] = array(); $GLOBALS['sched_ok'] = true; $GLOBALS['resp'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function get_post_meta( $id, $k ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function wp_next_scheduled( $h, $a = array() ) { return false; }
function wp_schedule_single_event( $at, $h, $a = array() ) { if ( ! $GLOBALS['sched_ok'] ) { return false; } $GLOBALS['cron'][] = array( 'at' => $at, 'hook' => $h, 'args' => $a ); return true; }
function _get_cron_array() { $out = array(); foreach ( $GLOBALS['cron'] as $e ) { $out[ $e['at'] ][ $e['hook'] ] = array(); } return $out; }
function sn_credential( $id ) { return $GLOBALS['keys'][ $id ] ?? ''; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_permalink( $p ) { return 'https://example.test/notes/' . $p->post_name . '/'; }
function wp_remote_post( $u, $a ) { $GLOBALS['http'][] = $a['body']['url']; return array_shift( $GLOBALS['resp'] ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
// The query the module makes: published, unlocked posts with no push record, oldest first.
function get_posts( $q ) {
	$GLOBALS['last_query'] = $q;
	$ids = array();
	foreach ( $GLOBALS['posts'] as $id => $p ) {
		if ( 'post' === $p->post_type && 'publish' === $p->post_status && '' === $p->post_password && ! isset( $GLOBALS['meta'][ $id ]['_sn_archive_push'] ) ) { $ids[] = $id; }
	}
	sort( $ids );
	return $q['posts_per_page'] > 0 ? array_slice( $ids, 0, $q['posts_per_page'] ) : $ids;
}
require __DIR__ . '/../inc/archive-push-status.php';
require __DIR__ . '/../inc/archive-push.php';
require __DIR__ . '/../inc/archive-push-existing.php';

$note = static fn( $id, $o = array() ) => (object) ( $o + array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_name' => 'n' . $id ) );
$yes  = static fn( $job ) => array( 'code' => 200, 'body' => json_encode( array( 'job_id' => $job ) ) );
$no   = array( 'code' => 429, 'body' => json_encode( array( 'message' => 'too many' ) ) );
$run  = static fn() => $GLOBALS['opt'][ SN_ARCHIVE_EXISTING_OPT ];
$drop = static function () { $GLOBALS['cron'] = array_values( array_filter( $GLOBALS['cron'], static fn( $e ) => SN_ARCHIVE_EXISTING_HOOK !== $e['hook'] ) ); }; // cron ran the tick: its row is gone

echo "\nThe decision (pure)\n";
ok( 'halted' === sn_archive_existing_next( array( 'state' => 'failed', 'status' => 429, 'reason' => 'too many' ), 9, true )['state'], 'a failed answer halts, whatever is left' );
ok( 'HTTP 429 too many' === sn_archive_existing_next( array( 'state' => 'failed', 'status' => 429, 'reason' => 'too many' ), 9, true )['reason'], 'the halt carries the status and the reason' );
ok( 'done' === sn_archive_existing_next( array( 'state' => 'requested' ), 0, false )['state'], 'nothing left is done' );
ok( 'running' === sn_archive_existing_next( array( 'state' => 'requested' ), 3, true )['state'], 'accepted with notes left keeps running' );
ok( 'running' === sn_archive_existing_next( null, 3, true )['state'], 'a note the push skipped is not a failure' );
ok( 'halted' === sn_archive_existing_next( array( 'state' => 'requested' ), 3, false )['state'], 'a next tick that cannot be booked halts' );

echo "\nStart\n";
$GLOBALS['posts'] = array( 1 => $note( 1 ), 2 => $note( 2 ), 3 => $note( 3 ), 4 => $note( 4, array( 'post_password' => 'x' ) ), 5 => $note( 5, array( 'post_type' => 'page' ) ), 6 => $note( 6, array( 'post_status' => 'draft' ) ) );
$GLOBALS['meta'][3]['_sn_archive_push'] = array( 'state' => 'requested', 'attempt' => 1 );
ok( 'unconfigured' === sn_archive_existing_start()['result'] && array() === $GLOBALS['cron'], 'no keys: nothing is booked' );
$GLOBALS['keys'] = array( 'archive_access_key' => 'A', 'archive_secret_key' => 'S' );
ok( array( 1, 2 ) === sn_archive_existing_pending(), 'pending is the published, unlocked notes with no record: not the page, the draft, the locked one, or the one already asked' );
ok( 'NOT EXISTS' === $GLOBALS['last_query']['meta_query'][0]['compare'] && false === $GLOBALS['last_query']['has_password'] && 'ASC' === $GLOBALS['last_query']['order'], 'the query asks for that itself' );
$s = sn_archive_existing_start();
ok( 'started' === $s['result'] && 2 === $s['pending'] && 1 === count( $GLOBALS['cron'] ) && array() === $GLOBALS['http'], 'start books one tick and asks the archive for nothing' );
ok( 'running' === sn_archive_existing_start()['result'] && 1 === count( $GLOBALS['cron'] ), 'a second press while a tick is booked books nothing' );

echo "\nTicks\n";
$drop(); $GLOBALS['resp'] = array( $yes( 'spn2-a' ) );
$t = sn_archive_existing_tick();
ok( 'running' === $t['state'] && array( 'https://example.test/notes/n1/' ) === $GLOBALS['http'] && 'requested' === $GLOBALS['meta'][1]['_sn_archive_push']['state'], 'a tick pushes the oldest note through the same push a first publish uses' );
ok( 1 === $run()['asked'] && 1 === count( $GLOBALS['cron'] ) && $GLOBALS['cron'][0]['at'] >= time() + SN_ARCHIVE_EXISTING_EVERY - 2, 'and books the next tick five minutes out' );
ok( $GLOBALS['cron'][0]['args'] !== array(), 'the tick carries an argument, so WordPress does not refuse it as a repeat inside ten minutes' );
$drop(); $GLOBALS['resp'] = array( $no );
$t = sn_archive_existing_tick();
ok( 'halted' === $t['state'] && 'HTTP 429 too many' === $run()['reason'] && 1 === $run()['asked'], 'a refusal halts the run and keeps the count' );
$booked = array_column( $GLOBALS['cron'], 'hook' );
ok( ! in_array( SN_ARCHIVE_EXISTING_HOOK, $booked, true ) && in_array( SN_ARCHIVE_PUSH_HOOK, $booked, true ), 'no further tick is booked; the failed note keeps its own one retry' );
ok( null === sn_archive_existing_tick() && 2 === count( $GLOBALS['http'] ), 'a stray tick while halted asks nothing' );
ok( array() === sn_archive_existing_pending() && 'nothing' === sn_archive_existing_start()['result'], 'the failed note has a record, so it is not picked again and there is nothing to resume' );
ok( isset( $GLOBALS['opt'][ SN_ARCHIVE_PUSH_LAST_OPT ]['failures'][2] ), 'the failure is held where the watch reads it' );

echo "\nResume and finish\n";
$GLOBALS['posts'][7] = $note( 7 ); $GLOBALS['posts'][8] = $note( 8 );
ok( 'started' === sn_archive_existing_start()['result'] && 1 === $run()['asked'], 'resume books a tick and keeps the count' );
$drop(); $GLOBALS['resp'] = array( $yes( 'spn2-b' ), $yes( 'spn2-c' ) );
sn_archive_existing_tick(); $drop();
$t = sn_archive_existing_tick();
ok( 'done' === $t['state'] && 3 === $run()['asked'] && ! sn_archive_existing_booked( SN_ARCHIVE_EXISTING_HOOK ), 'the last note ends the run with no tick left booked' );
ok( false !== strpos( sn_archive_existing_status_line(), 'Every published note has a push on record' ), 'the line says so' );

echo "\nA note that leaves no record cannot loop\n";
$GLOBALS['posts'][9] = $note( 9 ); sn_archive_existing_start(); $drop(); $GLOBALS['keys'] = array();
$t = sn_archive_existing_tick();
ok( 'halted' === $t['state'] && ! sn_archive_existing_booked( SN_ARCHIVE_EXISTING_HOOK ), 'keys removed mid-run: the tick halts instead of picking the same note forever' );

echo "\nRegistration\n";
$src = static fn( $f ) => (string) file_get_contents( __DIR__ . '/../' . $f );
ok( false !== strpos( $src( 'inc/cron-lifecycle.php' ), 'SN_ARCHIVE_EXISTING_HOOK,' ), 'the tick is in the deactivation list' );
ok( false !== strpos( $src( 'inc/admin-post-handler.php' ), "'archive_push_existing'      => 'sn_handle_archive_push_existing'" ), 'the button has its handler' );
$mod = $src( 'inc/archive-push-existing.php' );
ok( false === strpos( $mod, 'wp_remote_' ) && false === strpos( $mod, 'transition_post_status' ) && false === strpos( $mod, "add_action( 'init'" ), 'the module has no request of its own and nothing that starts it by itself' );
foreach ( array( 'started', 'running', 'nothing', 'unconfigured', 'unscheduled' ) as $r ) {
	ok( false !== strpos( $src( 'inc/admin-flash-messages.php' ), "'archive_existing_$r'" ), "flash for $r" );
}

$classic = $src( 'inc/archive-push-admin.php' );
ok( false !== strpos( $classic, "sn_admin_post_url( 'archive_push_existing' )" ) && false !== strpos( $classic, 'value="sn_archive_push_existing"' ) && false !== strpos( $src( 'inc/provenance-admin.php' ), 'sn_archive_existing_render_fieldset();' ), 'the classic Provenance page carries the same button through the same handler' );
ok( false !== strpos( $src( 'apps/sn-dashboard/parts/leaves/tools-provenance-archive.php' ), "'archive_push_existing'" ), 'and the native leaf' );
ok( false !== strpos( $src( 'assets/desktop-mode-widget-anchors.js' ), "archive = null; // a refresh" ) && false !== strpos( $src( 'assets/desktop-mode-widget-anchors.js' ), "[ 'Internet Archive', runText, halted || ! archive.configured ]" ) && false !== strpos( $src( 'assets/desktop-mode-widget-anchors.js' ), "' no answer'" ) && false !== strpos( $src( 'assets/desktop-mode-widget-anchors.js' ), "'every note asked'" ), 'the widget clears its archive reading at each load and paints it as rows: each run state in its own words, unconfirmed apart from waiting, unconfigured said' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
