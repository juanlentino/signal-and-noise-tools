<?php
/**
 * Tests for inc/analytics-api.php — Cloudflare Analytics Engine SQL read-client.
 *
 * Covers:
 *   - sn_analytics_config() → null when constants absent; array when both set.
 *   - sn_analytics_query() → parses a canned 200 AE JSON response → returns data array.
 *   - sn_analytics_query() → non-200 (401) → null + error recorded.
 *   - sn_analytics_query() → WP_Error → null + error recorded.
 *   - POST body is the raw SQL string (not a JSON envelope).
 *   - Authorization header is "Bearer <token>".
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

// add_action stub — analytics-api.php registers no hooks, but the guard is here for safety.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
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
}

// ── Canned AE SQL API JSON response ──────────────────────────────────────────
$AE_GOOD_BODY = json_encode( array(
	'meta'  => array( array( 'name' => 'event_type', 'type' => 'String' ), array( 'name' => 'events', 'type' => 'UInt64' ) ),
	'data'  => array( array( 'event_type' => 'pv', 'events' => 42 ) ),
	'rows'  => 1,
) );

echo "Analytics Engine SQL read-client — plugin v5.0.1\n\n";

// ── Test 1: sn_analytics_config() → null when neither constant defined ────────
echo "Test 1: config → null when constants absent\n";
ae_reset();
ok( sn_analytics_config() === null, 'config: null when SN_CF_ANALYTICS_TOKEN and SN_CF_ACCOUNT_ID not defined' );

// ── Test 2: sn_analytics_config() → null when only token defined ──────────────
echo "\nTest 2: config → null with only token constant\n";
ae_reset();
if ( ! defined( 'SN_CF_ANALYTICS_TOKEN' ) ) {
	define( 'SN_CF_ANALYTICS_TOKEN', 'tok_abc123' );
}
// SN_CF_ACCOUNT_ID not yet defined → should return null.
ok( sn_analytics_config() === null, 'config: null when account_id missing' );

// ── Test 3: sn_analytics_config() → full array when both constants defined ────
echo "\nTest 3: config → array when both constants defined\n";
ae_reset();
if ( ! defined( 'SN_CF_ACCOUNT_ID' ) ) {
	define( 'SN_CF_ACCOUNT_ID', 'acct_deadbeef1234567890abcdef12345678' );
}
$cfg = sn_analytics_config();
ok( is_array( $cfg ), 'config: returns array when both constants set' );
ok( isset( $cfg['token'] ) && $cfg['token'] === 'tok_abc123', 'config: token matches SN_CF_ANALYTICS_TOKEN' );
ok( isset( $cfg['account_id'] ) && $cfg['account_id'] === 'acct_deadbeef1234567890abcdef12345678', 'config: account_id matches SN_CF_ACCOUNT_ID' );

// ── Test 4: sn_analytics_query() → parses 200 AE JSON → returns data array ───
echo "\nTest 4: query → parses 200 response, returns data array\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

$sql    = "SELECT blob1 AS event_type, sum(_sample_interval) AS events FROM SN_DATASET WHERE timestamp >= NOW() - INTERVAL '7' DAY GROUP BY blob1";
$result = sn_analytics_query( $sql );

ok( is_array( $result ), 'query: returns array on 200' );
ok( count( $result ) === 1, 'query: data array has one row' );
ok( ( $result[0]['event_type'] ?? '' ) === 'pv', 'query: row event_type is pv' );
ok( ( $result[0]['events'] ?? 0 ) === 42, 'query: row events is 42' );

// ── Test 5: POST body is the raw SQL string ───────────────────────────────────
echo "\nTest 5: POST body is the raw SQL string (not a JSON envelope)\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

$sql2 = "SELECT count() FROM SN_DATASET";
sn_analytics_query( $sql2 );

$last_call = end( $GLOBALS['__ae_post_calls'] );
ok( isset( $last_call['args']['body'] ), 'query: POST args contain body key' );
ok( $last_call['args']['body'] === $sql2, 'query: POST body is the raw SQL string verbatim' );

// ── Test 6: Authorization header is "Bearer <token>" ─────────────────────────
echo "\nTest 6: Authorization header is 'Bearer <token>'\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

sn_analytics_query( "SELECT 1" );

$last_call  = end( $GLOBALS['__ae_post_calls'] );
$auth_header = $last_call['args']['headers']['Authorization'] ?? '';
ok( $auth_header === 'Bearer tok_abc123', 'query: Authorization header is "Bearer <token>"' );

// ── Test 7: timeout=6 and redirection=0 ──────────────────────────────────────
echo "\nTest 7: HTTP args have timeout=6 and redirection=0\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

sn_analytics_query( "SELECT 1" );

$last_call = end( $GLOBALS['__ae_post_calls'] );
ok( ( $last_call['args']['timeout'] ?? null ) === 6, 'query: timeout is 6' );
ok( ( $last_call['args']['redirection'] ?? null ) === 0, 'query: redirection is 0 (SSRF hardening)' );

// ── Test 8: non-200 (401) → null + error recorded ────────────────────────────
echo "\nTest 8: non-200 response → null + error recorded in transient\n";
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

// ── Test 9: WP_Error → null + error recorded ─────────────────────────────────
echo "\nTest 9: WP_Error → null + error recorded\n";
ae_reset();
$GLOBALS['__ae_wp_error_mode'] = true;

$result = sn_analytics_query( "SELECT 1" );
ok( $result === null, 'query: WP_Error → null' );

$err = sn_analytics_last_error();
ok( is_array( $err ), 'error: last_error returns array after WP_Error' );
ok( ( $err['code'] ?? -1 ) === 0, 'error: code is 0 for WP_Error (no HTTP status)' );
ok( strpos( (string) ( $err['message'] ?? '' ), 'connection timed out' ) !== false, 'error: message contains WP_Error message' );

// ── Test 10: sn_analytics_last_error() → null when no error stored ───────────
echo "\nTest 10: last_error → null when no error in transient\n";
ae_reset();
ok( sn_analytics_last_error() === null, 'last_error: null when transient absent' );

// ── Test 11: 200 response clears any prior error transient ───────────────────
echo "\nTest 11: successful 200 clears a prior error\n";
ae_reset();
// Pre-seed a stale error.
set_transient( SN_ANALYTICS_ERR_KEY, array( 'url' => 'x', 'code' => 503, 'message' => 'old', 'when' => time() - 60 ), 300 );
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = $AE_GOOD_BODY;

sn_analytics_query( "SELECT 1" );
ok( sn_analytics_last_error() === null, 'query: 200 success clears prior error transient' );

// ── Test 12: query with no config → null without POST ────────────────────────
// This tests guard: if sn_analytics_config() is null (e.g. constants cleared),
// sn_analytics_query() must not fire a network request.
// Since constants are now defined, we test the body-parsing of empty data.
echo "\nTest 12: 200 response with empty data array → empty array returned\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = json_encode( array( 'meta' => array(), 'data' => array(), 'rows' => 0 ) );

$result = sn_analytics_query( "SELECT 1 WHERE 0=1" );
ok( is_array( $result ) && count( $result ) === 0, 'query: empty data array returns []' );

// ── Test 13: malformed JSON → null + error recorded ──────────────────────────
echo "\nTest 13: 200 but unparseable JSON → null + error recorded\n";
ae_reset();
$GLOBALS['__ae_mock_code'] = 200;
$GLOBALS['__ae_mock_body'] = 'not-json{{{';

$result = sn_analytics_query( "SELECT 1" );
ok( $result === null, 'query: malformed JSON → null' );
$err = sn_analytics_last_error();
ok( is_array( $err ), 'error: last_error recorded for malformed JSON' );

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
