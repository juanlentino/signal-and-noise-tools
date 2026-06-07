<?php
/**
 * Standalone fixture tests for the Heartbeat server responder in
 * inc/admin-heartbeat.php (v4.9.0, Task 5).
 *
 * Covers snt_admin_heartbeat_received($response, $data) ONLY — the JS
 * DOM-patching is manual UAT (validated separately with node --check).
 *
 *   - no sn_heartbeat key in $data → $response unchanged (no SN keys, no work)
 *   - current_user_can(manage_options) false → no SN keys (never works on
 *     the global heartbeat for non-admins)
 *   - $data['sn_heartbeat'] = ['cron'] → sn_cron_last_fired map keyed by hook
 *   - = ['webhooks'] → sn_webhook_logs keyed by both fixture webhook ids
 *   - = ['cron','webhooks'] → both keys present
 *
 * Run: php tests/admin-heartbeat.php
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
define( 'SN_PLAUSIBLE_REFRESH_BATCH_HOOK',    'sn_plausible_refresh_dashboard' );
define( 'SN_PLAUSIBLE_REFRESH_REALTIME_HOOK', 'sn_plausible_refresh_realtime' );
define( 'SN_RSS_TRACKER_CRON_HOOK',           'sn_rss_tracker_daily_prune' );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

// current_user_can — toggleable.
$GLOBALS['__test_manage_options'] = true;
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['__test_manage_options'] );
}

// wp_date stub (formatter).
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts = null ) {
		return gmdate( $format, $ts ? $ts : time() );
	}
}

// SN-owned hook list + last-fired (injectable).
if ( ! function_exists( 'snt_cron_sn_owned_hooks' ) ) {
	function snt_cron_sn_owned_hooks() {
		return array( SN_PLAUSIBLE_REFRESH_BATCH_HOOK, SN_PLAUSIBLE_REFRESH_REALTIME_HOOK, SN_RSS_TRACKER_CRON_HOOK );
	}
}
$GLOBALS['__test_last_fired'] = array();
if ( ! function_exists( 'snt_cron_last_fired_for' ) ) {
	function snt_cron_last_fired_for( $hook ) {
		return isset( $GLOBALS['__test_last_fired'][ $hook ] ) ? $GLOBALS['__test_last_fired'][ $hook ] : null;
	}
}

// Webhooks + logs (fixtures).
$GLOBALS['__test_webhooks'] = array();
if ( ! function_exists( 'sn_webhooks_all' ) ) {
	function sn_webhooks_all() { return $GLOBALS['__test_webhooks']; }
}
$GLOBALS['__test_webhook_logs'] = array();
if ( ! function_exists( 'sn_webhook_log_read' ) ) {
	function sn_webhook_log_read( $id ) {
		return isset( $GLOBALS['__test_webhook_logs'][ $id ] ) ? $GLOBALS['__test_webhook_logs'][ $id ] : array();
	}
}

require_once __DIR__ . '/../inc/admin-heartbeat.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function hb_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function hb_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// Fixtures.
$GLOBALS['__test_last_fired'] = array(
	SN_PLAUSIBLE_REFRESH_BATCH_HOOK    => 1717600000,
	SN_PLAUSIBLE_REFRESH_REALTIME_HOOK => 1717600300,
	SN_RSS_TRACKER_CRON_HOOK           => null, // never fired
);
$GLOBALS['__test_webhooks'] = array(
	array( 'id' => 'wh_a', 'name' => 'A', 'enabled' => true ),
	array( 'id' => 'wh_b', 'name' => 'B', 'enabled' => false ),
);
$GLOBALS['__test_webhook_logs'] = array(
	'wh_a' => array( array( 'fired_at' => 1717600000, 'attempt' => 1, 'response_code' => 200, 'success' => true, 'response_excerpt' => 'ok' ) ),
	'wh_b' => array( array( 'fired_at' => 1717600100, 'attempt' => 2, 'response_code' => 500, 'success' => false, 'response_excerpt' => 'err' ) ),
);

// ─── Test 1: no sn_heartbeat key → response unchanged ────────────────
echo "\nTest 1: no sn_heartbeat key → no SN work\n";
$GLOBALS['__test_manage_options'] = true;
$resp = snt_admin_heartbeat_received( array( 'existing' => 1 ), array( 'unrelated' => true ) );
hb_eq( array( 'existing' => 1 ), $resp, 'response returned untouched (no sn_heartbeat → early return)' );
hb_true( ! isset( $resp['sn_cron_last_fired'] ) && ! isset( $resp['sn_webhook_logs'] ), 'no SN keys added' );

// ─── Test 2: non-admin → no SN keys ──────────────────────────────────
echo "\nTest 2: current_user_can false → no SN keys\n";
$GLOBALS['__test_manage_options'] = false;
$resp = snt_admin_heartbeat_received( array(), array( 'sn_heartbeat' => array( 'cron', 'webhooks' ) ) );
hb_true( ! isset( $resp['sn_cron_last_fired'] ) && ! isset( $resp['sn_webhook_logs'] ), 'non-admin gets no SN keys (never works on global heartbeat)' );

// ─── Test 3: ['cron'] → sn_cron_last_fired map ───────────────────────
echo "\nTest 3: ['cron'] → sn_cron_last_fired keyed by hook\n";
$GLOBALS['__test_manage_options'] = true;
$resp = snt_admin_heartbeat_received( array(), array( 'sn_heartbeat' => array( 'cron' ) ) );
hb_true( isset( $resp['sn_cron_last_fired'] ), 'sn_cron_last_fired key present' );
hb_true( ! isset( $resp['sn_webhook_logs'] ), 'sn_webhook_logs NOT present (only cron requested)' );
$map = $resp['sn_cron_last_fired'];
hb_true( isset( $map[ SN_PLAUSIBLE_REFRESH_BATCH_HOOK ] ), 'batch hook present in map' );
hb_eq( 1717600000, $map[ SN_PLAUSIBLE_REFRESH_BATCH_HOOK ]['ts'], 'batch hook ts correct' );
hb_true( isset( $map[ SN_PLAUSIBLE_REFRESH_BATCH_HOOK ]['formatted'] ), 'batch hook has formatted label' );
hb_eq( null, $map[ SN_RSS_TRACKER_CRON_HOOK ]['ts'], 'never-fired hook → ts null' );

// ─── Test 4: ['webhooks'] → sn_webhook_logs keyed by both ids ────────
echo "\nTest 4: ['webhooks'] → sn_webhook_logs keyed by both ids\n";
$resp = snt_admin_heartbeat_received( array(), array( 'sn_heartbeat' => array( 'webhooks' ) ) );
hb_true( isset( $resp['sn_webhook_logs'] ), 'sn_webhook_logs key present' );
hb_true( ! isset( $resp['sn_cron_last_fired'] ), 'sn_cron_last_fired NOT present (only webhooks requested)' );
$logs = $resp['sn_webhook_logs'];
hb_true( isset( $logs['wh_a'] ) && isset( $logs['wh_b'] ), 'both fixture webhook ids keyed' );
hb_eq( 200, $logs['wh_a'][0]['response_code'], 'wh_a log row carries response_code' );
hb_eq( 500, $logs['wh_b'][0]['response_code'], 'wh_b log row carries response_code' );

// ─── Test 5: ['cron','webhooks'] → both keys ─────────────────────────
echo "\nTest 5: ['cron','webhooks'] → both keys present\n";
$resp = snt_admin_heartbeat_received( array(), array( 'sn_heartbeat' => array( 'cron', 'webhooks' ) ) );
hb_true( isset( $resp['sn_cron_last_fired'] ), 'cron key present' );
hb_true( isset( $resp['sn_webhook_logs'] ), 'webhooks key present' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
