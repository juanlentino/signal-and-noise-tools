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
		return array( SN_RSS_TRACKER_CRON_HOOK ); // v6.0.0: RSS-only after Plausible retirement.
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

// The script registrar the S&N Dashboard host window calls. The enqueue below
// is gated on the classic hook suffixes, which the desktop page does not carry,
// so without a named registrar the window loaded no Heartbeat client at all and
// Cron's "Last fired" cells sat frozen with nothing saying so.
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
$GLOBALS['__hb_registered'] = array();
$GLOBALS['__hb_enqueued']   = array();
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/signal-and-noise-tools/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) { $GLOBALS['__hb_registered'][ $handle ] = array( $src, $deps, $ver ); return true; }
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) { return isset( $GLOBALS['__hb_registered'][ $handle ] ); }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) { $GLOBALS['__hb_enqueued'][] = $handle; return true; }
}
if ( ! function_exists( 'sn_admin_page_hooks' ) ) {
	function sn_admin_page_hooks() { return array( 'toplevel_page_sn-theme-options' ); }
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
	SN_RSS_TRACKER_CRON_HOOK => 1717600000,
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
hb_true( isset( $map[ SN_RSS_TRACKER_CRON_HOOK ] ), 'RSS hook present in map' );
hb_eq( 1717600000, $map[ SN_RSS_TRACKER_CRON_HOOK ]['ts'], 'RSS hook ts correct' );
hb_true( isset( $map[ SN_RSS_TRACKER_CRON_HOOK ]['formatted'] ), 'RSS hook has formatted label' );
hb_true( ! isset( $map['sn_plausible_refresh_dashboard'] ), 'v6.0.0: retired Plausible hook absent from map' );

// ─── Test 4: ['webhooks'] → sn_webhook_logs keyed by both ids ────────
echo "\nTest 4: ['webhooks'] → sn_webhook_logs keyed by both ids\n";
$resp = snt_admin_heartbeat_received( array(), array( 'sn_heartbeat' => array( 'webhooks' ) ) );
hb_true( isset( $resp['sn_webhook_logs'] ), 'sn_webhook_logs key present' );
hb_true( ! isset( $resp['sn_cron_last_fired'] ), 'sn_cron_last_fired NOT present (only webhooks requested)' );
$logs = $resp['sn_webhook_logs'];
hb_true( isset( $logs['wh_a'] ) && isset( $logs['wh_b'] ), 'both fixture webhook ids keyed' );
hb_eq( 200, $logs['wh_a'][0]['response_code'], 'wh_a log row carries response_code' );
hb_eq( 500, $logs['wh_b'][0]['response_code'], 'wh_b log row carries response_code' );
// Fix D (T5): every row carries a non-empty site-TZ formatted timestamp,
// mirroring the cron path's 'formatted' field.
hb_true( isset( $logs['wh_a'][0]['fired_at_formatted'] ) && '' !== $logs['wh_a'][0]['fired_at_formatted'], 'wh_a row carries non-empty fired_at_formatted' );
hb_true( isset( $logs['wh_b'][0]['fired_at_formatted'] ) && '' !== $logs['wh_b'][0]['fired_at_formatted'], 'wh_b row carries non-empty fired_at_formatted' );
hb_eq( gmdate( 'Y-m-d H:i:s', 1717600000 ), $logs['wh_a'][0]['fired_at_formatted'], 'wh_a fired_at_formatted matches the epoch (site-TZ formatter)' );

// ─── Test 5: ['cron','webhooks'] → both keys ─────────────────────────
echo "\nTest 5: ['cron','webhooks'] → both keys present\n";
$resp = snt_admin_heartbeat_received( array(), array( 'sn_heartbeat' => array( 'cron', 'webhooks' ) ) );
hb_true( isset( $resp['sn_cron_last_fired'] ), 'cron key present' );
hb_true( isset( $resp['sn_webhook_logs'] ), 'webhooks key present' );

echo "\nTest 6: the script registrar the host window calls\n";
snt_admin_heartbeat_register_script();
hb_true( isset( $GLOBALS['__hb_registered']['sn-admin-heartbeat'] ), 'snt_admin_heartbeat_register_script() registers the handle by name' );
hb_eq( array( 'jquery', 'heartbeat' ), $GLOBALS['__hb_registered']['sn-admin-heartbeat'][1] ?? array(),
	'with BOTH deps: without the core `heartbeat` handle WordPress silently drops the script, and the responder above would have nobody to answer' );
hb_true( false !== strpos( (string) ( $GLOBALS['__hb_registered']['sn-admin-heartbeat'][0] ?? '' ), 'assets/admin-heartbeat.js' ), 'from the same source path the enqueue used' );
$hb_count = count( $GLOBALS['__hb_registered'] );
snt_admin_heartbeat_register_script();
hb_eq( $hb_count, count( $GLOBALS['__hb_registered'] ), 'idempotent: a second call registers nothing twice' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
