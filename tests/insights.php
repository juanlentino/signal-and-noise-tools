<?php
/**
 * Standalone fixture tests for inc/insights.php (v3.6.0).
 *
 * Matches the bot-detection/cron-dashboard pattern: bare PHP, no
 * PHPUnit. Runnable as `php tests/insights.php`. Exits 0 on pass.
 *
 * @since plugin v3.6.0
 */

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS',  86400 );
define( 'WEEK_IN_SECONDS', 604800 );

if ( ! defined( 'OBJECT' )   ) define( 'OBJECT',   'OBJECT' );
if ( ! defined( 'OBJECT_K' ) ) define( 'OBJECT_K', 'OBJECT_K' );
if ( ! defined( 'ARRAY_A' )  ) define( 'ARRAY_A',  'ARRAY_A' );
if ( ! defined( 'ARRAY_N' )  ) define( 'ARRAY_N',  'ARRAY_N' );

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}

$GLOBALS['__test_options']    = array();
$GLOBALS['__test_transients'] = array();

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__test_options'][ $key ] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__test_options'][ $key ] );
	return true;
}
function get_transient( $key ) {
	return isset( $GLOBALS['__test_transients'][ $key ] ) ? $GLOBALS['__test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['__test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__test_transients'][ $key ] );
	return true;
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) { return json_encode( $v ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled() { return false; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event() {}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event() {}
}
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $path, $default = null ) {
		return isset( $GLOBALS['__test_sn_settings'][ $path ] )
			? $GLOBALS['__test_sn_settings'][ $path ]
			: $default;
	}
}

class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

require_once __DIR__ . '/../inc/insights.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ins_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ins_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test 1: module loads + constants defined ────────────────────────
echo "\nTest 1: module bootstrap\n";
ins_true( defined( 'SN_INSIGHTS_CACHE_KEY' ), 'SN_INSIGHTS_CACHE_KEY defined' );
ins_true( defined( 'SN_INSIGHTS_STATE_OPT' ), 'SN_INSIGHTS_STATE_OPT defined' );
ins_true( defined( 'SN_INSIGHTS_CRON_HOOK' ), 'SN_INSIGHTS_CRON_HOOK defined' );
ins_eq( 7 * DAY_IN_SECONDS, SN_INSIGHTS_CACHE_TTL, 'cache TTL is 7 days' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
