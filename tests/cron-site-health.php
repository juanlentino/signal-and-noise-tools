<?php
/**
 * Standalone fixture tests for the native WordPress Site Health async test
 * builder in inc/cron-dashboard.php (v4.9.0, Task 2).
 *
 * Covers snt_cron_site_health_result():
 *   - all SN-owned hooks scheduled + recently fired + cron not disabled
 *     → status 'good'
 *   - one hook unscheduled → 'recommended'
 *   - DISABLE_WP_CRON defined true + sn_cron_system_cron_configured filter
 *     false → 'recommended' (silently-disabled cron)
 *   - result envelope shape: label/status/badge/description/actions/test
 *     present + badge.color set
 *
 * Run: php tests/cron-site-health.php
 *
 * @since plugin v4.9.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

// SN-owned hook constants (define so snt_cron_sn_owned_hooks resolves them).
define( 'SN_PLAUSIBLE_REFRESH_BATCH_HOOK',    'sn_plausible_refresh_dashboard' );
define( 'SN_PLAUSIBLE_REFRESH_REALTIME_HOOK', 'sn_plausible_refresh_realtime' );
define( 'SN_RSS_TRACKER_CRON_HOOK',           'sn_rss_tracker_daily_prune' );
define( 'SNT_CRON_HISTORY_CRON_HOOK',         'snt_cron_history_prune' );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
$GLOBALS['__test_filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( array_key_exists( $hook, $GLOBALS['__test_filters'] ) ) {
			return $GLOBALS['__test_filters'][ $hook ];
		}
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { return $u; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $s ) { return $s; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p ) { return 'https://juanlentino.com/wp-admin/' . $p; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $p = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( $p, '/' ); }
}
if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to = 0 ) {
		$to = $to ? $to : time();
		return ( abs( $to - $from ) ) . ' seconds';
	}
}
if ( ! function_exists( 'current_action' ) ) {
	function current_action() { return ''; }
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook, $cb = false ) { return true; }
}

// wp_next_scheduled — injectable per-hook via $GLOBALS['__test_next_scheduled'].
$GLOBALS['__test_next_scheduled'] = array();
function wp_next_scheduled( $hook, $args = array() ) {
	return isset( $GLOBALS['__test_next_scheduled'][ $hook ] ) ? $GLOBALS['__test_next_scheduled'][ $hook ] : false;
}
// wp_get_schedule + wp_get_schedules — provide an interval for staleness math.
$GLOBALS['__test_schedule_for'] = array();
function wp_get_schedule( $hook, $args = array() ) {
	return isset( $GLOBALS['__test_schedule_for'][ $hook ] ) ? $GLOBALS['__test_schedule_for'][ $hook ] : 'daily';
}
function wp_get_schedules() {
	return array(
		'hourly'     => array( 'interval' => HOUR_IN_SECONDS ),
		'twicedaily' => array( 'interval' => 12 * HOUR_IN_SECONDS ),
		'daily'      => array( 'interval' => DAY_IN_SECONDS ),
	);
}

// Option store for last-fired reads (snt_cron_last_fired_for uses get_option).
$GLOBALS['__test_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}

class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

require_once __DIR__ . '/../inc/cron-dashboard.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ch_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ch_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// Helper: set every SN-owned hook scheduled in the future + fired recently.
function ch_all_healthy() {
	$hooks = array_merge( snt_cron_sn_owned_hooks(), array( SNT_CRON_HISTORY_CRON_HOOK ) );
	$GLOBALS['__test_next_scheduled'] = array();
	$GLOBALS['__test_options']        = array();
	$GLOBALS['__test_schedule_for']   = array();
	foreach ( $hooks as $h ) {
		$GLOBALS['__test_next_scheduled'][ $h ] = time() + HOUR_IN_SECONDS;
		$GLOBALS['__test_schedule_for'][ $h ]   = 'daily';
		// last-fired 1h ago — well within 2× daily interval.
		$GLOBALS['__test_options'][ 'snt_cron_last_fired_' . md5( $h ) ] = time() - HOUR_IN_SECONDS;
	}
}

// ─── Test 1: all healthy → good ──────────────────────────────────────
echo "\nTest 1: all SN hooks scheduled + recent + cron on → good\n";
ch_all_healthy();
$GLOBALS['__test_filters'] = array();
$res = snt_cron_site_health_result();
ch_eq( 'good', $res['status'], 'status is good' );

// ─── Test 2: result envelope shape ───────────────────────────────────
echo "\nTest 2: result envelope shape\n";
foreach ( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ) as $k ) {
	ch_true( array_key_exists( $k, $res ), "result has '$k' key" );
}
ch_true( is_array( $res['badge'] ) && isset( $res['badge']['color'] ), 'badge.color set' );
ch_eq( 'sn_cron_pipeline', $res['test'], "test === 'sn_cron_pipeline'" );
ch_true( false !== strpos( (string) $res['actions'], 'sn-automation' ), 'actions link to the Cron tab' );

// ─── Test 3: one hook unscheduled → recommended ──────────────────────
echo "\nTest 3: one unscheduled hook → recommended\n";
ch_all_healthy();
$GLOBALS['__test_next_scheduled'][ SN_RSS_TRACKER_CRON_HOOK ] = false;
$res = snt_cron_site_health_result();
ch_eq( 'recommended', $res['status'], 'unscheduled hook downgrades to recommended' );

// ─── Test 4: DISABLE_WP_CRON true + filter false → recommended ───────
echo "\nTest 4: DISABLE_WP_CRON without system cron → recommended\n";
ch_all_healthy();
define( 'DISABLE_WP_CRON', true );
$GLOBALS['__test_filters'] = array( 'sn_cron_system_cron_configured' => false );
$res = snt_cron_site_health_result();
ch_eq( 'recommended', $res['status'], 'silently-disabled cron downgrades to recommended' );

// ─── Test 5: DISABLE_WP_CRON true BUT system cron configured → good ──
echo "\nTest 5: DISABLE_WP_CRON WITH declared system cron → good\n";
ch_all_healthy();
$GLOBALS['__test_filters'] = array( 'sn_cron_system_cron_configured' => true );
$res = snt_cron_site_health_result();
ch_eq( 'good', $res['status'], 'declaring system cron via filter keeps status good' );

// ─── Test 6: scheduled but last-fired older than 2x interval → recommended ──
// Isolates the staleness branch: all hooks scheduled in the future, DISABLE_WP_CRON
// (defined true in Test 4) neutralized by declaring system cron, so only one hook's
// stale last-fired can downgrade the status.
echo "\nTest 6: stale hook (fired > 2x interval ago) → recommended\n";
ch_all_healthy();
$GLOBALS['__test_filters'] = array( 'sn_cron_system_cron_configured' => true );
$stale_hook = SN_RSS_TRACKER_CRON_HOOK;
$GLOBALS['__test_schedule_for'][ $stale_hook ] = 'daily'; // interval 86400 → 2× = 172800
$GLOBALS['__test_options'][ 'snt_cron_last_fired_' . md5( $stale_hook ) ] = time() - 3 * DAY_IN_SECONDS;
$res = snt_cron_site_health_result();
ch_eq( 'recommended', $res['status'], 'stale hook (>2x interval) downgrades to recommended' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
