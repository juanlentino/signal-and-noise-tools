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

// (render hardening FIX 4): stubs for snt_cron_site_health_result(),
// previously untested by this suite. wp_next_scheduled / wp_get_schedule /
// wp_get_schedules are fixture-controllable maps so each scenario can pin a
// hook's "next run" and recurrence independently.
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return '/wp-admin/' . $p; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return abs( (int) $b - (int) $a ) . 's'; } }
$GLOBALS['__test_apply_filters'] = array(); // tag => return value (default-false unless set)
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value = null ) {
		return array_key_exists( $tag, $GLOBALS['__test_apply_filters'] ) ? $GLOBALS['__test_apply_filters'][ $tag ] : $value;
	}
}
$GLOBALS['__test_next_scheduled'] = array(); // hook => timestamp|false
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['__test_next_scheduled'][ $hook ] ?? false; }
}
$GLOBALS['__test_schedule_slug'] = array(); // hook => schedule slug
$GLOBALS['__test_schedules']     = array(); // slug => array( 'interval' => N )
if ( ! function_exists( 'wp_get_schedule' ) ) {
	function wp_get_schedule( $hook ) { return $GLOBALS['__test_schedule_slug'][ $hook ] ?? false; }
}
if ( ! function_exists( 'wp_get_schedules' ) ) {
	function wp_get_schedules() { return $GLOBALS['__test_schedules']; }
}

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
	// v13.49.0: the stub carried only get_error_message(), so a test asserting
	// WHICH refusal fired could not be written against it — and "returns some
	// WP_Error" is a much weaker claim than "returns snt_cron_not_sn_owned".
	// Modelling the real class's accessors, per the stub-parity rule.
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}

// v13.49.0: snt_cron_schedule_event_impl() checks wp_schedule_single_event()'s
// WP_Error return (WP 5.7+ returns one when a pre-filter blocks the booking),
// so the harness needs the real predicate rather than leaving the module to
// fatal on it.
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
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
assert_true( snt_cron_is_sn_owned( 'sn_rss_tracker_daily_prune' ), 'SN-owned RSS hook recognized' );
assert_eq( false, snt_cron_is_sn_owned( 'sn_plausible_refresh_dashboard' ), 'v6.0.0: retired Plausible hook is no longer SN-owned (stays cleanable)' );
assert_eq( false, snt_cron_is_sn_owned( 'wp_version_check' ), 'WP core hook is not SN-owned' );
assert_eq( false, snt_cron_is_sn_owned( '' ), 'Empty string is not SN-owned' );
// v6.39.5: the guard is now authoritative — EVERY active recurring SN hook is
// refused, not just RSS (a docblock claimed this but the list omitted 8 of 9).
// Retired hooks (sn_plausible_*, asserted above) stay cleanable.
assert_true( snt_cron_is_sn_owned( 'sn_analytics_rollup_daily' ), 'analytics daily rollup is SN-owned' );
// v11.32.1: the fleet warm became RECURRING in v11.32.0 and was not added to
// the list, so the unschedule ability would have cheerfully removed it — and
// the only symptom would have been worker cells drifting back to "warming…"
// with nothing on any screen explaining why. This registry is the guard; a
// recurring hook that is not in it is unprotected.
assert_true( snt_cron_is_sn_owned( 'snt_deploy_workers_warm' ), 'THE FLEET WARM IS SN-OWNED — a recurring hook we own must be refused by the unschedule guard' );
assert_true( snt_cron_is_sn_owned( 'sn_analytics_rollup' ), 'analytics on-demand warmer is SN-owned' );
assert_true( snt_cron_is_sn_owned( 'snt_cron_history_prune' ), 'cron-history prune is SN-owned (snt_ prefix)' );
assert_true( snt_cron_is_sn_owned( 'sn_audit_log_prune' ), 'audit-log prune is SN-owned' );
assert_true( snt_cron_is_sn_owned( 'sn_edge_rollup_cron' ), 'edge rollup is SN-owned' );
assert_true( snt_cron_is_sn_owned( 'sn_insights_weekly_scan' ), 'insights scan is SN-owned' );
// v9.5.0 (R2): the weekly-digest cron was retired, so it is no longer an SN-owned
// live hook — the unschedule-cron-event ability may now clear the orphan.
assert_true( ! snt_cron_is_sn_owned( 'sn_insights_narration_weekly' ), 'R2: retired narration cron is NOT SN-owned (orphan is removable)' );
// v12.19.0: sn_uptime_kuma_heartbeat was removed with the push heartbeat. Its
// ownership assertion is replaced by a hook that still ships.
assert_true( snt_cron_is_sn_owned( 'sn_analytics_rollup_daily' ), 'analytics rollup is SN-owned' );
assert_true( snt_cron_is_sn_owned( 'sn_discography_cron' ), 'discography sync is SN-owned' );

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

