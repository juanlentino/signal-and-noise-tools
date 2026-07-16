<?php
/**
 * Standalone tests for the MCP rw-door guard (v9.51.0, lane SEC-A): the
 * credential-split (R1) and kill-switch (R2) predicates. Every predicate is
 * exercised BOTH as a pure function (state injected as params, no WP
 * bootstrap) and via its live wrapper (state gathered from get_option()/
 * defined()/rest_get_authenticated_app_password() stubs) — the "injectable"
 * requirement from the spec.
 *
 * @since plugin v9.51.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code()    { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data()    { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }

// In-memory options store.
$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; } }

// Toggle whether/what rest_get_authenticated_app_password() returns.
$GLOBALS['__app_pw_uuid'] = null; // null = function pretends not to exist; '' or a uuid = its return value.
if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
	function rest_get_authenticated_app_password() { return $GLOBALS['__app_pw_uuid']; }
}
// Toggle whether the app-password auth function is even present (WP < 5.7 gate, R1).
$GLOBALS['__app_pw_fn_exists'] = true;
if ( ! function_exists( 'sn_test_function_exists_shim' ) ) {
	// mcp-rw-guard.php itself calls the real function_exists(); to simulate the
	// "function doesn't exist" branch we instead drive __app_pw_uuid to null and
	// treat null as "no app-password auth" inside the live wrapper under test —
	// see the guard's own function_exists() guard, exercised directly below.
}

// v9.51.0 (lane SEC-C, R7): in-memory object-cache + transient stand-ins so the
// rate limiter's storage abstraction (ext object cache when present, transient
// fallback otherwise) is exercised on BOTH branches from one process.
$GLOBALS['__wp_cache']              = array();
$GLOBALS['__transients']            = array();
$GLOBALS['__using_ext_object_cache'] = false;
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) { function wp_using_ext_object_cache() { return $GLOBALS['__using_ext_object_cache']; } }
if ( ! function_exists( 'wp_cache_get' ) ) { function wp_cache_get( $k, $g = '' ) { return array_key_exists( "$g:$k", $GLOBALS['__wp_cache'] ) ? $GLOBALS['__wp_cache']["$g:$k"] : false; } }
if ( ! function_exists( 'wp_cache_set' ) ) { function wp_cache_set( $k, $v, $g = '', $ttl = 0 ) { $GLOBALS['__wp_cache']["$g:$k"] = $v; return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = 'auth' ) { return 'test-salt'; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $v ) ); } }
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP rw-guard — plugin v9.51.0 (lane SEC-A)\n\n";

// ============================================================
// R2 — kill switch, PURE predicate (state injected, no globals read inside)
// ============================================================
echo "-- R2 kill switch: pure predicate --\n";
ok( sn_mcp_rw_kill_switch_decision( true, true ) === true, 'constant disabled=true, option enabled=true -> engaged (constant wins)' );
ok( sn_mcp_rw_kill_switch_decision( true, false ) === true, 'constant disabled=true, option enabled=false -> engaged' );
ok( sn_mcp_rw_kill_switch_decision( false, true ) === false, 'constant disabled=false, option enabled=true -> NOT engaged (normal operation)' );
ok( sn_mcp_rw_kill_switch_decision( false, false ) === true, 'constant disabled=false, option enabled=false -> engaged (owner UI kill)' );

// ============================================================
// R2 — kill switch, LIVE wrapper (gathers real defined()/get_option() state)
// ============================================================
echo "\n-- R2 kill switch: live wrapper --\n";
$GLOBALS['__opts'] = array();
ok( sn_mcp_rw_kill_switch_constant_disabled() === false, 'constant not defined -> not disabled' );
ok( sn_mcp_rw_enabled_option() === true, 'option absent -> default true (fail-open on absence, per spec)' );
ok( sn_mcp_rw_kill_switch_engaged() === false, 'both absent -> kill switch NOT engaged (normal operation, the default-absent state)' );

update_option( 'sn_mcp_rw_enabled', false );
ok( sn_mcp_rw_enabled_option() === false, 'option explicitly false is read back as false' );
ok( sn_mcp_rw_kill_switch_engaged() === true, 'option false -> kill switch engaged (the owner UI kill)' );
update_option( 'sn_mcp_rw_enabled', true );
ok( sn_mcp_rw_kill_switch_engaged() === false, 'option reset to true -> kill switch not engaged' );

// The SN_MCP_RW_DISABLED constant can't be toggled at PHP runtime once defined
// in one process; verify the pure predicate side (above) covers the constant
// branch, and prove the live getter reads defined()+value the same way a real
// wp-config constant would by defining it here (first and only definition).
define( 'SN_MCP_RW_DISABLED', true );
ok( sn_mcp_rw_kill_switch_constant_disabled() === true, 'SN_MCP_RW_DISABLED constant, once true, is read as disabled' );
ok( sn_mcp_rw_kill_switch_engaged() === true, 'constant true -> kill switch engaged even though the option is true (constant wins, R2)' );

// ============================================================
// R1 — credential decision, PURE predicate
// ============================================================
echo "\n-- R1 credential decision: pure predicate --\n";
$uuid_a = '11111111-1111-1111-1111-111111111111';
$uuid_b = '22222222-2222-2222-2222-222222222222';

$d = sn_mcp_rw_credential_decision( '', $uuid_a, true );
ok( false === $d['allow'] && 'rw_credential_unbound' === $d['code'], 'R1 DECISION: unbound state (bound_uuid empty) = deny-closed with rw_credential_unbound' );

$d = sn_mcp_rw_credential_decision( $uuid_a, $uuid_a, true );
ok( true === $d['allow'] && '' === $d['code'], 'bound UUID set + matching authenticated UUID -> allow' );

$d = sn_mcp_rw_credential_decision( $uuid_a, $uuid_b, true );
ok( false === $d['allow'] && 'credential_not_authorized' === $d['code'], 'bound UUID set + mismatched authenticated UUID -> deny credential_not_authorized' );

$d = sn_mcp_rw_credential_decision( $uuid_a, '', false );
ok( false === $d['allow'] && 'credential_not_authorized' === $d['code'], 'bound UUID set + no app-password auth at all (e.g. cookie auth) -> deny credential_not_authorized' );

$d = sn_mcp_rw_credential_decision( $uuid_a, $uuid_a, false );
ok( false === $d['allow'] && 'credential_not_authorized' === $d['code'], 'bound UUID set + uuid matches but has_app_password_auth=false -> still deny (auth channel matters, not just the string)' );

// ============================================================
// R1 — credential decision, LIVE wrapper
// ============================================================
echo "\n-- R1 credential decision: live wrapper --\n";
$GLOBALS['__opts']        = array();
$GLOBALS['__app_pw_uuid'] = null;
ok( sn_mcp_rw_bound_uuid() === '', 'bound uuid option absent -> empty string (unbound)' );
ok( sn_mcp_rw_authenticated_app_password_uuid() === '', 'no app-password auth on this request -> empty string' );
$d = sn_mcp_rw_credential_authorize();
ok( false === $d['allow'] && 'rw_credential_unbound' === $d['code'], 'live wrapper: fresh install, nothing bound -> deny rw_credential_unbound' );

update_option( 'sn_mcp_rw_app_password_uuid', $uuid_a );
$GLOBALS['__app_pw_uuid'] = $uuid_a;
$d = sn_mcp_rw_credential_authorize();
ok( true === $d['allow'], 'live wrapper: bound uuid matches the authenticated request -> allow' );

$GLOBALS['__app_pw_uuid'] = $uuid_b;
$d = sn_mcp_rw_credential_authorize();
ok( false === $d['allow'] && 'credential_not_authorized' === $d['code'], 'live wrapper: bound uuid does NOT match the authenticated request -> deny' );

$GLOBALS['__app_pw_uuid'] = '';
$d = sn_mcp_rw_credential_authorize();
ok( false === $d['allow'] && 'credential_not_authorized' === $d['code'], 'live wrapper: bound but this request has no app-password auth (e.g. cookie) -> deny' );

// ============================================================
// UUID shape validation + setter (owned by SEC-A; consumed by the SEC-C leaf)
// ============================================================
echo "\n-- UUID shape validation + setter --\n";
ok( sn_mcp_rw_uuid_shape_valid( $uuid_a ) === true, 'a well-formed UUID passes shape validation' );
ok( sn_mcp_rw_uuid_shape_valid( 'not-a-uuid' ) === false, 'a malformed string fails shape validation' );
ok( sn_mcp_rw_uuid_shape_valid( '' ) === false, 'an empty string fails shape validation (use the empty-string unbind path instead)' );
ok( sn_mcp_rw_uuid_shape_valid( $uuid_a . '; DROP TABLE wp_options;' ) === false, 'a UUID with trailing injected content fails shape validation' );

$GLOBALS['__opts'] = array();
ok( sn_mcp_set_rw_bound_uuid( 'garbage' ) === false, 'setting a malformed uuid is rejected (returns false, option untouched)' );
ok( sn_mcp_rw_bound_uuid() === '', 'the option was never written on a rejected set' );

ok( sn_mcp_set_rw_bound_uuid( strtoupper( $uuid_a ) ) === true, 'setting a valid (even uppercase) uuid succeeds' );
ok( sn_mcp_rw_bound_uuid() === strtolower( $uuid_a ), 'the stored uuid is normalized to lowercase' );

ok( sn_mcp_set_rw_bound_uuid( '' ) === true, 'setting an empty string explicitly unbinds' );
ok( sn_mcp_rw_bound_uuid() === '', 'after an explicit unbind, the bound uuid reads back empty' );

// ============================================================
// Error builder (feeds the WP_Error a permission_callback can return)
// ============================================================
echo "\n-- sn_mcp_rw_error() --\n";
$err = sn_mcp_rw_error( 'rw_disabled' );
ok( is_wp_error( $err ), 'sn_mcp_rw_error returns a WP_Error' );
ok( ( $err->get_error_data()['status'] ?? null ) === 403, 'the error carries HTTP status 403' );
ok( false !== strpos( $err->get_error_message(), 'disabled' ), 'rw_disabled error names the disabled state' );

$err = sn_mcp_rw_error( 'rw_credential_unbound' );
ok( false !== stripos( $err->get_error_message(), 'Tools' ) || false !== stripos( $err->get_error_message(), 'MCP' ),
	'R1: the unbound-state error names the fix (points at the write-door credential setup)' );

$err = sn_mcp_rw_error( 'credential_not_authorized' );
ok( is_wp_error( $err ) && ( $err->get_error_data()['status'] ?? null ) === 403, 'credential_not_authorized is also a 403' );

$err = sn_mcp_rw_error( 'some_unknown_code' );
ok( is_wp_error( $err ) && '' !== $err->get_error_message(), 'an unrecognized code still produces a generic denial message, never an empty one' );

// ============================================================
// R7 — rate limit on /mcp-rw (lane SEC-C). Token-bucket keyed on the
// authenticated app-pw UUID (fallback hashed IP), object-cache-or-transient
// storage, a modest per-minute cap. The CALL SITE that gates on this lives in
// inc/mcp/mcp-tools.php's sn_mcp_call_tool() (see tests/mcp-tools.php) — this
// suite exercises the predicate + identity + storage layer this file owns.
// ============================================================
echo "\n-- R7 rate limit: pure decision predicate --\n";
ok( sn_mcp_rw_rate_limit_decision( 0, 30 ) === true, '0 of 30 used -> allowed' );
ok( sn_mcp_rw_rate_limit_decision( 29, 30 ) === true, '29 of 30 used -> still allowed (one left)' );
ok( sn_mcp_rw_rate_limit_decision( 30, 30 ) === false, '30 of 30 used -> at cap, denied' );
ok( sn_mcp_rw_rate_limit_decision( 31, 30 ) === false, 'over cap -> denied' );

echo "\n-- R7 rate limit: identity resolution never falls through to 'unlimited' --\n";
ok( sn_mcp_rw_rate_limit_identity( $uuid_a, 'ignored-when-uuid-present' ) === 'uuid:' . $uuid_a, 'identity keys on the app-pw UUID when present' );
ok( sn_mcp_rw_rate_limit_identity( '', 'abcd1234' ) === 'ip:abcd1234', 'identity falls back to the hashed IP when no UUID' );
ok( sn_mcp_rw_rate_limit_identity( '', '' ) === 'ip:unknown', 'PROBE PIN: both UUID and IP hash empty still resolves to a concrete (shared) identity, never "unlimited"' );

echo "\n-- R7 rate limit: storage abstraction (object cache vs transient) --\n";
$GLOBALS['__using_ext_object_cache'] = false;
ok( sn_mcp_rw_rate_limit_store_get( 'sn_mcp_rw_rate_test1' ) === null, 'transient path: unseen key reads back null' );
sn_mcp_rw_rate_limit_store_set( 'sn_mcp_rw_rate_test1', 3, 60 );
ok( sn_mcp_rw_rate_limit_store_get( 'sn_mcp_rw_rate_test1' ) === 3, 'transient path: set then get round-trips' );
ok( array_key_exists( 'sn_mcp_rw_rate_test1', $GLOBALS['__transients'] ), 'transient path: value actually landed in the transient store, not the object cache' );

$GLOBALS['__using_ext_object_cache'] = true;
ok( sn_mcp_rw_rate_limit_store_get( 'sn_mcp_rw_rate_test2' ) === null, 'object-cache path: unseen key reads back null' );
sn_mcp_rw_rate_limit_store_set( 'sn_mcp_rw_rate_test2', 5, 60 );
ok( sn_mcp_rw_rate_limit_store_get( 'sn_mcp_rw_rate_test2' ) === 5, 'object-cache path: set then get round-trips' );
ok( array_key_exists( 'sn_mcp_rw_rate:sn_mcp_rw_rate_test2', $GLOBALS['__wp_cache'] ), 'object-cache path: value actually landed in wp_cache, not the transient store' );
$GLOBALS['__using_ext_object_cache'] = false;

echo "\n-- R7 rate limit: end-to-end check-and-increment --\n";
$GLOBALS['__transients'] = array();
$key = 'fresh-identity-' . uniqid();
for ( $i = 0; $i < 30; $i++ ) {
	$r = sn_mcp_rw_rate_limit_check( $key );
	ok( true === $r['allow'], "call " . ( $i + 1 ) . " of 30 within the cap is allowed" );
}
$over = sn_mcp_rw_rate_limit_check( $key );
ok( false === $over['allow'], 'the 31st call in the same window is denied' );
ok( $over['retry_after'] > 0, 'a denied call carries a positive retry_after hint' );

echo "\n-- R7 rate limit: live gate gathers real identity + is exempt for nothing but the caller decides the door --\n";
$GLOBALS['__transients'] = array();
$GLOBALS['__app_pw_uuid'] = $uuid_a;
$gate = sn_mcp_rw_rate_limit_gate();
ok( true === $gate['allow'], 'live gate: first call for a fresh identity is allowed' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
