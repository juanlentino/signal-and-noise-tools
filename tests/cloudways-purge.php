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
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $msg;
		public function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->msg; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
// v10.52.5: the timeout is filterable, so the harness needs a real filter map.
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; } }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args(); array_shift( $args );
		foreach ( $GLOBALS['__filters'][ $tag ] ?? array() as $cb ) { $value = call_user_func_array( $cb, $args ); $args[0] = $value; }
		return $value;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
// Configurable purge-endpoint response — default is the happy path; scenarios
// below override it to exercise the non-2xx bounded-capture path (FIX 3b).
$GLOBALS['__purge_response'] = array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 12345 ) ), 'response' => array( 'code' => 200 ) );
// v10.52.4: the token exchange is now cached, so scenarios need to vary its
// response (expires_in, failures) and to COUNT how often it is actually hit.
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'TESTTOKEN' ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__purge_responses'] = array(); // Optional queue: one response per purge call.
$GLOBALS['__http'] = array();
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = array( 'url' => $url, 'args' => $args );
	if ( strpos( $url, 'oauth/access_token' ) !== false ) {
		return $GLOBALS['__token_response'];
	}
	if ( strpos( $url, 'app/cache/purge' ) !== false ) {
		if ( ! empty( $GLOBALS['__purge_responses'] ) ) {
			return array_shift( $GLOBALS['__purge_responses'] );
		}
		return $GLOBALS['__purge_response'];
	}
	return array( 'body' => '{}', 'response' => array( 'code' => 200 ) );
}
// Transient stubs that RECORD the ttl — the cap is a security decision, so it
// has to be asserted, not assumed.
$GLOBALS['__transients'] = array();
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = array( 'value' => $v, 'ttl' => (int) $ttl ); return true; }
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ]['value'] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
// Count only the OAuth calls — the whole point of the cache.
function token_calls() {
	return count( array_filter( $GLOBALS['__http'], static function ( $r ) {
		return false !== strpos( (string) $r['url'], 'oauth/access_token' );
	} ) );
}
// Reset every layer between scenarios: request memo, transient, HTTP log,
// per-request purge guard.
function token_reset( $keep_transient = false ) {
	unset( $GLOBALS['sn_cloudways_token_memo'] );
	if ( ! $keep_transient ) { $GLOBALS['__transients'] = array(); }
	$GLOBALS['__http'] = array();
	$GLOBALS['sn_cloudways_purge_done'] = false;
	$GLOBALS['__purge_responses'] = array();
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
ok( 5.0 === (float) ( $oauth['args']['timeout'] ?? null ), 'oauth request timeout defaults to 5s (float since v10.52.5, filterable)' );

$purge = $GLOBALS['__http'][1];
ok( strpos( $purge['url'], 'app/cache/purge' ) !== false, 'second call hits app/cache/purge' );
ok( ( $purge['args']['headers']['Authorization'] ?? '' ) === 'Bearer TESTTOKEN', 'purge sends the Bearer token' );
ok( 0 === ( $purge['args']['redirection'] ?? -1 ), 'purge request disables redirects (no Bearer forward on a 3xx)' );
ok( (string) ( $purge['args']['body']['server_id'] ?? '' ) === '111', 'purge body carries server_id' );
ok( (string) ( $purge['args']['body']['app_id'] ?? '' ) === '222', 'purge body carries app_id' );
ok( 5.0 === (float) ( $purge['args']['timeout'] ?? null ), 'purge request timeout defaults to 5s (float since v10.52.5, filterable)' );

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

// --- Scenario 8: token caching (v10.52.4) --------------------------------
// Live 2026-08-05: two purges seconds apart produced `stage: auth` on the
// second, because every purge minted a fresh OAuth token and Cloudways
// rate-limits that endpoint. The purge never even reached the API — a
// self-inflicted failure that reads exactly like a bad credential.
echo "\nGroup: OAuth token caching — a burst must not manufacture an auth failure\n";

// Two purges in ONE request: the exact shape that failed live.
token_reset();
$GLOBALS['__purge_response'] = array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 1 ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__opts'] = array();
ok( true === sn_cloudways_purge_app(), 'first purge in the request succeeds' );
$GLOBALS['sn_cloudways_purge_done'] = false;
ok( true === sn_cloudways_purge_app(), 'second purge in the same request succeeds' );
ok( 1 === token_calls(), 'the token is exchanged ONCE across both purges (was twice — the live failure)' );

// A second request (memo gone, transient survives) still reuses the token.
token_reset( true );
$GLOBALS['sn_cloudways_purge_done'] = false;
sn_cloudways_purge_app();
ok( 0 === token_calls(), 'a later request reuses the cached token — no exchange at all' );

// --- TTL: the cap is a security decision, so assert it ---------------------
echo "\nGroup: token TTL is honoured, floored and CAPPED\n";
$ttl_of = static function () { return (int) ( $GLOBALS['__transients']['sn_cloudways_token']['ttl'] ?? -1 ); };

token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'T1', 'expires_in' => 3600 ) ), 'response' => array( 'code' => 200 ) );
sn_cloudways_get_token();
ok( 600 === $ttl_of(), 'a 1-hour expires_in is capped to SNT_CW_TOKEN_MAX_TTL (600s), not trusted wholesale' );

