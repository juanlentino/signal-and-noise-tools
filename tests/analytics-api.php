<?php
/**
 * Tests for inc/analytics-api.php — Cloudflare Analytics Engine SQL read-client.
 *
 * Covers:
 *   - sn_analytics_config() → null when constants absent and no options set.
 *   - sn_analytics_config() → option-only resolution (no constants defined).
 *   - sn_analytics_config() → null when only one of {token, account_id} is present.
 *   - sn_analytics_config() → constant wins over option when both are set.
 *   - sn_analytics_config() → null when constants absent; array when both set.
 *   - sn_analytics_query() → parses a canned 200 AE JSON response → returns data array.
 *   - sn_analytics_query() → non-200 (401) → null + error recorded.
 *   - sn_analytics_query() → WP_Error → null + error recorded.
 *   - POST body is the raw SQL string (not a JSON envelope).
 *   - Authorization header is "Bearer <token>".
 *   - sn_analytics_probe() → true when query returns array, false when null.
 *
 * Run: php tests/analytics-api.php
 *
 * @since plugin v5.0.1
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );

// ── WP function stubs ────────────────────────────────────────────────────────

class WP_Error {
	public $code;
	public $message;
	public function __construct( $c = '', $m = '' ) {
		$this->code    = $c;
		$this->message = $m;
	}
	public function get_error_message() {
		return $this->message;
	}
}

// Controlled via $GLOBALS['__ae_wp_error_mode']: true → is_wp_error returns true.
$GLOBALS['__ae_wp_error_mode'] = false;

function is_wp_error( $v ) {
	if ( $GLOBALS['__ae_wp_error_mode'] && $v instanceof WP_Error ) {
		return true;
	}
	return $v instanceof WP_Error;
}

// wp_remote_post stub — captures call args for assertion.
$GLOBALS['__ae_post_calls']  = array();
$GLOBALS['__ae_mock_code']   = 200;
$GLOBALS['__ae_mock_body']   = '';

function wp_remote_post( $url, $args = array() ) {
	if ( $GLOBALS['__ae_wp_error_mode'] ) {
		return new WP_Error( 'http_request_failed', 'connection timed out' );
	}
	$GLOBALS['__ae_post_calls'][] = array( 'url' => $url, 'args' => $args );
	return array(
		'response' => array( 'code' => $GLOBALS['__ae_mock_code'] ),
		'body'     => $GLOBALS['__ae_mock_body'],
	);
}

function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
}

function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) ? ( $r['body'] ?? '' ) : '';
}

// Transient store.
$GLOBALS['__ae_transients'] = array();

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__ae_transients'] )
		? $GLOBALS['__ae_transients'][ $key ]
		: false;
}
function set_transient( $key, $value, $exp = 0 ) {
	$GLOBALS['__ae_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__ae_transients'][ $key ] );
	return true;
}

// Options store (array-backed get_option / update_option / delete_option stubs).
$GLOBALS['__ae_options'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__ae_options'] )
		? $GLOBALS['__ae_options'][ $key ]
		: $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__ae_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__ae_options'][ $key ] );
	return true;
}

// add_action stub — analytics-api.php registers no hooks, but the guard is here for safety.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

// Site timezone stub — drives sn_analytics_site_tz_name() (v9.26.4). Controllable
// per test to model a named IANA zone vs a manual UTC offset vs a junk string.
$GLOBALS['__ae_tz_string'] = 'America/New_York';
function wp_timezone_string() {
	return $GLOBALS['__ae_tz_string'];
}

// ── Load the module under test ───────────────────────────────────────────────

require_once __DIR__ . '/../inc/analytics-api.php';

// ── Test harness ─────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $msg\n";
	} else {
		$fail++;
		echo "FAIL: $msg\n";
	}
}

function ae_reset() {
	$GLOBALS['__ae_post_calls']    = array();
	$GLOBALS['__ae_mock_code']     = 200;
	$GLOBALS['__ae_mock_body']     = '';
	$GLOBALS['__ae_transients']    = array();
	$GLOBALS['__ae_wp_error_mode'] = false;
	$GLOBALS['__ae_options']       = array();
}

// ── Canned AE SQL API JSON response ──────────────────────────────────────────
$AE_GOOD_BODY = json_encode( array(
	'meta'  => array( array( 'name' => 'event_type', 'type' => 'String' ), array( 'name' => 'events', 'type' => 'UInt64' ) ),
	'data'  => array( array( 'event_type' => 'pv', 'events' => 42 ) ),
	'rows'  => 1,
) );

echo "Analytics Engine SQL read-client — plugin v5.0.1\n\n";

// ══════════════════════════════════════════════════════════════════════════════
// SECTION A: Option-resolution tests — run BEFORE any constant is define()d.
// Once PHP define()s SN_CF_ANALYTICS_TOKEN / SN_CF_ACCOUNT_ID they cannot be
// undefined. All option-only and null-via-option cases must execute here.
// ══════════════════════════════════════════════════════════════════════════════

// ── Test 1: sn_analytics_config() → null when no constants AND no options ────
echo "Test 1: config → null when constants absent and no options set\n";
ae_reset();
ok( sn_analytics_config() === null, 'config: null when no constants and no options' );

// ── Test 2: option-only resolution — both options set, no constants ───────────
echo "\nTest 2: config → array via options alone (no constants defined)\n";
ae_reset();
update_option( 'sn_cf_analytics_token', 'opt_token_abc' );
update_option( 'sn_cf_account_id', 'opt_account_xyz' );
$cfg = sn_analytics_config();
ok( is_array( $cfg ), 'config: returns array when both options set (no constants)' );
ok( isset( $cfg['token'] ) && $cfg['token'] === 'opt_token_abc', 'config: token comes from option sn_cf_analytics_token' );
ok( isset( $cfg['account_id'] ) && $cfg['account_id'] === 'opt_account_xyz', 'config: account_id comes from option sn_cf_account_id' );

// ── Test 3: null when only token option is set (account_id absent) ────────────
echo "\nTest 3: config → null when only token option present\n";
ae_reset();
update_option( 'sn_cf_analytics_token', 'opt_token_abc' );
// sn_cf_account_id NOT set → should return null.
ok( sn_analytics_config() === null, 'config: null when account_id option missing' );

// ── Test 4: null when only account_id option is set (token absent) ────────────
echo "\nTest 4: config → null when only account_id option present\n";
ae_reset();
update_option( 'sn_cf_account_id', 'opt_account_xyz' );
// sn_cf_analytics_token NOT set → should return null.
ok( sn_analytics_config() === null, 'config: null when token option missing' );

// ── Test 5: null when options are empty strings ───────────────────────────────
echo "\nTest 5: config → null when options are empty strings\n";
ae_reset();
update_option( 'sn_cf_analytics_token', '' );
update_option( 'sn_cf_account_id', '' );
ok( sn_analytics_config() === null, 'config: null when options are empty strings' );

// ══════════════════════════════════════════════════════════════════════════════
// SECTION B: Constant-based tests — define constants just-in-time.
// SN_CF_ANALYTICS_TOKEN is defined first (Test 6), SN_CF_ACCOUNT_ID in Test 7.
// Constant-wins-over-option test runs last (Test 8) when both are defined.
// ══════════════════════════════════════════════════════════════════════════════

// ── Test 6: config → null when only token constant defined ───────────────────
echo "\nTest 6: config → null with only token constant\n";
ae_reset();
if ( ! defined( 'SN_CF_ANALYTICS_TOKEN' ) ) {
	define( 'SN_CF_ANALYTICS_TOKEN', 'tok_abc123' );
}
// SN_CF_ACCOUNT_ID not yet defined → should return null.
ok( sn_analytics_config() === null, 'config: null when account_id missing' );

// ── Test 7: config → full array when both constants defined ──────────────────
echo "\nTest 7: config → array when both constants defined\n";
ae_reset();
if ( ! defined( 'SN_CF_ACCOUNT_ID' ) ) {
	define( 'SN_CF_ACCOUNT_ID', 'acct_deadbeef1234567890abcdef12345678' );
}
$cfg = sn_analytics_config();
ok( is_array( $cfg ), 'config: returns array when both constants set' );
ok( isset( $cfg['token'] ) && $cfg['token'] === 'tok_abc123', 'config: token matches SN_CF_ANALYTICS_TOKEN' );
ok( isset( $cfg['account_id'] ) && $cfg['account_id'] === 'acct_deadbeef1234567890abcdef12345678', 'config: account_id matches SN_CF_ACCOUNT_ID' );

// ── Test 8: constant wins over option when both are set ───────────────────────
// Both constants are now defined (from Tests 6 + 7). Set conflicting options.
echo "\nTest 8: config → constant value wins over option when both present\n";
ae_reset();
update_option( 'sn_cf_analytics_token', 'SHOULD_NOT_WIN_token' );
update_option( 'sn_cf_account_id', 'SHOULD_NOT_WIN_account' );
$cfg = sn_analytics_config();
ok( is_array( $cfg ), 'config: returns array when constant + option both set' );
ok( isset( $cfg['token'] ) && $cfg['token'] === 'tok_abc123', 'config: constant token wins over option' );
ok( isset( $cfg['account_id'] ) && $cfg['account_id'] === 'acct_deadbeef1234567890abcdef12345678', 'config: constant account_id wins over option' );

// ── Test 9: sn_analytics_query() → parses 200 AE JSON → returns data array ───
echo "\nTest 9: query → parses 200 response, returns data array\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

$sql    = "SELECT blob1 AS event_type, sum(_sample_interval) AS events FROM SN_DATASET WHERE timestamp >= NOW() - INTERVAL '7' DAY GROUP BY blob1";
$result = sn_analytics_query( $sql );

ok( is_array( $result ), 'query: returns array on 200' );
ok( count( $result ) === 1, 'query: data array has one row' );
ok( ( $result[0]['event_type'] ?? '' ) === 'pv', 'query: row event_type is pv' );
ok( ( $result[0]['events'] ?? 0 ) === 42, 'query: row events is 42' );

// ── Test 10: POST body is the raw SQL string ──────────────────────────────────
echo "\nTest 10: POST body is the raw SQL string (not a JSON envelope)\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

$sql2 = "SELECT count() FROM SN_DATASET";
sn_analytics_query( $sql2 );

$last_call = end( $GLOBALS['__ae_post_calls'] );
ok( isset( $last_call['args']['body'] ), 'query: POST args contain body key' );
ok( $last_call['args']['body'] === $sql2, 'query: POST body is the raw SQL string verbatim' );

// ── Test 11: Authorization header is "Bearer <token>" ────────────────────────
echo "\nTest 11: Authorization header is 'Bearer <token>'\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

sn_analytics_query( "SELECT 1" );

$last_call   = end( $GLOBALS['__ae_post_calls'] );
$auth_header = $last_call['args']['headers']['Authorization'] ?? '';
ok( $auth_header === 'Bearer tok_abc123', 'query: Authorization header is "Bearer <token>"' );

// ── Test 12: timeout=6 and redirection=0 ─────────────────────────────────────
echo "\nTest 12: HTTP args have timeout=6 and redirection=0\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

sn_analytics_query( "SELECT 1" );

$last_call = end( $GLOBALS['__ae_post_calls'] );
ok( ( $last_call['args']['timeout'] ?? null ) === 6, 'query: timeout is 6' );
ok( ( $last_call['args']['redirection'] ?? null ) === 0, 'query: redirection is 0 (SSRF hardening)' );

// ── Test 13: non-200 (401) → null + error recorded ───────────────────────────
echo "\nTest 13: non-200 response → null + error recorded in transient\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 401;
$GLOBALS['__ae_mock_body'] = '{"errors":[{"code":10000,"message":"Authentication error"}]}';

$result = sn_analytics_query( "SELECT 1" );
ok( $result === null, 'query: 401 → null' );

$err = sn_analytics_last_error();
ok( is_array( $err ), 'error: last_error returns an array after 401' );
ok( ( $err['code'] ?? 0 ) === 401, 'error: error code is 401' );
ok( isset( $err['url'] ) && strpos( $err['url'], 'analytics_engine/sql' ) !== false, 'error: url contains analytics_engine/sql' );
ok( isset( $err['message'] ), 'error: message key present' );
ok( isset( $err['when'] ) && is_int( $err['when'] ), 'error: when is an integer timestamp' );

// ── Test 14: WP_Error → null + error recorded ────────────────────────────────
echo "\nTest 14: WP_Error → null + error recorded\n";
ae_reset();
$GLOBALS['__ae_wp_error_mode'] = true;

$result = sn_analytics_query( "SELECT 1" );
ok( $result === null, 'query: WP_Error → null' );

$err = sn_analytics_last_error();
ok( is_array( $err ), 'error: last_error returns array after WP_Error' );
ok( ( $err['code'] ?? -1 ) === 0, 'error: code is 0 for WP_Error (no HTTP status)' );
ok( strpos( (string) ( $err['message'] ?? '' ), 'connection timed out' ) !== false, 'error: message contains WP_Error message' );

// ── Test 15: sn_analytics_last_error() → null when no error stored ───────────
echo "\nTest 15: last_error → null when no error in transient\n";
ae_reset();
ok( sn_analytics_last_error() === null, 'last_error: null when transient absent' );

// ── Test 16: 200 response clears any prior error transient ───────────────────
echo "\nTest 16: successful 200 clears a prior error\n";
ae_reset();
// Pre-seed a stale error.
set_transient( SN_ANALYTICS_ERR_KEY, array( 'url' => 'x', 'code' => 503, 'message' => 'old', 'when' => time() - 60 ), 300 );
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

sn_analytics_query( "SELECT 1" );
ok( sn_analytics_last_error() === null, 'query: 200 success clears prior error transient' );

// ── Test 17: 200 response with empty data array → empty array returned ────────
echo "\nTest 17: 200 response with empty data array → empty array returned\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = json_encode( array( 'meta' => array(), 'data' => array(), 'rows' => 0 ) );

$result = sn_analytics_query( "SELECT 1 WHERE 0=1" );
ok( is_array( $result ) && count( $result ) === 0, 'query: empty data array returns []' );

// ── Test 18: malformed JSON → null + error recorded ──────────────────────────
echo "\nTest 18: 200 but unparseable JSON → null + error recorded\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = 'not-json{{{';

$result = sn_analytics_query( "SELECT 1" );
ok( $result === null, 'query: malformed JSON → null' );
$err = sn_analytics_last_error();
ok( is_array( $err ), 'error: last_error recorded for malformed JSON' );

// ── Test 19: sn_analytics_probe() → true when query returns array ─────────────
echo "\nTest 19: sn_analytics_probe() → true when stub returns well-formed data\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = json_encode( array(
	'meta' => array( array( 'name' => 'n', 'type' => 'UInt64' ) ),
	'data' => array( array( 'n' => 1 ) ),
	'rows' => 1,
) );
ok( sn_analytics_probe() === true, 'probe: true when query stub returns an array' );

// ── Test 20: sn_analytics_probe() → false on transport/auth failure ───────────
echo "\nTest 20: sn_analytics_probe() → false on 401 failure\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 401;
$GLOBALS['__ae_mock_body'] = '{"errors":[{"code":10000,"message":"Authentication error"}]}';
ok( sn_analytics_probe() === false, 'probe: false when query returns null (401)' );

// ── Test 21: sn_analytics_probe() → false on WP_Error ────────────────────────
echo "\nTest 21: sn_analytics_probe() → false on WP_Error\n";
ae_reset();
$GLOBALS['__ae_wp_error_mode'] = true;
ok( sn_analytics_probe() === false, 'probe: false when query returns null (WP_Error)' );

// ── Row-cap truncation flag (adversarial finding 3) ───────────────────────────
// AE responses carry {rows, rows_before_limit_at_least}: when the latter
// exceeds the former the result set was ROW-CAP TRUNCATED — the returned rows
// are real but the set is INCOMPLETE, so any "absent = zero" reasoning over it
// (the gated pageview_visits merge) is invalid. The flag is request-scoped,
// re-recorded on EVERY call, and false on every failure path (a null return
// already carries no completeness claim).
echo "\nGroup: row-cap truncation flag (sn_analytics_last_result_truncated)\n";
ok( function_exists( 'sn_analytics_last_result_truncated' ), 'truncation: sn_analytics_last_result_truncated() exists' );

ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = json_encode( array(
	'meta' => array( array( 'name' => 'pageview_visits', 'type' => 'UInt64' ) ),
	'data' => array( array( 'day' => '2026-07-15', 'path' => '/', 'class' => 'human', 'pageview_visits' => '4' ) ),
	'rows' => 1,
	'rows_before_limit_at_least' => 5,
) );
$result = sn_analytics_query( 'SELECT 1' );
ok( is_array( $result ) && 1 === count( $result ), 'truncation: a truncated 200 still returns its (real) rows' );
ok( true === sn_analytics_last_result_truncated(), 'truncation: rows_before_limit_at_least (5) > rows (1) → flagged TRUE' );

$GLOBALS['__ae_mock_body'] = json_encode( array(
	'meta' => array(),
	'data' => array( array( 'n' => 1 ), array( 'n' => 2 ) ),
	'rows' => 2,
	'rows_before_limit_at_least' => 2,
) );
sn_analytics_query( 'SELECT 1' );
ok( false === sn_analytics_last_result_truncated(), 'truncation: rows === rows_before_limit_at_least → complete → FALSE' );

// Counters absent (the pre-pinned $AE_GOOD_BODY shape) → no evidence → false.
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;
sn_analytics_query( 'SELECT 1' );
ok( false === sn_analytics_last_result_truncated(), 'truncation: envelope without counters → no truncation evidence → FALSE' );

// A failure RESETS a stale truncated verdict — the flag always describes the
// LAST call, never a previous response.
$GLOBALS['__ae_mock_body'] = json_encode( array( 'meta' => array(), 'data' => array(), 'rows' => 0, 'rows_before_limit_at_least' => 9 ) );
sn_analytics_query( 'SELECT 1' );
ok( true === sn_analytics_last_result_truncated(), 'truncation: seeded a truncated verdict (0 returned of ≥9)' );
$GLOBALS['__ae_mock_code'] = 401;
$GLOBALS['__ae_mock_body'] = '{"errors":[{"code":10000,"message":"Authentication error"}]}';
sn_analytics_query( 'SELECT 1' );
ok( false === sn_analytics_last_result_truncated(), 'truncation: a failed call (401 → null) resets the flag to FALSE' );

// ── Site timezone name (AE tz-aware bucketing, v9.26.4) ───────────────────────
echo "\nGroup: sn_analytics_site_tz_name\n";
$GLOBALS['__ae_tz_string'] = 'America/New_York';
ok( sn_analytics_site_tz_name() === 'America/New_York', 'tz-name: a real IANA identifier passes through' );
$GLOBALS['__ae_tz_string'] = 'UTC';
ok( sn_analytics_site_tz_name() === 'UTC', 'tz-name: UTC is a valid identifier' );
$GLOBALS['__ae_tz_string'] = 'Europe/Madrid';
ok( sn_analytics_site_tz_name() === 'Europe/Madrid', 'tz-name: another named zone passes through' );
$GLOBALS['__ae_tz_string'] = '+05:30';
ok( sn_analytics_site_tz_name() === '', 'tz-name: a manual UTC offset is NOT a usable AE zone → empty' );
$GLOBALS['__ae_tz_string'] = "Bad'; DROP TABLE x --";
ok( sn_analytics_site_tz_name() === '', 'tz-name: a non-identifier / injectable string is rejected → empty' );
$GLOBALS['__ae_tz_string'] = '';
ok( sn_analytics_site_tz_name() === '', 'tz-name: empty setting → empty' );

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
