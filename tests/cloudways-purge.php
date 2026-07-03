<?php
/**
 * Standalone test: Cloudways API app/Varnish cache purge.
 * Run: php tests/cloudways-purge.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// --- WP stubs -------------------------------------------------------------
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return false; } }
$GLOBALS['__http'] = array();
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = array( 'url' => $url, 'args' => $args );
	if ( strpos( $url, 'oauth/access_token' ) !== false ) {
		return array( 'body' => json_encode( array( 'access_token' => 'TESTTOKEN' ) ), 'response' => array( 'code' => 200 ) );
	}
	if ( strpos( $url, 'app/cache/purge' ) !== false ) {
		return array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 12345 ) ), 'response' => array( 'code' => 200 ) );
	}
	return array( 'body' => '{}', 'response' => array( 'code' => 200 ) );
}
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
$GLOBALS['__opts'] = array();
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/cloudways-purge.php';

// --- Scenario 1: NOT fully configured (only 3 of 4 constants) -------------
define( 'SN_CLOUDWAYS_EMAIL', 'me@example.test' );
define( 'SN_CLOUDWAYS_API_KEY', 'SECRETKEY123' );
define( 'SN_CLOUDWAYS_SERVER_ID', '111' );
// SN_CLOUDWAYS_APP_ID intentionally undefined here.
echo "Group: not configured (missing app_id)\n";
ok( false === sn_cloudways_is_configured(), 'is_configured false when a constant is missing' );
$GLOBALS['__http'] = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
ok( false === sn_cloudways_purge_app(), 'purge returns false when not configured' );
ok( empty( $GLOBALS['__http'] ), 'no HTTP call when not configured' );

// --- Scenario 2: fully configured ----------------------------------------
define( 'SN_CLOUDWAYS_APP_ID', '222' );
echo "\nGroup: configured — token + purge\n";
ok( true === sn_cloudways_is_configured(), 'is_configured true with all four constants' );
$GLOBALS['__http'] = array();
$GLOBALS['__opts'] = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
$res = sn_cloudways_purge_app();
ok( true === $res, 'purge returns true on {status:true}' );
ok( count( $GLOBALS['__http'] ) === 2, 'exactly two HTTP calls (oauth + purge)' );

$oauth = $GLOBALS['__http'][0];
ok( strpos( $oauth['url'], 'oauth/access_token' ) !== false, 'first call hits oauth/access_token' );
ok( ( $oauth['args']['body']['email'] ?? '' ) === 'me@example.test', 'oauth body carries the email' );
ok( ( $oauth['args']['body']['api_key'] ?? '' ) === 'SECRETKEY123', 'oauth body carries the api_key' );
// v8.7.1 (CMA audit INFO-1): the account-wide api_key rides the POST body, so a 307/308
// redirect would re-send it — redirection=>0 forbids following any 3xx from the API host.
ok( 0 === ( $oauth['args']['redirection'] ?? -1 ), 'oauth request disables redirects (no api_key forward on a 3xx)' );

$purge = $GLOBALS['__http'][1];
ok( strpos( $purge['url'], 'app/cache/purge' ) !== false, 'second call hits app/cache/purge' );
ok( ( $purge['args']['headers']['Authorization'] ?? '' ) === 'Bearer TESTTOKEN', 'purge sends the Bearer token' );
ok( 0 === ( $purge['args']['redirection'] ?? -1 ), 'purge request disables redirects (no Bearer forward on a 3xx)' );
ok( (string) ( $purge['args']['body']['server_id'] ?? '' ) === '111', 'purge body carries server_id' );
ok( (string) ( $purge['args']['body']['app_id'] ?? '' ) === '222', 'purge body carries app_id' );

$stored = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( ! empty( $stored['ok'] ), 'last-purge option records ok=true' );
ok( (int) ( $stored['operation_id'] ?? 0 ) === 12345, 'last-purge option records the operation_id' );
$blob = json_encode( $stored );
ok( strpos( $blob, 'SECRETKEY123' ) === false && strpos( $blob, 'TESTTOKEN' ) === false, 'the stored option leaks neither key nor token' );

// --- Scenario 3: once-per-request guard -----------------------------------
echo "\nGroup: once-per-request guard\n";
$GLOBALS['__http'] = array();
// guard is now set from the successful purge above
ok( false === sn_cloudways_purge_app(), 'second call in the same request is a no-op' );
ok( empty( $GLOBALS['__http'] ), 'guard prevents a second HTTP round-trip' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
