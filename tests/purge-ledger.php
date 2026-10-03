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
function add_option( $k, $v ) { if ( isset( $GLOBALS['opt'][ $k ] ) ) { return false; } $GLOBALS['opt'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opt'][ $k ] ); return true; }
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
$GLOBALS['snt_purge_edge'] = true; // sn_cf_purge_everything() dispatched.
snt_purge_ledger_close();
$r = snt_purge_ledger_rows()[0];
ok( 'update' === $r['trigger'] && false === $r['redis'] && true === $r['pages'] && true === $r['edge'] && 'skipped: page-only purge' === $r['cloudways'], 'an update row: no Redis flush, pages and edge cleared, Cloudways stood down' );
ok( ! isset( $GLOBALS['snt_purge_current'] ), 'the open row is closed' );
snt_purge_ledger_open( array( 'trigger' => 'update' ) );
snt_purge_ledger_close();
ok( false === snt_purge_ledger_rows()[0]['edge'], 'a purge whose Cloudflare leg never dispatched records edge false (the arg only asked)' );
ok( 'db' === $r['transients'], 'without flush_group the row says the transients could only be cleared in the database' );
for ( $i = 0; $i < 60; $i++ ) { snt_purge_ledger_add( array( 'trigger' => 'manual', 'redis' => true ) ); }
ok( SNT_PURGE_LEDGER_CAP === count( snt_purge_ledger_rows() ), 'the ring is capped at ' . SNT_PURGE_LEDGER_CAP );

echo "\nWhat asked\n";
ok( 'update' === snt_purge_trigger( array( 'trigger' => 'update' ) ), 'the theme names it (14.10.0)' );
$GLOBALS['doing'] = array( 'upgrader_process_complete' );
ok( 'update' === snt_purge_trigger(), 'an older theme, inside an update: update' );
$GLOBALS['doing'] = array( 'wp_after_insert_post' );
ok( 'publish' === snt_purge_trigger(), 'a first publish or post save: publish, not manual' );
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
function get_template() { return $GLOBALS['tpl'][0]; }
function wp_get_theme() { return new class { function get( $k ) { return $GLOBALS['tpl'][1]; } }; }
$GLOBALS['tpl'] = array( 'signal-and-noise', '14.9.0' );
$GLOBALS['wp_filter'] = array( 'upgrader_process_complete' => (object) array( 'callbacks' => array( 10 => array(
	'a' => array( 'function' => array( $other, 'on_update' ) ),
	'b' => array( 'function' => array( $breeze, 'breeze_after_plugin_bulk_upgrade' ) ),
) ) ) );
ok( snt_purge_breeze_update_purge_hooked(), 'control: found while hooked' );
ok( ! snt_purge_remove_breeze_update_purge() && snt_purge_breeze_update_purge_hooked(), 'an older theme (no replacement purge) keeps Breeze\'s hook' );
ok( ! snt_purge_theme_replaces_breeze( 'twentytwentyfive', '9.0' ), 'another theme keeps it too' );
$GLOBALS['tpl'] = array( 'signal-and-noise', '15.0.1' );
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
ok( 2 === substr_count( $src, "snt_purge_ran_recently( 'update', 15 * MINUTE_IN_SECONDS )" ) && false !== strpos( $src, "'object_cache' => false, 'trigger' => 'rollover'" ), 'the rollover checks it when queued AND when it runs (a delayed cron), and never flushes Redis' );
$js = (string) file_get_contents( __DIR__ . '/../assets/freshness-dot.js' );
ok( preg_match( "/return cssLoads\\(canonHtml\\)\\.then\\(function \\(ok\\) \\{\\s*if \\(!ok\\) \\{ return 'broken'; \\}\\s*return stale \\? 'stale' : 'fresh';/", $js ), 'the card checks the stylesheet even when the renders agree' );

echo "\nTwo writers do not drop each other's row\n";
$GLOBALS['opt'] = array( SNT_PURGE_LEDGER_OPTION . '_lock' => time() - 60 );
snt_purge_ledger_add( array( 'trigger' => 'update' ) );
ok( 1 === count( snt_purge_ledger_rows() ) && ! isset( $GLOBALS['opt'][ SNT_PURGE_LEDGER_OPTION . '_lock' ] ), 'a crashed writer\'s stale lock is taken over, the row lands, the lock is released' );
$src = (string) file_get_contents( __DIR__ . '/../inc/purge-ledger.php' );
ok( false !== strpos( $src, 'if ( add_option( $lock' ) && false !== strpos( $src, "if ( ! \$have ) {\n\t\treturn;" ), 'the append holds a lock, and never writes or releases without it' );

echo "\nThe refreshing window counts from an edge purge\n";
$GLOBALS['opt'] = array();
snt_purge_ledger_add( array( 'trigger' => 'update', 'edge' => true ) );
$GLOBALS['opt'][ SNT_PURGE_LEDGER_OPTION ][0]['time'] = 1000;
snt_purge_ledger_add( array( 'trigger' => 'cron:x', 'edge' => false ) );
ok( 1000 === snt_purge_ledger_last_edge(), 'a newer purge that never reached the edge does not count' );

echo "\nA full ring inside the week says its counts are a floor\n";
$GLOBALS['opt'] = array();
for ( $i = 0; $i < SNT_PURGE_LEDGER_CAP; $i++ ) { snt_purge_ledger_add( array( 'trigger' => 'manual' ) ); }
ok( true === snt_purge_ledger_summary()['last_7_days_is_floor'], '50 purges inside the week: at least 50' );
$GLOBALS['opt'][ SNT_PURGE_LEDGER_OPTION ][ SNT_PURGE_LEDGER_CAP - 1 ]['time'] = time() - 8 * DAY_IN_SECONDS;
ok( false === snt_purge_ledger_summary()['last_7_days_is_floor'], 'control: the oldest row is older than the week, so the count is exact' );

echo "\nCloudways on its own (Breeze's buttons) still writes a row\n";
require __DIR__ . '/../inc/cloudways-purge.php';
$GLOBALS['opt'] = array();
unset( $GLOBALS['snt_purge_current'] );
snt_cloudways_note( 'inconclusive http 0' );
ok( 'unknown' === snt_purge_ledger_rows()[0]['redis'], 'a timeout may still have emptied Redis: unknown, never no' );
snt_cloudways_note( 'not configured' );
ok( 'not configured' === snt_purge_ledger_rows()[0]['cloudways'] && false === snt_purge_ledger_rows()[0]['redis'], 'unconfigured Cloudways still leaves a row' );
ok( 1 === snt_purge_ledger_summary()['redis_flushes_7d'], 'the unknown one counts as a possible Redis flush' );

echo "\nA Cloudflare zone purge on its own (admin bar, first publish, a schedule) is a row\n";
function wp_remote_post() { return array( "response" => array( "code" => 200 ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
require __DIR__ . '/../inc/cloudflare-purge.php';
$GLOBALS['opt'] = array( SN_CF_TOKEN_OPT => 't', SN_CF_ZONE_OPT => 'z' );
unset( $GLOBALS['snt_purge_current'] );
$cf_ok = false;
try { $cf_ok = sn_cf_purge_everything(); } catch ( \Throwable $e ) { echo "  (harness: " . $e->getMessage() . ")\n"; }
$row = snt_purge_ledger_rows()[0] ?? array();
ok( $cf_ok && true === ( $row['edge'] ?? null ) && false === ( $row['redis'] ?? null ), 'a direct zone purge writes its own row, edge true, no Redis' );
snt_purge_ledger_open( array( 'trigger' => 'update' ) );
$before = count( snt_purge_ledger_rows() );
sn_cf_purge_everything();
ok( $before === count( snt_purge_ledger_rows() ) && ! empty( $GLOBALS['snt_purge_edge'] ), 'inside the chain it marks that row instead of adding one' );
unset( $GLOBALS['snt_purge_current'], $GLOBALS['snt_purge_edge'] );

$cfsrc = (string) file_get_contents( __DIR__ . '/../inc/cloudflare-purge.php' );
$vfn = substr( $cfsrc, (int) strpos( $cfsrc, 'function sn_cf_purge_everything_verified()' ), 1500 );
ok( false !== strpos( $vfn, "if ( ! empty( \$out['cf_success'] ) && isset( \$GLOBALS['snt_purge_current'] ) ) {" ) && false !== strpos( $vfn, "\$GLOBALS['snt_purge_edge'] = true;" ), 'the manual (verified) purge marks its row\'s edge when Cloudflare confirms' );

echo "\nThe summary\n";
$GLOBALS['opt'] = array();
snt_purge_ledger_add( array( 'trigger' => 'update', 'redis' => false ) );
snt_purge_ledger_add( array( 'trigger' => 'manual', 'redis' => true ) );
$s = snt_purge_ledger_summary();
ok( 2 === $s['last_7_days'] && array( 'manual' => 1, 'update' => 1 ) === $s['by_trigger'] && 1 === $s['redis_flushes_7d'], 'counts by trigger and Redis flushes' );
ok( 'removed' === $s['breeze_update_purge'] && 'off' === $s['breeze_nightly'], 'the two settings that keep purges rare read removed and off' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