token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'T2', 'expires_in' => 300 ) ), 'response' => array( 'code' => 200 ) );
sn_cloudways_get_token();
ok( 240 === $ttl_of(), 'a short expires_in is honoured minus the 60s margin (300 -> 240)' );

token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'T3' ) ), 'response' => array( 'code' => 200 ) );
sn_cloudways_get_token();
ok( 300 === $ttl_of(), 'an absent expires_in falls back to the default TTL, never to "forever"' );

// Below the floor, caching buys nothing and costs a row — so don't.
token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'T4', 'expires_in' => 90 ) ), 'response' => array( 'code' => 200 ) );
ok( 'T4' === sn_cloudways_get_token(), 'a near-expiry token is still returned' );
ok( ! isset( $GLOBALS['__transients']['sn_cloudways_token'] ), 'but a sub-floor TTL is not persisted' );

// --- A FAILED exchange must never be cached -------------------------------
// Caching '' would turn one rate-limited moment into a TTL-long outage: every
// later purge would read the empty cache and fail at stage:auth without ever
// retrying. That is the live failure made permanent.
echo "\nGroup: failures are never cached\n";
token_reset();
$GLOBALS['__token_response'] = array( 'body' => '{"message":"rate limited"}', 'response' => array( 'code' => 429 ) );
ok( '' === sn_cloudways_get_token(), 'a rate-limited exchange returns empty' );
ok( ! isset( $GLOBALS['__transients']['sn_cloudways_token'] ), 'a failed exchange is NOT cached' );
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'RECOVERED' ) ), 'response' => array( 'code' => 200 ) );
ok( 'RECOVERED' === sn_cloudways_get_token(), 'the very next call retries and recovers — no TTL-long outage' );

token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => '' ) ), 'response' => array( 'code' => 200 ) );
ok( '' === sn_cloudways_get_token(), 'a 200 with an EMPTY access_token is still a failure' );
ok( ! isset( $GLOBALS['__transients']['sn_cloudways_token'] ), 'and is not cached either' );

// --- A cached token the API rejects: invalidate + retry ONCE --------------
// A cache that cannot be invalidated by the thing it caches for reports
// healthy while every purge fails.
echo "\nGroup: a rejected cached token invalidates and retries exactly once\n";
token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'STALE', 'expires_in' => 600 ) ), 'response' => array( 'code' => 200 ) );
sn_cloudways_get_token();                 // seed the cache
$GLOBALS['__http'] = array();             // count from here
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'FRESH', 'expires_in' => 600 ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__purge_responses'] = array(
	array( 'body' => '{"message":"Unauthorized"}', 'response' => array( 'code' => 401 ) ),
	array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 555 ) ), 'response' => array( 'code' => 200 ) ),
);
$GLOBALS['__opts'] = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
ok( true === sn_cloudways_purge_app(), 'a 401 on the cached token still ends in a successful purge' );
ok( 1 === token_calls(), 'exactly one re-exchange (not zero, not a loop)' );
$retried = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( ! empty( $retried['reauthed'] ), 'the row is marked reauthed — a second-attempt success is not a clean one' );
ok( 555 === (int) ( $retried['operation_id'] ?? 0 ), 'the retry’s operation id is what gets recorded' );
ok( 'FRESH' === get_transient( 'sn_cloudways_token' ), 'the stale token was replaced in the cache' );

// A genuinely bad credential must fail visibly rather than retry forever.
token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'BAD', 'expires_in' => 600 ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__purge_response'] = array( 'body' => '{"message":"Unauthorized"}', 'response' => array( 'code' => 401 ) );
$GLOBALS['__opts'] = array();
$GLOBALS['sn_cloudways_purge_done'] = false;
ok( false === sn_cloudways_purge_app(), 'a persistently rejected credential fails' );
ok( 2 === count( array_filter( $GLOBALS['__http'], static function ( $r ) { return false !== strpos( (string) $r['url'], 'app/cache/purge' ); } ) ), 'it retried exactly once — two purge attempts, then stop' );
$bad = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( isset( $bad['error'] ), 'the failure still captures the error envelope' );

