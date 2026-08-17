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
// Models the REAL sn_mcp_call_tool boundary: ('result' => tool result array,
// with 'isError' => true on failure) — see sn_mcp_error_result().
function sn_mcp_call_tool( $name, $args, $door ) {
	$GLOBALS['__calls'][] = compact( 'name', 'args', 'door' );
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
foreach ( array_keys( snt_scheduled_reads_tools() ) as $name ) {
	if ( preg_match( '/apply|purge|update|regenerate|prune|dismiss|unschedule/', $name ) ) { ok( false, "write-shaped name in the run list: $name" ); }
}
ok( true, 'no write-shaped tool name in the run list' );

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
