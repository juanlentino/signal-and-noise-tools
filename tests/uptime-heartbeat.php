<?php
/**
 * Standalone fixture tests for inc/uptime-heartbeat.php (v4.9.0, Task 4).
 *
 * Opt-in Uptime Kuma push-monitor heartbeat:
 *   - sn_uptime_cron_schedules adds 'sn_five_minutes' = 300s
 *   - sn_uptime_heartbeat_schedule: enabled + url + unscheduled → schedules once
 *   - disabled + scheduled → clears
 *   - enabled + empty url → not scheduled
 *   - worker: enabled + valid url → ONE GET with redirection === 0 (SSRF)
 *   - worker: re-reads disabled state → 0 GET (drop if toggled off mid-flight)
 *   - worker: invalid url → 0 GET (wp_http_validate_url rejects)
 *
 * Run: php tests/uptime-heartbeat.php
 *
 * @since plugin v4.9.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SNT_VERSION', '4.9.0' );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

// sn_setting injectable via $GLOBALS['__test_settings'] (dot-path → value).
$GLOBALS['__test_settings'] = array();
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $path, $default = null ) {
		return array_key_exists( $path, $GLOBALS['__test_settings'] ) ? $GLOBALS['__test_settings'][ $path ] : $default;
	}
}

// Cron stubs.
$GLOBALS['__test_next_scheduled'] = array();
$GLOBALS['__test_scheduled_events'] = array();
$GLOBALS['__test_cleared'] = array();
function wp_next_scheduled( $hook, $args = array() ) {
	return isset( $GLOBALS['__test_next_scheduled'][ $hook ] ) ? $GLOBALS['__test_next_scheduled'][ $hook ] : false;
}
function wp_schedule_event( $ts, $recurrence, $hook, $args = array() ) {
	$GLOBALS['__test_scheduled_events'][] = compact( 'ts', 'recurrence', 'hook', 'args' );
	$GLOBALS['__test_next_scheduled'][ $hook ] = $ts;
	return true;
}
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$GLOBALS['__test_cleared'][] = $hook;
	unset( $GLOBALS['__test_next_scheduled'][ $hook ] );
	return 1;
}

// wp_http_validate_url — reject non-http(s) + obviously-bad urls. Mirrors
// WP core's gate: only http/https schemes with a host pass.
function wp_http_validate_url( $u ) {
	if ( ! is_string( $u ) || '' === $u ) { return false; }
	$parts = parse_url( $u );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return false; }
	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) { return false; }
	return $u;
}

// wp_remote_get — capture args + count.
$GLOBALS['__test_get_calls'] = array();
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__test_get_calls'][] = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => 200 ), 'body' => 'OK' );
}
function wp_remote_retrieve_response_code( $resp ) {
	return is_array( $resp ) && isset( $resp['response']['code'] ) ? $resp['response']['code'] : 0;
}

// Transient store.
$GLOBALS['__test_transients'] = array();
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__test_transients'] ) ? $GLOBALS['__test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $exp = 0 ) {
	$GLOBALS['__test_transients'][ $key ] = $value;
	return true;
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) {
		$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}

class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

require_once __DIR__ . '/../inc/uptime-heartbeat.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function uh_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function uh_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

function uh_reset() {
	$GLOBALS['__test_settings']         = array();
	$GLOBALS['__test_next_scheduled']   = array();
	$GLOBALS['__test_scheduled_events'] = array();
	$GLOBALS['__test_cleared']          = array();
	$GLOBALS['__test_get_calls']        = array();
	$GLOBALS['__test_transients']       = array();
}

// ─── Test 1: cron_schedules adds sn_five_minutes = 300 ───────────────
echo "\nTest 1: cron_schedules registers sn_five_minutes (300s)\n";
$schedules = sn_uptime_cron_schedules( array() );
uh_true( isset( $schedules['sn_five_minutes'] ), 'sn_five_minutes key present' );
uh_eq( 300, $schedules['sn_five_minutes']['interval'], 'interval is 300s' );

// ─── Test 2: enabled + url + unscheduled → schedules once ────────────
echo "\nTest 2: enabled + url + unscheduled → schedules once\n";
uh_reset();
$GLOBALS['__test_settings'] = array(
	'monitoring.uptime_kuma_enabled'  => true,
	'monitoring.uptime_kuma_push_url' => 'https://kuma.example.com/api/push/abc123',
);
sn_uptime_heartbeat_schedule();
uh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'scheduled exactly once' );
uh_eq( SN_UPTIME_HEARTBEAT_HOOK, $GLOBALS['__test_scheduled_events'][0]['hook'], 'scheduled the heartbeat hook' );
uh_eq( 'sn_five_minutes', $GLOBALS['__test_scheduled_events'][0]['recurrence'], 'uses the 5-min recurrence' );
// Calling again (now scheduled) must NOT double-schedule.
sn_uptime_heartbeat_schedule();
uh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'idempotent: still one scheduled event' );

// ─── Test 3: disabled + scheduled → clears ───────────────────────────
echo "\nTest 3: disabled + scheduled → clears\n";
uh_reset();
$GLOBALS['__test_next_scheduled'][ SN_UPTIME_HEARTBEAT_HOOK ] = time() + 60;
$GLOBALS['__test_settings'] = array(
	'monitoring.uptime_kuma_enabled'  => false,
	'monitoring.uptime_kuma_push_url' => 'https://kuma.example.com/api/push/abc123',
);
sn_uptime_heartbeat_schedule();
uh_eq( 1, count( $GLOBALS['__test_cleared'] ), 'cleared the scheduled hook once' );
uh_eq( SN_UPTIME_HEARTBEAT_HOOK, $GLOBALS['__test_cleared'][0], 'cleared the heartbeat hook' );

// ─── Test 4: enabled + empty url → not scheduled ─────────────────────
echo "\nTest 4: enabled + empty url → not scheduled\n";
uh_reset();
$GLOBALS['__test_settings'] = array(
	'monitoring.uptime_kuma_enabled'  => true,
	'monitoring.uptime_kuma_push_url' => '',
);
sn_uptime_heartbeat_schedule();
uh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'empty url → not scheduled' );

// ─── Test 5: worker enabled + valid url → 1 GET, redirection 0 ───────
echo "\nTest 5: worker enabled + valid url → one GET with redirection=0\n";
uh_reset();
$GLOBALS['__test_settings'] = array(
	'monitoring.uptime_kuma_enabled'  => true,
	'monitoring.uptime_kuma_push_url' => 'https://kuma.example.com/api/push/abc123',
);
sn_uptime_heartbeat_worker();
uh_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'exactly one GET fired' );
$call = $GLOBALS['__test_get_calls'][0];
uh_eq( 0, $call['args']['redirection'], 'redirection === 0 (SSRF hardening, mirrors webhooks)' );
uh_true( false !== strpos( $call['url'], 'status=up' ), 'url carries status=up (Kuma push API)' );
uh_true( isset( $GLOBALS['__test_transients']['sn_uptime_last_ping'] ), 'records sn_uptime_last_ping transient' );

// ─── Test 6: worker re-reads disabled → 0 GET ───────────────────────
echo "\nTest 6: worker re-reads disabled state → no GET\n";
uh_reset();
$GLOBALS['__test_settings'] = array(
	'monitoring.uptime_kuma_enabled'  => false,
	'monitoring.uptime_kuma_push_url' => 'https://kuma.example.com/api/push/abc123',
);
sn_uptime_heartbeat_worker();
uh_eq( 0, count( $GLOBALS['__test_get_calls'] ), 'disabled at worker time → no GET' );

// ─── Test 7: worker invalid url → 0 GET ──────────────────────────────
echo "\nTest 7: worker invalid url → no GET (wp_http_validate_url rejects)\n";
uh_reset();
$GLOBALS['__test_settings'] = array(
	'monitoring.uptime_kuma_enabled'  => true,
	'monitoring.uptime_kuma_push_url' => 'file:///etc/passwd',
);
sn_uptime_heartbeat_worker();
uh_eq( 0, count( $GLOBALS['__test_get_calls'] ), 'invalid url → no GET' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