// The token must never reach the stored row.
ok( false === strpos( json_encode( $bad ), 'BAD' ), 'no token leaks into the recorded row' );

// Restore defaults for any scenario appended after this one.
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'TESTTOKEN' ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__purge_response'] = array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 12345 ) ), 'response' => array( 'code' => 200 ) );
token_reset();

// --- Scenario 9: every failure row names itself (v10.52.5) ----------------
// Two live rows this session said a purge failed and nothing about why:
// `stage: auth` with no reason (really a rate limit from a purge burst), and
// `ok: false, http: 0, error: ""` (really a 5s timeout on a request that had
// already started the purge). Both sent the reader somewhere useless.
echo "\nGroup: transport failure is INCONCLUSIVE, not failed, and always names itself\n";
token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'T', 'expires_in' => 600 ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__purge_responses'] = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' ) );
$GLOBALS['__opts'] = array();
ok( false === sn_cloudways_purge_app(), 'a transport failure returns false — we cannot claim success' );
$t = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( 'dispatch' === ( $t['stage'] ?? '' ), 'the row names the stage it reached' );
ok( 0 === (int) ( $t['http'] ?? -1 ), 'http is 0 on a transport failure' );
ok( ! empty( $t['inconclusive'] ), 'the row is marked INCONCLUSIVE — a timeout is not evidence the purge did not run' );
ok( false !== strpos( (string) ( $t['error'] ?? '' ), 'cURL error 28' ), 'the WP_Error message is captured verbatim, not blanked' );
ok( false !== strpos( (string) ( $t['error'] ?? '' ), 'http_request_failed' ), 'and the WP_Error code with it' );

// A WP_Error carrying nothing still must not produce a blank reason.
token_reset();
$GLOBALS['__purge_responses'] = array( new WP_Error( '', '' ) );
$GLOBALS['__opts'] = array();
sn_cloudways_purge_app();
$t2 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( '' !== trim( (string) ( $t2['error'] ?? '' ) ), 'an empty WP_Error still yields a non-empty reason' );

// A non-2xx with an empty body: the status becomes the reason.
token_reset();
$GLOBALS['__purge_response'] = array( 'body' => '', 'response' => array( 'code' => 503 ) );
$GLOBALS['__opts'] = array();
sn_cloudways_purge_app();
$t3 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( false !== strpos( (string) ( $t3['error'] ?? '' ), '503' ), 'an empty error body falls back to naming the status' );
ok( empty( $t3['inconclusive'] ), 'a real HTTP response is NOT inconclusive — the server answered' );

echo "\nGroup: an auth failure names its own cause\n";
token_reset();
$GLOBALS['__token_response'] = array( 'body' => '{"message":"Too many requests"}', 'response' => array( 'code' => 429 ) );
$GLOBALS['__opts'] = array();
ok( false === sn_cloudways_purge_app(), 'a failed token exchange still fails the purge' );
$a = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( 'auth' === ( $a['stage'] ?? '' ), 'the row still names stage auth' );
ok( 429 === (int) ( $a['http'] ?? 0 ), 'and now carries the token exchange status — a rate limit, not a bad key' );
ok( false !== strpos( (string) ( $a['error'] ?? '' ), 'Too many requests' ), 'and the reason the API gave' );

token_reset();
$GLOBALS['__token_response'] = new WP_Error( 'http_request_failed', 'Could not resolve host' );
$GLOBALS['__opts'] = array();
sn_cloudways_purge_app();
$a2 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( 0 === (int) ( $a2['http'] ?? -1 ), 'a transport failure during auth records http 0' );
ok( false !== strpos( (string) ( $a2['error'] ?? '' ), 'Could not resolve host' ), 'and names the network error' );

token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'nothing' => 'here' ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__opts'] = array();
sn_cloudways_purge_app();
$a3 = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( false !== strpos( (string) ( $a3['error'] ?? '' ), 'no access_token' ), 'a 200 with no token is called out as a protocol surprise, not a network error' );