$__cron_fixture = $GLOBALS['__test_cron_array'];

// ─── Test 7: empty cron array ────────────────────────────────────────
echo "\nTest 7: empty cron array\n";
$GLOBALS['__test_cron_array'] = array();
$empty = snt_cron_get_events_impl();
assert_eq( array(), $empty, 'empty cron returns empty array' );

// ─── Test 7b: ZERO SCHEDULED EVENTS IS NOT A HEALTHY SITE ────────────
// v11.29.2. A WordPress install always carries core events (wp_version_check,
// wp_scheduled_delete, ...). A total of ZERO means the scheduler is disabled
// or the array was wiped — and the desktop widget rendered that green, because
// nothing was wrong with any event it could see. Absence of faults is not the
// same as evidence of running, which is the same mistake the purge verifier
// made for three months.
echo "\nTest 7b: zero scheduled events reads amber, not green\n";
$GLOBALS['__test_cron_array'] = array();
$sum_zero = snt_cron_summary_for_localize();
assert_eq( 0, $sum_zero['total'], 'the empty install reports zero events' );
assert_eq( 'warn', $sum_zero['state'], 'ZERO EVENTS IS AMBER — a scheduler with nothing scheduled is not a working scheduler' );
assert_true( '' !== (string) ( $sum_zero['note'] ?? '' ), 'and it says why, rather than showing a bare amber dot' );

$GLOBALS['__test_cron_array'] = $__cron_fixture;
$sum_ok = snt_cron_summary_for_localize();
assert_true( $sum_ok['total'] > 0, 'the populated fixture reports events' );
assert_eq( 'ok', $sum_ok['state'], 'a populated schedule with handlers reads ok' );
assert_eq( '', (string) ( $sum_ok['note'] ?? '' ), 'and carries no note' );

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
$res = snt_cron_unschedule_event_impl( 'sn_rss_tracker_daily_prune' );
assert_true( $res instanceof WP_Error, 'SN-owned hook returns WP_Error' );
assert_eq( 'snt_cron_sn_owned_refused', $res->code, 'sn-owned-refused error code' );

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

// ─── Test 20-25: snt_cron_site_health_result — 'critical' elevation (FIX 4) ───
// DISABLE_WP_CRON is defined ONCE (PHP constants can't be redefined); every
// scenario that needs to prove "NOT silently disabled" does so via the two
// other legs of the condition — a recent firing (hard evidence) or the
// sn_cron_system_cron_configured filter — exercising the full branch matrix
// without needing to toggle the constant itself.
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }

function fix4_reset_healthy_fixture() {
	$GLOBALS['__test_options']        = array();
	$GLOBALS['__test_next_scheduled'] = array();
	$GLOBALS['__test_schedule_slug']  = array();
	$GLOBALS['__test_schedules']      = array();
	$GLOBALS['__test_apply_filters']  = array();
	$now = time();
	foreach ( snt_cron_site_health_hooks() as $hook ) {
		$GLOBALS['__test_next_scheduled'][ $hook ] = $now + 3600; // scheduled, healthy, no interval tracked
	}
}

