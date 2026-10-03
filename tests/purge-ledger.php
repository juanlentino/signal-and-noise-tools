<?php
/**
 * Guard: the purge ledger and the rules that keep purges rare (20.7.0).
 *
 * A row per purge, capped; the Cloudways app purge (it empties all of Redis)
 * runs only on a purge-everything; Breeze's own update purge is unhooked;
 * the rollover skips after an update purge.
 *
 * Run: php tests/purge-ledger.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['opt'] = array(); $GLOBALS['doing'] = array(); $GLOBALS['cron'] = false; $GLOBALS['sched'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function add_action() {}
function doing_action( $h ) { return in_array( $h, $GLOBALS['doing'], true ); }
function wp_doing_cron() { return $GLOBALS['cron']; }
function current_action() { return end( $GLOBALS['doing'] ) ?: ''; }
function wp_next_scheduled( $h ) { return $GLOBALS['sched'][ $h ] ?? false; }
function remove_action( $hook, $fn, $prio ) {
	global $wp_filter;
	foreach ( $wp_filter[ $hook ]->callbacks[ $prio ] as $k => $cb ) {
		if ( $cb['function'] === $fn ) { unset( $wp_filter[ $hook ]->callbacks[ $prio ][ $k ] ); return true; }
	}
	return false;
}

require __DIR__ . '/../inc/purge-ledger.php';

echo "A row per purge\n";
snt_purge_ledger_open( array( 'object_cache' => false, 'trigger' => 'update' ) );
$GLOBALS['snt_purge_cloudways'] = 'skipped: page-only purge';
snt_purge_ledger_close();
$r = snt_purge_ledger_rows()[0];
ok( 'update' === $r['trigger'] && false === $r['redis'] && true === $r['pages'] && true === $r['edge'] && 'skipped: page-only purge' === $r['cloudways'], 'an update row: no Redis flush, pages and edge cleared, Cloudways stood down' );
ok( ! isset( $GLOBALS['snt_purge_current'] ), 'the open row is closed' );
for ( $i = 0; $i < 60; $i++ ) { snt_purge_ledger_add( array( 'trigger' => 'manual', 'redis' => true ) ); }
ok( SNT_PURGE_LEDGER_CAP === count( snt_purge_ledger_rows() ), 'the ring is capped at ' . SNT_PURGE_LEDGER_CAP );

echo "\nWhat asked\n";
ok( 'update' === snt_purge_trigger( array( 'trigger' => 'update' ) ), 'the theme names it (14.10.0)' );
$GLOBALS['doing'] = array( 'upgrader_process_complete' );
ok( 'update' === snt_purge_trigger(), 'an older theme, inside an update: update' );
$GLOBALS['doing'] = array( 'snt_deploy_history_purge_rollover' ); $GLOBALS['cron'] = true;
ok( 'cron:snt_deploy_history_purge_rollover' === snt_purge_trigger(), 'a cron names its hook' );
$GLOBALS['doing'] = array(); $GLOBALS['cron'] = false;
ok( 'manual' === snt_purge_trigger(), 'otherwise manual' );

echo "\nThe Cloudways app purge (it empties Redis) runs only on a purge-everything\n";
snt_purge_ledger_open( array( 'object_cache' => false, 'trigger' => 'update' ) );
ok( ! snt_purge_wants_app_purge(), 'an update stands it down' );
snt_purge_ledger_open( array( 'trigger' => 'manual' ) );
ok( snt_purge_wants_app_purge(), 'the manual purge-everything runs it' );
unset( $GLOBALS['snt_purge_current'] );
ok( snt_purge_wants_app_purge(), 'outside our chain (Breeze\'s Purge Varnish button) it runs' );
$GLOBALS['doing'] = array( 'breeze_purge_cache' );
ok( ! snt_purge_wants_app_purge(), 'Breeze\'s nightly purge never runs it' );
$GLOBALS['doing'] = array();

$cw = (string) file_get_contents( __DIR__ . '/../inc/cloudways-purge.php' );
$gate = strpos( $cw, '! snt_purge_wants_app_purge()' );
ok( false !== $gate && $gate < strpos( $cw, "'/app/cache/purge'" ), 'the Cloudways leg asks before it dispatches' );

echo "\nBreeze's own update purge is unhooked (5a)\n";
class Breeze_Bulk_Update { function breeze_after_plugin_bulk_upgrade() {} }
class Other_Plugin { function on_update() {} }
$breeze = new Breeze_Bulk_Update(); $other = new Other_Plugin();
$GLOBALS['wp_filter'] = array( 'upgrader_process_complete' => (object) array( 'callbacks' => array( 10 => array(
	'a' => array( 'function' => array( $other, 'on_update' ) ),
	'b' => array( 'function' => array( $breeze, 'breeze_after_plugin_bulk_upgrade' ) ),
) ) ) );
ok( snt_purge_breeze_update_purge_hooked(), 'control: found while hooked' );
ok( snt_purge_remove_breeze_update_purge(), 'removed' );
ok( ! snt_purge_breeze_update_purge_hooked() && 1 === count( $GLOBALS['wp_filter']['upgrader_process_complete']->callbacks[10] ), 'gone, and the other plugin\'s callback stays' );
ok( ! snt_purge_remove_breeze_update_purge(), 'nothing to remove says false, never a silent success' );

echo "\nThe rollover skips after an update purge\n";
$GLOBALS['opt'] = array();
$now = 1800000000;
snt_purge_ledger_add( array( 'time' => $now - 120, 'trigger' => 'update' ) );
$GLOBALS['opt'][ SNT_PURGE_LEDGER_OPTION ][0]['time'] = $now - 120;
ok( snt_purge_ran_recently( 'update', 900, $now ), 'an update purge 2 minutes ago counts' );
ok( ! snt_purge_ran_recently( 'update', 900, $now + 1000 ), 'not after 15 minutes' );
ok( ! snt_purge_ran_recently( 'manual', 900, $now ), 'another trigger does not count' );
$src = (string) file_get_contents( __DIR__ . '/../inc/deploy-history.php' );
ok( false !== strpos( $src, "snt_purge_ran_recently( 'update', 15 * MINUTE_IN_SECONDS )" ) && false !== strpos( $src, "'object_cache' => false, 'trigger' => 'rollover'" ), 'the rollover checks it, and never flushes Redis when it does run' );

echo "\nThe summary\n";
$GLOBALS['opt'] = array();
snt_purge_ledger_add( array( 'trigger' => 'update', 'redis' => false ) );
snt_purge_ledger_add( array( 'trigger' => 'manual', 'redis' => true ) );
$s = snt_purge_ledger_summary();
ok( 2 === $s['last_7_days'] && array( 'manual' => 1, 'update' => 1 ) === $s['by_trigger'] && 1 === $s['redis_flushes_7d'], 'counts by trigger and Redis flushes' );
ok( 'removed' === $s['breeze_update_purge'] && 'off' === $s['breeze_nightly'], 'the two settings that keep purges rare read removed and off' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