// --- The invariant, asserted across every failure shape -------------------
// This is the guard that stops the class of bug rather than the instances:
// whenever a row says ok:false, it must also say why.
echo "\nGroup: INVARIANT — ok:false always carries a non-empty error\n";
$shapes = array(
	'transport'      => array( 'purge' => new WP_Error( 'x', 'y' ) ),
	'empty-body-503' => array( 'purge' => array( 'body' => '', 'response' => array( 'code' => 503 ) ) ),
	'422-envelope'   => array( 'purge' => array( 'body' => '{"message":"nope"}', 'response' => array( 'code' => 422 ) ) ),
	'auth-429'       => array( 'token' => array( 'body' => '{"m":1}', 'response' => array( 'code' => 429 ) ) ),
	'auth-transport' => array( 'token' => new WP_Error( 'n', 'down' ) ),
	'auth-no-token'  => array( 'token' => array( 'body' => '{}', 'response' => array( 'code' => 200 ) ) ),
);
foreach ( $shapes as $name => $cfg ) {
	token_reset();
	$GLOBALS['__token_response'] = $cfg['token'] ?? array( 'body' => json_encode( array( 'access_token' => 'T' ) ), 'response' => array( 'code' => 200 ) );
	$GLOBALS['__purge_response'] = $cfg['purge'] ?? array( 'body' => json_encode( array( 'status' => true ) ), 'response' => array( 'code' => 200 ) );
	$GLOBALS['__opts'] = array();
	sn_cloudways_purge_app();
	$row = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
	if ( empty( $row['ok'] ) ) {
		ok( '' !== trim( (string) ( $row['error'] ?? '' ) ), "invariant [$name]: ok:false carries a non-empty error" );
		ok( in_array( ( $row['stage'] ?? '' ), array( 'auth', 'dispatch' ), true ), "invariant [$name]: the row names its stage" );
	} else {
		ok( false, "invariant [$name]: expected a failure row" );
	}
}

// A SUCCESS row must stay clean — no error, no inconclusive, but a stage.
token_reset();
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'T' ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__purge_response'] = array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 5 ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__opts'] = array();
sn_cloudways_purge_app();
$okrow = $GLOBALS['__opts']['sn_cloudways_last_purge'] ?? array();
ok( ! isset( $okrow['error'] ), 'a success row carries no error' );
ok( ! isset( $okrow['inconclusive'] ), 'a success row is not inconclusive' );
ok( 'dispatch' === ( $okrow['stage'] ?? '' ), 'a success row still names its stage' );

// --- Timeout is filterable, per leg ---------------------------------------
echo "\nGroup: the timeout is filterable per leg\n";
token_reset();
add_filter( 'sn_cloudways_timeout', static function ( $t, $leg ) { return 'purge' === $leg ? 12.0 : 3.0; }, 10, 2 );
$GLOBALS['__purge_response'] = array( 'body' => json_encode( array( 'status' => true ) ), 'response' => array( 'code' => 200 ) );
sn_cloudways_purge_app();
$auth_call  = null;
$purge_call = null;
foreach ( $GLOBALS['__http'] as $call ) {
	if ( false !== strpos( $call['url'], 'oauth/access_token' ) ) { $auth_call = $call; }
	if ( false !== strpos( $call['url'], 'app/cache/purge' ) ) { $purge_call = $call; }
}
ok( 3.0 === (float) ( $auth_call['args']['timeout'] ?? 0 ), 'the auth leg honours the filter' );
ok( 12.0 === (float) ( $purge_call['args']['timeout'] ?? 0 ), 'the purge leg honours the filter independently' );
$GLOBALS['__filters'] = array();

// Restore defaults.
$GLOBALS['__token_response'] = array( 'body' => json_encode( array( 'access_token' => 'TESTTOKEN' ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__purge_response'] = array( 'body' => json_encode( array( 'status' => true, 'operation_id' => 12345 ) ), 'response' => array( 'code' => 200 ) );
token_reset();

// ---- API base: v2 default + wp-config rollback (v12.18.0) ------------------
// v1 is deprecated. The override exists because v2 could not be verified against
// the live account before shipping — the credentials are wp-config-only and
// never leave the site — so wp-config must be able to pin back to v1 WITHOUT a
// plugin release.
ok( SNT_CW_API_DEFAULT_BASE === 'https://api.cloudways.com/api/v2', 'ships v2 as the default base' );
ok( false === strpos( SNT_CW_API_DEFAULT_BASE, '/api/v1' ), 'the deprecated v1 base is not the default' );
ok( sn_cloudways_api_base() === SNT_CW_API_DEFAULT_BASE, 'with no override, resolves to the shipped default' );
define( 'SN_CLOUDWAYS_API_BASE', 'https://api.cloudways.com/api/v1/' );
ok( sn_cloudways_api_base() === 'https://api.cloudways.com/api/v1', 'a wp-config override wins, and its trailing slash is trimmed' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