echo "\nTest 20: snt_cron_site_health_result — baseline good (all scheduled, system cron declared)\n";
fix4_reset_healthy_fixture();
$GLOBALS['__test_apply_filters']['sn_cron_system_cron_configured'] = true; // escapes silent-disabled despite DISABLE_WP_CRON=true
$r20 = snt_cron_site_health_result();
assert_eq( 'good', $r20['status'], 'all hooks scheduled + a declared system cron → good' );
assert_eq( 'Performance', $r20['badge']['label'] ?? null, 'badge stays Performance (unchanged by the elevation)' );

echo "\nTest 21: snt_cron_site_health_result — a NOT-scheduled expected hook → recommended, not critical\n";
fix4_reset_healthy_fixture();
$GLOBALS['__test_apply_filters']['sn_cron_system_cron_configured'] = true;
$hooks21 = snt_cron_site_health_hooks();
unset( $GLOBALS['__test_next_scheduled'][ $hooks21[0] ] ); // now reads false = not scheduled
$r21 = snt_cron_site_health_result();
assert_eq( 'recommended', $r21['status'], 'an unscheduled expected hook → recommended' );
assert_true( false !== strpos( $r21['description'], 'NOT scheduled' ), 'description names the unscheduled hook' );

echo "\nTest 22: snt_cron_site_health_result — silently disabled but nothing overdue yet → recommended, not critical\n";
fix4_reset_healthy_fixture(); // no filter set → sn_cron_system_cron_configured defaults false; nothing has ever fired
$r22 = snt_cron_site_health_result();
assert_eq( 'recommended', $r22['status'], 'DISABLE_WP_CRON silently starving with zero overdue evidence → recommended, not critical' );
assert_true( false === strpos( $r22['description'], 'appears to be running' ), 'the critical copy is withheld when nothing is actually overdue yet' );

echo "\nTest 23: snt_cron_site_health_result — silently disabled + an overdue scheduled hook → CRITICAL\n";
fix4_reset_healthy_fixture();
$hooks23  = snt_cron_site_health_hooks();
$target23 = $hooks23[0];
$GLOBALS['__test_schedule_slug'][ $target23 ]    = 'sn_test_5min';
$GLOBALS['__test_schedules']['sn_test_5min']     = array( 'interval' => 300 ); // 5 min cadence
$GLOBALS['__test_next_scheduled'][ $target23 ]   = time() + 300; // WP still THINKS it's scheduled
$GLOBALS['__test_options'][ 'snt_cron_last_fired_' . md5( $target23 ) ] = time() - 1000; // 1000s ago > 2*300s=600s → overdue
$r23 = snt_cron_site_health_result();
assert_eq( 'critical', $r23['status'], 'DISABLE_WP_CRON starved + an overdue scheduled hook (>2x cadence) → critical' );
assert_true(
	false !== strpos( $r23['description'], 'DISABLE_WP_CRON is set but no system cron appears to be running wp-cron.php' ),
	'description names the fix with the exact required copy'
);

echo "\nTest 24: snt_cron_site_health_result — a declared system cron escapes critical even with an overdue hook\n";
fix4_reset_healthy_fixture();
$hooks24  = snt_cron_site_health_hooks();
$target24 = $hooks24[0];
$GLOBALS['__test_schedule_slug'][ $target24 ]  = 'sn_test_5min';
$GLOBALS['__test_schedules']['sn_test_5min']   = array( 'interval' => 300 );
$GLOBALS['__test_next_scheduled'][ $target24 ] = time() + 300;
$GLOBALS['__test_options'][ 'snt_cron_last_fired_' . md5( $target24 ) ] = time() - 1000;
$GLOBALS['__test_apply_filters']['sn_cron_system_cron_configured'] = true;
$r24 = snt_cron_site_health_result();
assert_eq( 'recommended', $r24['status'], 'a declared system cron escapes critical (the overdue hook still reads as a plain issue)' );

