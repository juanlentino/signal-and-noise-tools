<?php
/**
 * Standalone fixture tests for inc/cron-dashboard.php.
 *
 * Matches the bot-detection.php precedent: bare-PHP, no PHPUnit, no
 * composer. Runnable as:
 *
 *     php tests/cron-dashboard.php
 *
 * Exits 0 on all-pass, 1 on any failure.
 *
 * Stubs only the WP functions the impl module actually calls. Tests
 * pure logic — REST + abilities + admin render layers get exercised
 * by the manual smoke test on live (per spec § 10.2).
 *
 * @since plugin v3.0.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

// ─── WP stubs ─────────────────────────────────────────────────────────
define( 'ABSPATH', '/' );

// In-memory option store the stubs read/write.
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_actions'] = array(); // hook => bool (has_action)
$GLOBALS['__test_cron_array'] = array();
$GLOBALS['__test_current_user_can'] = true;
$GLOBALS['__test_current_action'] = '';
$GLOBALS['__test_action_callbacks'] = array();

function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {
	// No-op for module load; specific tests can override via globals.
}

// v4.9.0: cron-dashboard.php now registers a Site Health filter + REST route
// at module scope (Task 2). These stubs let the module load under the harness.
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() {} }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://x/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

function _get_cron_array() {
	return $GLOBALS['__test_cron_array'];
}

function has_action( $hook ) {
	return isset( $GLOBALS['__test_actions'][ $hook ] ) && $GLOBALS['__test_actions'][ $hook ];
}

// v3.1.0: stubs for unschedule path. wp_clear_scheduled_hook returns
// the count of events cleared (WP 6.1+) or false/null on failure.
// We model the test fixture as a flat map of "hook|md5(args)" => count.
$GLOBALS['__test_scheduled'] = array();

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$key = $hook . '|' . md5( wp_json_encode( $args ) );
	$count = isset( $GLOBALS['__test_scheduled'][ $key ] ) ? (int) $GLOBALS['__test_scheduled'][ $key ] : 0;
	unset( $GLOBALS['__test_scheduled'][ $key ] );
	return $count;
}

// PHP's json_encode is sufficient for the test fixture; the impl itself
// doesn't reach wp_json_encode (only the test stub does, for keying).
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $val ) {
		return json_encode( $val );
	}
}

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__test_options'][ $key ] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}

function current_user_can( $cap ) {
	return $GLOBALS['__test_current_user_can'];
}

function current_action() {
	return $GLOBALS['__test_current_action'];
}

function do_action_ref_array( $hook, $args ) {
	// Test stub: invoke a registered callable if present.
	if ( isset( $GLOBALS['__test_action_callbacks'][ $hook ] ) ) {
		call_user_func_array( $GLOBALS['__test_action_callbacks'][ $hook ], $args );
	}
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_message() { return $this->message; }
}

require_once __DIR__ . '/../inc/cron-dashboard.php';

// ─── Test harness ─────────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function assert_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
		echo "    Expected: " . var_export( $expected, true ) . "\n";
		echo "    Actual:   " . var_export( $actual, true ) . "\n";
	}
}

function assert_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

// ─── Test 1: snt_cron_is_sn_owned ───────────────────────────────────
echo "\nTest 1: snt_cron_is_sn_owned\n";
assert_true( snt_cron_is_sn_owned( 'sn_plausible_refresh_dashboard' ), 'SN-owned dashboard hook recognized' );
assert_true( snt_cron_is_sn_owned( 'sn_rss_tracker_daily_prune' ), 'SN-owned RSS hook recognized' );
assert_eq( false, snt_cron_is_sn_owned( 'wp_version_check' ), 'WP core hook is not SN-owned' );
assert_eq( false, snt_cron_is_sn_owned( '' ), 'Empty string is not SN-owned' );

// ─── Test 2: last-fired round trip ───────────────────────────────────
echo "\nTest 2: last-fired round trip\n";
$GLOBALS['__test_options'] = array(); // reset
$now = time();
snt_cron_record_last_fired( 'my_test_hook' );
$got = snt_cron_last_fired_for( 'my_test_hook' );
assert_true( is_int( $got ) && $got >= $now, 'record + read round-trips an int >= now' );
assert_eq( null, snt_cron_last_fired_for( 'never_fired_hook' ), 'unknown hook returns null' );
assert_eq( null, snt_cron_last_fired_for( '' ), 'empty hook name returns null' );

// ─── Test 3: snt_cron_get_events_impl flat structure ─────────────────
echo "\nTest 3: snt_cron_get_events_impl flat structure\n";
$GLOBALS['__test_cron_array'] = array(
	1747936800 => array(
		'wp_version_check' => array(
			'sig_wp_version_check_a' => array( 'schedule' => 'twicedaily', 'args' => array(), 'interval' => 43200 ),
		),
	),
	1747940000 => array(
		'sn_rss_tracker_daily_prune' => array(
			'sig_sn_rss_a' => array( 'schedule' => 'daily', 'args' => array(), 'interval' => 86400 ),
		),
	),
);
$GLOBALS['__test_actions'] = array( 'wp_version_check' => true, 'sn_rss_tracker_daily_prune' => true );

$rows = snt_cron_get_events_impl();
assert_eq( 2, count( $rows ), 'returns 2 rows from 2-event fixture' );

// ─── Test 4: SN-owned events sort first ──────────────────────────────
echo "\nTest 4: SN-owned events sort first\n";
assert_eq( 'sn_rss_tracker_daily_prune', $rows[0]['hook'], 'SN-owned row sorts before wp_version_check despite later next_run_ts' );
assert_eq( true, $rows[0]['is_sn_owned'], 'first row is_sn_owned=true' );
assert_eq( false, $rows[1]['is_sn_owned'], 'second row is_sn_owned=false' );

// ─── Test 5: row schema ──────────────────────────────────────────────
echo "\nTest 5: row schema\n";
$row = $rows[0];
$required_keys = array( 'hook', 'args_signature', 'next_run_ts', 'schedule', 'interval_s', 'args', 'last_fired_ts', 'has_handler', 'is_sn_owned' );
foreach ( $required_keys as $k ) {
	assert_true( array_key_exists( $k, $row ), "row has '$k' key" );
}
assert_eq( true, $row['has_handler'], 'has_handler reflects has_action()' );

// ─── Test 6: sn_only filter ──────────────────────────────────────────
echo "\nTest 6: sn_only filter\n";
$sn_rows = snt_cron_get_events_impl( true );
assert_eq( 1, count( $sn_rows ), 'sn_only=true filters to 1 SN-owned row' );
assert_eq( 'sn_rss_tracker_daily_prune', $sn_rows[0]['hook'], 'filtered row is the SN hook' );

// ─── Test 7: empty cron array ────────────────────────────────────────
echo "\nTest 7: empty cron array\n";
$GLOBALS['__test_cron_array'] = array();
$empty = snt_cron_get_events_impl();
assert_eq( array(), $empty, 'empty cron returns empty array' );

// ─── Test 8: snt_cron_run_event_impl permission gate ─────────────────
echo "\nTest 8: snt_cron_run_event_impl permission gate\n";
$GLOBALS['__test_current_user_can'] = false;
$res = snt_cron_run_event_impl( 'any_hook' );
assert_true( $res instanceof WP_Error, 'non-admin gets WP_Error' );
assert_eq( 'snt_cron_forbidden', $res->code, 'error code is snt_cron_forbidden' );
$GLOBALS['__test_current_user_can'] = true;

// ─── Test 9: snt_cron_run_event_impl orphan-hook rejection ───────────
echo "\nTest 9: snt_cron_run_event_impl orphan-hook rejection\n";
$GLOBALS['__test_actions'] = array(); // no actions registered
$res = snt_cron_run_event_impl( 'no_such_handler_hook' );
assert_true( $res instanceof WP_Error, 'orphan hook gets WP_Error' );
assert_eq( 'snt_cron_no_handler', $res->code, 'error code is snt_cron_no_handler' );

// ─── Test 10: snt_cron_run_event_impl successful dispatch ────────────
echo "\nTest 10: snt_cron_run_event_impl successful dispatch\n";
$GLOBALS['__test_actions'] = array( 'sn_rss_tracker_daily_prune' => true );
$fired = false;
$GLOBALS['__test_action_callbacks']['sn_rss_tracker_daily_prune'] = function() use ( &$fired ) { $fired = true; };
$res = snt_cron_run_event_impl( 'sn_rss_tracker_daily_prune' );
assert_true( $fired, 'handler was invoked' );
assert_eq( true, $res['success'], 'success=true' );
assert_eq( 'sn_rss_tracker_daily_prune', $res['hook'], 'hook echoed back' );
// v3.0.1: response shape includes a server-formatted last_fired string
// for the JS to render without timezone drift. In this test scenario the
// stub's add_action is a no-op so last_fired_ts stays null after dispatch,
// meaning last_fired_formatted is also null — but the key MUST exist.
assert_true( array_key_exists( 'last_fired_formatted', $res ), 'response has last_fired_formatted key (v3.0.1)' );
assert_true( is_float( $res['elapsed_ms'] ) || is_int( $res['elapsed_ms'] ), 'elapsed_ms is numeric' );

// ─── Test 11: snt_cron_run_event_impl catches Throwable ──────────────
echo "\nTest 11: snt_cron_run_event_impl catches Throwable\n";
$GLOBALS['__test_actions'] = array( 'boom_hook' => true );
$GLOBALS['__test_action_callbacks']['boom_hook'] = function() { throw new RuntimeException( 'simulated handler failure' ); };
$res = snt_cron_run_event_impl( 'boom_hook' );
assert_eq( false, $res['success'], 'success=false on Throwable' );
assert_true( strpos( (string) $res['error'], 'simulated handler failure' ) !== false, 'error message captured' );

// ─── Test 12: snt_cron_unschedule_event_impl permission gate ─────────
echo "\nTest 12: snt_cron_unschedule_event_impl permission gate (v3.1.0)\n";
$GLOBALS['__test_current_user_can'] = false;
$res = snt_cron_unschedule_event_impl( 'any_hook' );
assert_true( $res instanceof WP_Error, 'non-admin gets WP_Error' );
assert_eq( 'snt_cron_forbidden', $res->code, 'forbidden error code' );
$GLOBALS['__test_current_user_can'] = true;

// ─── Test 13: invalid hook validation ────────────────────────────────
echo "\nTest 13: snt_cron_unschedule_event_impl invalid hook (v3.1.0)\n";
$res = snt_cron_unschedule_event_impl( '' );
assert_true( $res instanceof WP_Error, 'empty hook returns WP_Error' );
assert_eq( 'snt_cron_invalid_hook', $res->code, 'invalid-hook error code' );

// ─── Test 14: SN-owned refusal (the critical safety check) ───────────
echo "\nTest 14: snt_cron_unschedule_event_impl refuses SN-owned hooks (v3.1.0)\n";
$res = snt_cron_unschedule_event_impl( 'sn_plausible_refresh_dashboard' );
assert_true( $res instanceof WP_Error, 'SN-owned hook returns WP_Error' );
assert_eq( 'snt_cron_sn_owned_refused', $res->code, 'sn-owned-refused error code' );
$res = snt_cron_unschedule_event_impl( 'sn_rss_tracker_daily_prune' );
assert_eq( 'snt_cron_sn_owned_refused', $res->code, 'SN RSS hook also refused' );

// ─── Test 15: successful unschedule (cleared > 0) ────────────────────
echo "\nTest 15: snt_cron_unschedule_event_impl clears scheduled events (v3.1.0)\n";
$GLOBALS['__test_scheduled'] = array(
	'some_plugin_hook|' . md5( '[]' )    => 1,
	'another_hook|'    . md5( '[]' )    => 1,
);
$res = snt_cron_unschedule_event_impl( 'some_plugin_hook' );
assert_eq( true, $res['success'], 'success=true' );
assert_eq( 'some_plugin_hook', $res['hook'], 'hook echoed back' );
assert_eq( 1, $res['cleared'], 'cleared=1 (one event removed)' );
assert_true( ! isset( $GLOBALS['__test_scheduled']['some_plugin_hook|' . md5( '[]' ) ] ), 'event removed from fixture store' );

// ─── Test 16: no-match unschedule returns cleared=0, NOT an error ────
echo "\nTest 16: snt_cron_unschedule_event_impl no-match returns success/0 (v3.1.0)\n";
$res = snt_cron_unschedule_event_impl( 'never_scheduled_hook' );
assert_eq( true, $res['success'], 'no-match is still success=true (idempotency)' );
assert_eq( 0, $res['cleared'], 'cleared=0 when nothing matched' );

// ─── Test 17: orphan-allow path — unschedule works WITHOUT has_action ─
echo "\nTest 17: snt_cron_unschedule_event_impl works on orphan hooks (v3.1.0)\n";
// Orphan = present in cron but no registered handler. We must NOT refuse
// — pruning orphans is the whole point of having this op.
$GLOBALS['__test_actions'] = array(); // explicitly no handlers
$GLOBALS['__test_scheduled'] = array(
	'orphan_hook|' . md5( '[]' ) => 1,
);
$res = snt_cron_unschedule_event_impl( 'orphan_hook' );
assert_eq( true, $res['success'], 'orphan unschedule succeeds (no has_action gate)' );
assert_eq( 1, $res['cleared'], 'orphan event was cleared' );

// ─── Test 18: args round-trip ────────────────────────────────────────
echo "\nTest 18: snt_cron_unschedule_event_impl args round-trip (v3.1.0)\n";
$GLOBALS['__test_scheduled'] = array(
	'do_pings|' . md5( '[1587]' ) => 1,
);
$res = snt_cron_unschedule_event_impl( 'do_pings', array( 1587 ) );
assert_eq( 1, $res['cleared'], 'matching-args invocation clears the row' );
assert_eq( array( 1587 ), $res['args'], 'args echoed back in response' );

// ─── Test 19: args mismatch — different args = different signature ───
echo "\nTest 19: snt_cron_unschedule_event_impl args mismatch returns cleared=0 (v3.1.0)\n";
$GLOBALS['__test_scheduled'] = array(
	'do_pings|' . md5( '[1587]' ) => 1,
);
$res = snt_cron_unschedule_event_impl( 'do_pings', array( 9999 ) );
assert_eq( 0, $res['cleared'], 'wrong-args call does not clear the [1587] event' );
assert_true( isset( $GLOBALS['__test_scheduled']['do_pings|' . md5( '[1587]' ) ] ), 'original event still scheduled' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
