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
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
// Configurable purge-endpoint response — default is the happy path; scenarios
// below override it to exercise the non-2xx bounded-capture path (FIX 3b).
$GLOBALS['__purge_response'] = array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 12345 ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__http'] = array();
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = array( 'url' => $url, 'args' => $args );
	if ( strpos( $url, 'oauth/access_token' ) !== false ) {
		return array( 'body' => json_encode( array( 'access_token' => 'TESTTOKEN' ) ), 'response' => array( 'code' => 200 ) );
	}
	if ( strpos( $url, 'app/cache/purge' ) !== false ) {
		return $GLOBALS['__purge_response'];
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
// (render hardening FIX 3a): 15s → 5s.
ok( 5 === ( $oauth['args']['timeout'] ?? null ), 'oauth request timeout is 5s (was 15s)' );

$purge = $GLOBALS['__http'][1];
ok( strpos( $purge['url'], 'app/cache/purge' ) !== false, 'second call hits app/cache/purge' );
ok( ( $purge['args']['headers']['Authorization'] ?? '' ) === 'Bearer TESTTOKEN', 'purge sends the Bearer token' );
ok( 0 === ( $purge['args']['redirection'] ?? -1 ), 'purge request disables redirects (no Bearer forward on a 3xx)' );
ok( (string) ( $purge['args']['body']['server_id'] ?? '' ) === '111', 'purge body carries server_id' );
ok( (string) ( $purge['args']['body']['app_id'] ?? '' ) === '222', 'purge body carries app_id' );
ok( 5 === ( $purge['args']['timeout'] ?? null ), 'purge request timeout is 5s (was 15s)' );

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

// --- Scenario 4: non-2xx purge response — bounded error capture (FIX 3b) --
// Mirrors the observed live 422: Cloudways' field-validation error envelope.
echo "\nGroup: non-2xx purge response — bounded, sanitized error capture\n";
$GLOBALS['__purge_response'] = array(
	'body'     => json_encode( array( 'status' => false, 'message' => 'server_id: invalid or not found for this account.' ) ),
	'response' => array( 'code' => 422 ),
);
$GLOBALS['__opts'] = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
$res4 = sn_cloudways_purge_app();
ok( false === $res4, 'purge returns false on a 422' );
$stored4 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( empty( $stored4['ok'] ), 'last-purge option records ok=false' );
ok( 422 === (int) ( $stored4['http'] ?? 0 ), 'last-purge option records the http code (422)' );
ok( isset( $stored4['error'] ), 'last-purge option carries a captured error field' );
ok( false !== strpos( (string) ( $stored4['error'] ?? '' ), 'invalid or not found' ), 'the field-validation message is visible in the captured error' );

// --- Scenario 5: a hostile/oversized error body — capped + tags stripped --
echo "\nGroup: hostile long error body — 300-char cap, tags stripped\n";
$hostile = '<script>evil()</script>' . str_repeat( 'X', 500 );
$GLOBALS['__purge_response'] = array( 'body' => $hostile, 'response' => array( 'code' => 500 ) );
$GLOBALS['__opts'] = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
sn_cloudways_purge_app();
$stored5 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( 300 === strlen( (string) ( $stored5['error'] ?? '' ) ), 'captured error is capped to exactly 300 chars' );
ok( false === strpos( (string) ( $stored5['error'] ?? '' ), '<script>' ), 'tags are stripped from the captured error' );

// --- Scenario 6: a successful purge does NOT carry an error field ---------
echo "\nGroup: a successful purge carries no error field\n";
$GLOBALS['__purge_response'] = array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 999 ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__opts'] = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
sn_cloudways_purge_app();
$stored6 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( ! isset( $stored6['error'] ), 'a successful (ok=true) purge does not add an error field' );

// --- Scenario 7: 422 "operation already in progress" is NOT a failure -----
// Live 2026-08-05: every purge leg on this site read ✕ while the site was
// verifiably fresh. Cloudways SERIALIZES cache operations per server, so a
// purge issued while one is still open is rejected 422 — even though the
// purge we wanted is already running. Recording that as a failure inverts the
// truth: the outcome we asked for is in flight, under someone else's id.
echo "\nGroup: 422 'operation already in progress' coalesces onto the open operation\n";
$in_progress = json_encode(
	array(
		'message'   => 'An operation is already in progress for this server.',
		'operation' => array(
			'id'                       => '92897180',
			'type'                     => 'purge_app_cache',
			'server_id'                => '1432404',
			'estimated_time_remaining' => '0',
			'status'                   => 'Process is initiated',
			'is_completed'             => '0',
		),
	)
);
$GLOBALS['__purge_response']        = array( 'body' => $in_progress, 'response' => array( 'code' => 422 ) );
$GLOBALS['__opts']                  = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
$res7                               = sn_cloudways_purge_app();
ok( true === $res7, 'an in-flight purge_app_cache returns true — the purge IS happening' );
$stored7 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( ! empty( $stored7['ok'] ), 'last-purge records ok=true' );
ok( 92897180 === (int) ( $stored7['operation_id'] ?? 0 ), 'the OPEN operation id is adopted, not left at 0' );
ok( ! empty( $stored7['coalesced'] ), 'the row is marked coalesced so this is never mistaken for a fresh dispatch' );
ok( 422 === (int) ( $stored7['http'] ?? 0 ), 'the real http code is still recorded verbatim' );
ok( ! isset( $stored7['error'] ), 'a coalesced purge carries no error field' );

// The narrow reading is the whole point: only an in-flight purge of the SAME
// type counts. Anything else 422s as a genuine failure, or this becomes a
// success-only readout that reports healthy while the cache goes stale.
echo "\nGroup: coalescing is narrow — other in-flight operations still fail\n";
$other_op = json_encode(
	array(
		'message'   => 'An operation is already in progress for this server.',
		'operation' => array( 'id' => '77', 'type' => 'restart_mysql', 'server_id' => '1432404', 'is_completed' => '0' ),
	)
);
$GLOBALS['__purge_response']        = array( 'body' => $other_op, 'response' => array( 'code' => 422 ) );
$GLOBALS['__opts']                  = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
ok( false === sn_cloudways_purge_app(), 'an unrelated in-flight operation (restart_mysql) is still a failure' );
$stored8 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( empty( $stored8['ok'] ), 'unrelated operation records ok=false' );
ok( isset( $stored8['error'] ), 'unrelated operation still captures the error envelope' );
ok( empty( $stored8['coalesced'] ), 'unrelated operation is not marked coalesced' );

// A COMPLETED operation is not in flight — nothing is purging, so nothing to
// coalesce onto. Treating it as success would report a purge that never ran.
$done_op = json_encode(
	array(
		'message'   => 'An operation is already in progress for this server.',
		'operation' => array( 'id' => '88', 'type' => 'purge_app_cache', 'server_id' => '1432404', 'is_completed' => '1' ),
	)
);
$GLOBALS['__purge_response']        = array( 'body' => $done_op, 'response' => array( 'code' => 422 ) );
$GLOBALS['__opts']                  = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
ok( false === sn_cloudways_purge_app(), 'a COMPLETED purge operation is not in flight and does not coalesce' );

// Malformed 422 bodies must not be read optimistically.
foreach ( array( 'not json at all', '{"message":"An operation is already in progress for this server."}', '{"operation":{"type":"purge_app_cache"}}' ) as $i => $bad ) {
	$GLOBALS['__purge_response']        = array( 'body' => $bad, 'response' => array( 'code' => 422 ) );
	$GLOBALS['__opts']                  = array();
	$GLOBALS['sn_cloudways_purge_done'] = false;
	ok( false === sn_cloudways_purge_app(), 'malformed 422 body #' . ( $i + 1 ) . ' does not coalesce' );
}

// A 200 with status:true must keep its own operation id — the coalesce path
// must not leak into the ordinary success path.
$GLOBALS['__purge_response']        = array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 4242 ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__opts']                  = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
sn_cloudways_purge_app();
$stored9 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( 4242 === (int) ( $stored9['operation_id'] ?? 0 ), 'an ordinary 200 keeps its own operation id' );
ok( empty( $stored9['coalesced'] ), 'an ordinary 200 is not marked coalesced' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