echo "\nTest 25: snt_cron_site_health_result — a recently-fired hook proves cron works, escaping critical for a separate overdue hook\n";
fix4_reset_healthy_fixture();
$hooks25   = snt_cron_site_health_hooks();
$overdue25 = $hooks25[0];
$healthy25 = $hooks25[1];
$GLOBALS['__test_schedule_slug'][ $overdue25 ]  = 'sn_test_5min';
$GLOBALS['__test_schedules']['sn_test_5min']    = array( 'interval' => 300 );
$GLOBALS['__test_next_scheduled'][ $overdue25 ] = time() + 300;
$GLOBALS['__test_options'][ 'snt_cron_last_fired_' . md5( $overdue25 ) ] = time() - 1000; // overdue
$GLOBALS['__test_schedule_slug'][ $healthy25 ]  = 'sn_test_5min';
$GLOBALS['__test_next_scheduled'][ $healthy25 ] = time() + 300;
$GLOBALS['__test_options'][ 'snt_cron_last_fired_' . md5( $healthy25 ) ] = time() - 60; // fired 60s ago — hard evidence cron IS running
$r25 = snt_cron_site_health_result();
assert_eq( 'recommended', $r25['status'], 'evidence cron IS firing escapes critical even with a separately overdue hook' );


/* ════════════════════════════════════════════════════════════════════════
 * v13.49.0 — snt_cron_schedule_event_impl(): booking, not dispatching.
 *
 * The bound's POLARITY is the property under test. snt_cron_sn_owned_hooks()
 * is one predicate read in two directions: unscheduling REFUSES an SN-owned
 * hook (stopping our own maintenance is the harm there), scheduling accepts
 * ONLY them (deferred dispatch of third-party code is the harm here). A test
 * that only proved "an SN hook schedules" would pass on an implementation with
 * no bound at all, so the refusal is pinned first.
 * ════════════════════════════════════════════════════════════════════════ */

if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
$GLOBALS['__test_scheduled_single'] = array();
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array(), $wp_error = false ) {
		$GLOBALS['__test_scheduled_single'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
		return true;
	}
}

$GLOBALS['__test_current_user_can'] = true;
$sn_owned_hook = 'sn_health_scan_daily';
$GLOBALS['__test_actions'][ $sn_owned_hook ] = true;

// THE BOUND, refusing direction first.
$GLOBALS['__test_actions']['some_other_plugin_hook'] = true;
$r_foreign = snt_cron_schedule_event_impl( 'some_other_plugin_hook', array(), 0 );
assert_true( $r_foreign instanceof WP_Error, 'schedule: a NON-SN hook refuses, even with a registered handler' );
assert_eq( 'snt_cron_not_sn_owned', ( $r_foreign instanceof WP_Error ) ? $r_foreign->get_error_code() : '(not a WP_Error)', 'schedule: the refusal names the bound rather than failing vaguely' );
assert_true( ( $r_foreign instanceof WP_Error ) && false !== strpos( $r_foreign->get_error_message(), 'sn_health_scan_daily' ), 'schedule: the refusal LISTS what is schedulable, so a caller can correct it' );

// A hook with no handler is refused rather than booked into nothing.
$GLOBALS['__test_actions']['sn_analytics_rollup'] = false;
$r_nohandler = snt_cron_schedule_event_impl( 'sn_analytics_rollup', array(), 0 );
assert_true( $r_nohandler instanceof WP_Error, 'schedule: an SN hook with NO registered handler refuses' );
assert_eq( 'snt_cron_no_handler', ( $r_nohandler instanceof WP_Error ) ? $r_nohandler->get_error_code() : '(not a WP_Error)', 'schedule: the no-handler refusal is its own code — a booked run that fires into nothing is the honest-null rule applied to cron' );

