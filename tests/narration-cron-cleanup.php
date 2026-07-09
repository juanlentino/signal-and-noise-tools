<?php
/**
 * Standalone fixture tests for inc/narration-cron-cleanup.php.
 *
 * v9.5.0 retired the weekly-digest scheduler (annotations Release 2). Any install
 * that had the opt-in cron enabled is left with an orphaned sn_insights_narration_weekly
 * event pointing at a callback that no longer schedules it. This one-time admin_init
 * routine clears that event, gated by a version sentinel so it fires exactly once.
 *
 * Run: php tests/narration-cron-cleanup.php
 * @since plugin v9.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   — $label\n"; }
	else { $fail++; echo "  FAIL — $label\n"; }
}
function eq( $expected, $actual, $label ) {
	ok( $expected === $actual, $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

// ── option store ──
$GLOBALS['__options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
}

// ── cron store: record clears + model a scheduled event ──
$GLOBALS['__cron']    = array( 'sn_insights_narration_weekly' => 1234567890 ); // pretend it was scheduled
$GLOBALS['__cleared'] = array();
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $h, $a = array() ) { return $GLOBALS['__cron'][ $h ] ?? false; }
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $h, $a = array() ) {
		$GLOBALS['__cleared'][] = $h;
		$existed = isset( $GLOBALS['__cron'][ $h ] );
		unset( $GLOBALS['__cron'][ $h ] );
		return $existed ? 1 : 0;
	}
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }

require dirname( __DIR__ ) . '/inc/narration-cron-cleanup.php';

// ── Scenario 1: fresh install (sentinel absent) → clears the orphan + stamps ──
echo "Test 1: first run clears the orphaned cron and stamps the sentinel\n";
sn_narration_cron_cleanup_maybe_run();
ok( in_array( 'sn_insights_narration_weekly', $GLOBALS['__cleared'], true ), 'clears sn_insights_narration_weekly on first run' );
eq( 1, count( $GLOBALS['__cleared'] ), 'exactly one clear call' );
eq( '9.5.0', get_option( 'sn_narration_cron_cleaned', '' ), 'sentinel stamped to 9.5.0' );
ok( false === wp_next_scheduled( 'sn_insights_narration_weekly' ), 'the orphaned event is gone afterward' );

// ── Scenario 2: second run → no-op (sentinel already 9.5.0) ──
echo "\nTest 2: second run is a no-op (idempotent via the sentinel)\n";
$GLOBALS['__cleared'] = array();
$GLOBALS['__cron']    = array( 'sn_insights_narration_weekly' => 1234567890 ); // even if it somehow reappears
sn_narration_cron_cleanup_maybe_run();
eq( 0, count( $GLOBALS['__cleared'] ), 'second run does NOT re-clear' );

// ── Scenario 3: a non-9.5.0 sentinel value still triggers the cleanup (stale/empty) ──
echo "\nTest 3: a stale/empty sentinel re-runs the cleanup\n";
$GLOBALS['__options']['sn_narration_cron_cleaned'] = '';
$GLOBALS['__cleared'] = array();
sn_narration_cron_cleanup_maybe_run();
ok( in_array( 'sn_insights_narration_weekly', $GLOBALS['__cleared'], true ), 'runs when the sentinel is not 9.5.0' );
eq( '9.5.0', get_option( 'sn_narration_cron_cleaned', '' ), 're-stamps the sentinel' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
