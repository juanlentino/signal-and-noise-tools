<?php
/**
 * Standalone test: a provenance commit whose settle-window dispatch event was
 * lost is re-enqueued within minutes (inc/provenance-orphan-dispatch.php).
 *
 * The case it pins (2026-10-16): note 2430 v2, written at 02:15:10 UTC. Its
 * sn_prov_dispatch_async event (args [2430]) never fired and was not scheduled
 * afterwards, so the commit sat in Recording until the hourly sweep. Saves
 * 10 to 35 seconds later dispatched normally.
 *
 * Run: php tests/provenance-orphan-dispatch.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
const SN_PROV_UID_META            = '_sn_prov_uid';
const SN_PROV_DISPATCH_ASYNC_HOOK = 'sn_prov_dispatch_async';
const MINUTE_IN_SECONDS           = 60;
const HOUR_IN_SECONDS             = 3600;

$GLOBALS['__actions']   = array();
$GLOBALS['__scheduled'] = array();   // hook => [ args_json => ts ]
$GLOBALS['__enqueued']  = array();
$GLOBALS['__chains']    = array();
$GLOBALS['__posts']     = array();
$GLOBALS['__options']   = array();
$GLOBALS['__get_posts'] = array();
$GLOBALS['__config']    = array( 'url' => 'https://prov.example.org', 'secret' => 's3cret' );

function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $c; }
function add_filter( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $c; }
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['__scheduled'][ $hook ][ json_encode( $args ) ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['__scheduled'][ $hook ][ json_encode( $args ) ] = $ts; return true; }
function get_posts( $q ) { $GLOBALS['__get_posts'][] = $q; return array_keys( $GLOBALS['__posts'] ); }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function sn_prov_get_chain( $id ) { return $GLOBALS['__chains'][ (int) $id ] ?? array(); }
function sn_prov_settle_seconds() { return 300; }
function sn_prov_worker_url() { return $GLOBALS['__config']['url']; }
function sn_prov_hmac_secret() { return $GLOBALS['__config']['secret']; }
function sn_prov_url_allowed( $u ) { return 0 === strpos( (string) $u, 'https://' ); }
function sn_prov_enqueue_dispatch( $id ) { $GLOBALS['__enqueued'][] = (int) $id; }

require_once __DIR__ . '/../inc/provenance-orphan-dispatch.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$now = 1792203310 + 25 * 60; // 02:40:10 UTC, 25 minutes after 2430 v2 was written.
$at  = static function ( $ts ) { return gmdate( 'Y-m-d\TH:i:s\Z', $ts ); };
$commit = static function ( array $over = array() ) use ( $at ) {
	return array_merge( array( 'version' => 2, 'status' => 'unanchored', 'committed_at' => $at( 1792203310 ) ), $over );
};

echo "Group 1: the predicate\n";
ok( true === sn_prov_commit_is_orphaned( $commit(), false, $now, 300 ), 'the 2430 shape: unanchored, never attempted, nothing scheduled, 25 minutes old: orphaned' );
ok( false === sn_prov_commit_is_orphaned( $commit(), 1792203610, $now, 300 ), 'a dispatch event still scheduled: not orphaned (the settle window is doing its job)' );
ok( false === sn_prov_commit_is_orphaned( $commit( array( 'committed_at' => $at( $now - 300 ) ) ), false, $now, 300 ), 'inside settle + grace (5 min old): not yet, the event may be running right now' );
ok( true === sn_prov_commit_is_orphaned( $commit( array( 'committed_at' => $at( $now - 300 - SN_PROV_ORPHAN_GRACE ) ) ), false, $now, 300 ), '...and orphaned exactly at settle + grace' );
ok( false === sn_prov_commit_is_orphaned( $commit( array( 'dispatch_attempted' => $now - 600 ) ), false, $now, 300 ), 'a dispatch already attempted (response lost or refused by the Worker): not this check\'s business, the hourly sweep retries it' );
ok( false === sn_prov_commit_is_orphaned( $commit( array( 'dispatch_refused' => $now - 600 ) ), false, $now, 300 ), 'a dispatch refused on our side (subject kind unresolved): retrying every five minutes would only refuse again' );
ok( false === sn_prov_commit_is_orphaned( $commit( array( 'signature' => 'abc' ) ), false, $now, 300 ), 'a signed commit is published, whatever its status says' );
foreach ( array( 'pending', 'confirmed' ) as $s ) {
	ok( false === sn_prov_commit_is_orphaned( $commit( array( 'status' => $s ) ), false, $now, 300 ), "status $s: not orphaned" );
}
ok( false === sn_prov_commit_is_orphaned( $commit( array( 'version' => 0 ) ), false, $now, 300 ), 'the v0 genesis sentinel is never dispatched by this path' );
ok( false === sn_prov_commit_is_orphaned( $commit( array( 'committed_at' => 'garbage' ) ), false, $now, 300 ), 'an unreadable committed_at is not old: unknown never triggers' );
ok( false === sn_prov_commit_is_orphaned( 'x', false, $now, 300 ), 'a malformed entry is not orphaned' );

echo "\nGroup 2: the sweep\n";
$reset = static function () { $GLOBALS['__enqueued'] = array(); $GLOBALS['__get_posts'] = array(); };
$GLOBALS['__posts'] = array(
	2430 => (object) array( 'ID' => 2430, 'post_password' => '' ),
	2088 => (object) array( 'ID' => 2088, 'post_password' => '' ),
	1943 => (object) array( 'ID' => 1943, 'post_password' => 'secret' ),
	1743 => (object) array( 'ID' => 1743, 'post_password' => '' ),
);
$GLOBALS['__chains'] = array(
	2430 => array( array( 'version' => 1, 'status' => 'confirmed' ), $commit() ),
	2088 => array( array( 'version' => 1, 'status' => 'confirmed' ), $commit( array( 'status' => 'pending', 'dispatch_attempted' => 1 ) ) ),
	1943 => array( $commit() ),
	1743 => array( $commit() ),
);
$GLOBALS['__scheduled'][ SN_PROV_DISPATCH_ASYNC_HOOK ][ json_encode( array( 1743 ) ) ] = $now + 60;
$reset();
$out = sn_prov_orphan_sweep( $now );
ok( array( 2430 ) === $GLOBALS['__enqueued'], 'only 2430 is re-enqueued: 2088 is pending, 1943 is password-protected, 1743 still has its event (' . implode( ',', $GLOBALS['__enqueued'] ) . ')' );
ok( array( array( 'post_id' => 2430, 'version' => 2 ) ) === $out, 'the sweep returns what it re-enqueued' );
$q = $GLOBALS['__get_posts'][0] ?? array();
ok( 'publish' === ( $q['post_status'] ?? '' ) && SN_PROV_UID_META === ( $q['meta_key'] ?? '' ) && 'ids' === ( $q['fields'] ?? '' ), 'reads published signed subjects only, ids only (the hourly sweep\'s population)' );
ok( isset( $q['date_query'][0]['after'] ) && 'post_modified_gmt' === ( $q['date_query'][0]['column'] ?? '' ), 'bounded to recently modified posts: an older orphan is the hourly sweep\'s, and this runs every five minutes' );
$log = get_option( SN_PROV_ORPHAN_LOG_OPTION, array() );
ok( is_array( $log ) && 1 === count( $log ) && 2430 === $log[0]['post_id'] && 2 === $log[0]['version'] && $now === $log[0]['at'], 'each recovery is logged ({post_id, version, at}) so a recurrence is visible, not silent' );

echo "\nGroup 3: never a loop\n";
$GLOBALS['__config']['secret'] = '';
$reset();
ok( array() === sn_prov_orphan_sweep( $now ) && array() === $GLOBALS['__enqueued'] && array() === $GLOBALS['__get_posts'], 'no Worker secret: nothing is enqueued and nothing is even read (a dispatch would return before marking the commit, and the check would re-enqueue it every five minutes forever)' );
$GLOBALS['__config'] = array( 'url' => 'http://internal.local', 'secret' => 's' );
$reset();
ok( array() === sn_prov_orphan_sweep( $now ), 'a Worker URL the outbound gate refuses: same' );
$GLOBALS['__config'] = array( 'url' => 'https://prov.example.org', 'secret' => 's3cret' );
for ( $i = 0; $i < 30; $i++ ) { sn_prov_orphan_log( $i, 1, $now ); }
ok( SN_PROV_ORPHAN_LOG_MAX === count( get_option( SN_PROV_ORPHAN_LOG_OPTION ) ) && 29 === get_option( SN_PROV_ORPHAN_LOG_OPTION )[0]['post_id'], 'the log is a bounded ring, newest first' );

echo "\nGroup 4: wiring\n";
ok( in_array( 'sn_prov_orphan_sweep_cb', $GLOBALS['__actions'][ SN_PROV_ORPHAN_HOOK ] ?? array(), true ), 'the callback is on its own hook' );
$GLOBALS['__scheduled'] = array();
sn_prov_orphan_schedule();
ok( false !== wp_next_scheduled( SN_PROV_ORPHAN_HOOK ), 'scheduled on init when absent' );
$first = wp_next_scheduled( SN_PROV_ORPHAN_HOOK );
sn_prov_orphan_schedule();
ok( $first === wp_next_scheduled( SN_PROV_ORPHAN_HOOK ), 'and left alone when present (no write to the cron option on every request)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