// Permission, ahead of everything else.
$GLOBALS['__test_current_user_can'] = false;
$r_perm = snt_cron_schedule_event_impl( $sn_owned_hook, array(), 0 );
assert_eq( 'snt_cron_forbidden', ( $r_perm instanceof WP_Error ) ? $r_perm->get_error_code() : '(not a WP_Error)', 'schedule: refuses without manage_options' );
$GLOBALS['__test_current_user_can'] = true;

// Empty hook.
$r_empty = snt_cron_schedule_event_impl( '', array(), 0 );
assert_eq( 'snt_cron_invalid_hook', ( $r_empty instanceof WP_Error ) ? $r_empty->get_error_code() : '(not a WP_Error)', 'schedule: an empty hook refuses' );

// THE HAPPY PATH: books one event and returns immediately.
unset( $GLOBALS['__test_next_scheduled'][ $sn_owned_hook ] );
$GLOBALS['__test_scheduled_single'] = array();
$r_ok = snt_cron_schedule_event_impl( $sn_owned_hook, array(), 0 );
assert_true( ! ( $r_ok instanceof WP_Error ), 'schedule: an SN-owned hook with a handler books' );
assert_true( is_array( $r_ok ) && ! empty( $r_ok['success'] ), 'schedule: reports success' );
assert_true( is_array( $r_ok ) && false === ( $r_ok['already_scheduled'] ?? null ), 'schedule: a fresh booking is not reported as pre-existing' );
assert_eq( 1, count( $GLOBALS['__test_scheduled_single'] ), 'schedule: exactly ONE event was booked' );
assert_eq( $sn_owned_hook, $GLOBALS['__test_scheduled_single'][0]['hook'], 'schedule: booked the requested hook' );

// IDEMPOTENCE: an identical pending event is reported, never duplicated —
// otherwise the same maintenance runs twice.
$GLOBALS['__test_next_scheduled'][ $sn_owned_hook ] = 1893456000;
$GLOBALS['__test_scheduled_single'] = array();
$r_dupe = snt_cron_schedule_event_impl( $sn_owned_hook, array(), 0 );
assert_true( is_array( $r_dupe ) && true === ( $r_dupe['already_scheduled'] ?? null ), 'schedule: an identical pending event is REPORTED as already scheduled' );
assert_eq( 1893456000, is_array( $r_dupe ) ? ( $r_dupe['scheduled_for'] ?? null ) : null, 'schedule: and reports the EXISTING run time, not a new one' );
assert_eq( 0, count( $GLOBALS['__test_scheduled_single'] ), 'schedule: nothing was booked a second time' );
unset( $GLOBALS['__test_next_scheduled'][ $sn_owned_hook ] );

// The delay clamp, matching the validation gate's ceiling.
$GLOBALS['__test_scheduled_single'] = array();
$r_clamp = snt_cron_schedule_event_impl( $sn_owned_hook, array(), 99999 );
assert_true( is_array( $r_clamp ) && ( $r_clamp['scheduled_for'] - time() ) <= HOUR_IN_SECONDS, 'schedule: an over-long delay clamps to the one-hour ceiling' );
$GLOBALS['__test_scheduled_single'] = array();
$r_neg = snt_cron_schedule_event_impl( $sn_owned_hook, array(), -500 );
assert_true( is_array( $r_neg ) && $r_neg['scheduled_for'] >= time() - 1, 'schedule: a negative delay clamps to now, never into the past' );

// THE REGRESSION THIS RELEASE FIXES, exercised rather than grepped: the health
// hook must be SN-owned, so the rw-doored unschedule-cron-event refuses it.
assert_true( snt_cron_is_sn_owned( 'sn_health_scan_daily' ), 'the daily health hook is SN-owned (MISSING until v13.49.0)' );
$GLOBALS['__test_current_user_can'] = true;
$r_unsched_health = snt_cron_unschedule_event_impl( 'sn_health_scan_daily', array() );
assert_true( $r_unsched_health instanceof WP_Error, 'REGRESSION: unschedule now REFUSES the health hook — before v13.49.0 it would have silently stopped the daily scan' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
