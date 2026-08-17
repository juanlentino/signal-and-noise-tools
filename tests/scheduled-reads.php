<?php
/** Standalone tests for the R6a scheduled read-only agent runs. */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_MCP_DOOR_READ', 'read' );

$GLOBALS['__options']  = array();
$GLOBALS['__settings'] = array();
$GLOBALS['__cron']     = array();
$GLOBALS['__calls']    = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }
function add_action() {}
function sn_setting( $path, $default = null ) { return $GLOBALS['__settings'][ $path ] ?? $default; }
function wp_next_scheduled( $hook ) { return $GLOBALS['__cron'][ $hook ]['timestamp'] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { $GLOBALS['__cron'][ $hook ] = compact( 'timestamp', 'recurrence' ); return true; }
function wp_unschedule_event( $timestamp, $hook ) { unset( $GLOBALS['__cron'][ $hook ] ); return true; }
$GLOBALS['__current_user'] = 0;
$GLOBALS['__kill_engaged'] = false;
function get_current_user_id() { return $GLOBALS['__current_user']; }
function wp_set_current_user( $id ) { $GLOBALS['__current_user'] = (int) $id; }
function get_users( $args ) { return $GLOBALS['__admins'] ?? array( 7 ); }
function sn_mcp_read_kill_switch_engaged() { return $GLOBALS['__kill_engaged']; }
// Models the REAL sn_mcp_call_tool boundary: ('result' => tool result array,
// with 'isError' => true on failure) — see sn_mcp_error_result(). Captures
// the current user at call time so the cron-elevation pin sees what the
// abilities' permission callbacks would see.
function sn_mcp_call_tool( $name, $args, $door ) {
	$GLOBALS['__calls'][] = compact( 'name', 'args', 'door' ) + array( 'as_user' => $GLOBALS['__current_user'] );
	if ( 'signal-noise__anchor-status' === $name && ! empty( $GLOBALS['__fail_anchor'] ) ) {
		return array( 'result' => array( 'content' => array(), 'isError' => true ) );
	}
	return array( 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => '{}' ) ) ) );
}

require __DIR__ . '/../inc/scheduled-reads.php';

$pass = 0; $fail = 0;
function ok( $condition, $message ) { global $pass, $fail; if ( $condition ) { $pass++; echo "  PASS: $message\n"; } else { $fail++; echo "  FAIL: $message\n"; } }

echo "\nTest: the run list is fixed, read-only, byte-pinned\n";
$expected = array(
	'signal-noise__get-health-scan'       => array(),
	'signal-noise__uptime-status'         => array(),
	'signal-noise__get-deploy-status'     => array(),
	'signal-noise__anchor-status'         => array(),
	'signal-noise__get-analytics-summary' => array(),
);
ok( $expected === snt_scheduled_reads_tools(), 'the tool list is exactly the five read-door names — any change is a reviewed event' );
$write_shaped = 0;
foreach ( array_keys( snt_scheduled_reads_tools() ) as $name ) {
	// Verb list covers the LIVE write slugs too: anchor-sweep, merge-tags,
	// run-cron/run-audit, clear-template-overrides, the *-apply family.
	if ( preg_match( '/apply|purge|update|regenerate|prune|dismiss|unschedule|sweep|merge|run-|clear-/', $name ) ) { $write_shaped++; }
}
ok( 0 === $write_shaped, 'no write-shaped tool name in the run list' );

echo "\nTest: a run goes through the read door and records outcomes\n";
$run = snt_scheduled_reads_run();
ok( 5 === count( $GLOBALS['__calls'] ), 'one call per listed tool' );
$doors = array_unique( array_column( $GLOBALS['__calls'], 'door' ) );
ok( array( 'read' ) === $doors, 'every call is pinned to the read door' );
ok( false === $run['tools']['signal-noise__get-health-scan']['error'], 'a successful read records error=false' );
$history = get_option( SNT_SCHEDULED_READS_HISTORY );
ok( is_array( $history ) && 1 === count( $history ) && $history[0]['ran_at'] === $run['ran_at'], 'the run lands in history' );

echo "\nTest: an isError result records as a failed read, run still completes\n";
$GLOBALS['__fail_anchor'] = true;
$run = snt_scheduled_reads_run();
ok( true === $run['tools']['signal-noise__anchor-status']['error'], 'isError result records error=true' );
ok( false === $run['tools']['signal-noise__get-analytics-summary']['error'], 'tools after the failing one still run' );
$GLOBALS['__fail_anchor'] = false;

echo "\nTest: history caps at " . SNT_SCHEDULED_READS_HISTORY_CAP . "\n";
for ( $i = 0; $i < 20; $i++ ) { snt_scheduled_reads_run(); }
ok( SNT_SCHEDULED_READS_HISTORY_CAP === count( get_option( SNT_SCHEDULED_READS_HISTORY ) ), 'history never exceeds the cap' );

echo "\nTest: the kill switch governs scheduled runs (it is NOT inside sn_mcp_call_tool)\n";
$GLOBALS['__calls'] = array();
$GLOBALS['__kill_engaged'] = true;
$run = snt_scheduled_reads_run();
ok( true === ( $run['kill_switch'] ?? false ) && array() === $run['tools'], 'an engaged read kill switch records a skipped run' );
ok( 0 === count( $GLOBALS['__calls'] ), 'no tool executes past a darkened read door' );
ok( ( get_option( SNT_SCHEDULED_READS_HISTORY )[0]['kill_switch'] ?? false ) === true, 'the skipped run is visible in history, not silent' );
$GLOBALS['__kill_engaged'] = false;

echo "\nTest: the cron callback assumes and releases the owner identity\n";
// WP-Cron has no user and every listed ability gates on manage_options — an
// anonymous nightly run would refuse all five reads forever (found in review).
$GLOBALS['__calls'] = array();
$GLOBALS['__current_user'] = 0;
snt_scheduled_reads_daily_cron_cb();
ok( 5 === count( $GLOBALS['__calls'] ), 'cron fires the full list' );
$as_users = array_unique( array_column( $GLOBALS['__calls'], 'as_user' ) );
ok( array( 7 ) === $as_users, 'every cron call executes as the first administrator, never anonymous' );
ok( 0 === get_current_user_id(), 'the previous (no-user) identity is restored after the run' );
$GLOBALS['__admins'] = array();
$GLOBALS['__calls']  = array();
snt_scheduled_reads_daily_cron_cb();
ok( 0 === count( $GLOBALS['__calls'] ), 'no administrator account: the cron declines to run rather than running anonymous' );
unset( $GLOBALS['__admins'] );

echo "\nTest: cron lifecycle\n";
ok( false === snt_scheduled_reads_enabled(), 'disabled by default — a new cron surface is opt-in' );
snt_scheduled_reads_maybe_schedule_cron();
ok( array() === $GLOBALS['__cron'], 'disabled: nothing schedules' );
$GLOBALS['__settings']['operations.scheduled_reads_enabled'] = true;
snt_scheduled_reads_maybe_schedule_cron();
ok( 'daily' === ( $GLOBALS['__cron'][ SNT_SCHEDULED_READS_CRON_HOOK ]['recurrence'] ?? '' ), 'enabled: schedules daily' );
snt_scheduled_reads_maybe_schedule_cron();
ok( 1 === count( $GLOBALS['__cron'] ), 'scheduling is idempotent' );
$GLOBALS['__settings']['operations.scheduled_reads_enabled'] = false;
snt_scheduled_reads_maybe_schedule_cron();
ok( false === wp_next_scheduled( SNT_SCHEDULED_READS_CRON_HOOK ), 'disabling unschedules' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
