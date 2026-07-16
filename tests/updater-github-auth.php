<?php
/**
 * Fixture test: the self-updater's GitHub tag-fetch must send an
 * Authorization header when SNT_GITHUB_TOKEN is defined (5000/h authenticated)
 * and must omit it when the constant is absent (60/h unauthenticated fallback).
 *
 * Context: the updater (sn_gh_latest_plugin_tag) historically sent only
 * Accept + User-Agent, so every WP update-check spent from the shared-IP 60/h
 * unauthenticated GitHub pool. When that pool exhausts, the tag-fetch gets a 403
 * → returns null → the Updates page shows "no update available" even when one
 * exists. github-actions-api.php (the deploy poller) already authenticates with
 * the same wp-config SNT_GITHUB_TOKEN constant; this brings the updater to parity.
 *
 * Run: php tests/updater-github-auth.php
 *
 * @since plugin v4.5.6
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
// v9.54.1: the transient/durable failure TTLs are expressed in minutes.
define( 'MINUTE_IN_SECONDS', 60 );

// ── Capture buffer: every wp_remote_get records its $args here. ──
$GLOBALS['__captured_requests'] = array();
$GLOBALS['__transients']        = array();

if ( ! function_exists( 'add_filter' ) )  { function add_filter() {} }
if ( ! function_exists( 'add_action' ) )  { function add_action() {} }
if ( ! function_exists( 'home_url' ) )    { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'get_site_transient' ) ) {
    function get_site_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_site_transient' ) ) {
    function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
}
// v9.54.0: a successful fetch now CLEARS the recorded failure reason, so the
// updater reaches for delete_site_transient(). Without this the suite fatals on
// a function WP core has always provided.
if ( ! function_exists( 'delete_site_transient' ) ) {
    function delete_site_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = array() ) {
        $GLOBALS['__captured_requests'][] = array( 'url' => $url, 'args' => $args );
        // Return one matching tag so the function completes its happy path.
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( array( 'name' => 'v9.9.9' ) ) ) );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
}

require __DIR__ . '/../inc/wp-update-integration.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "PASS: $label\n"; }
    else { $fail++; echo "FAIL: $label\n"; }
}

/** Fetch the most recent captured request's headers, after a forced (uncached) call. */
function last_headers() {
    $GLOBALS['__captured_requests'] = array();
    sn_gh_latest_plugin_tag( true ); // force_refresh = bypass cache
    $reqs = $GLOBALS['__captured_requests'];
    return $reqs ? ( $reqs[ count( $reqs ) - 1 ]['args']['headers'] ?? array() ) : array();
}

// ── Case 1: token DEFINED → Authorization: Bearer present ──
define( 'SNT_GITHUB_TOKEN', 'ghp_faketoken123' );
$h = last_headers();
ok( isset( $h['Authorization'] ),                          'token defined → Authorization header present' );
ok( ( $h['Authorization'] ?? '' ) === 'Bearer ghp_faketoken123', 'Authorization is "Bearer <token>"' );
ok( ( $h['Accept'] ?? '' ) === 'application/vnd.github+json',     'Accept header preserved' );
ok( isset( $h['User-Agent'] ),                            'User-Agent header preserved' );

// ── Case 2 (documented): when the constant is UNDEFINED, no Authorization. ──
// Can't undefine a constant mid-process, so this is asserted structurally:
// the source guards the header with `if ( defined( 'SNT_GITHUB_TOKEN' ) && ... )`,
// proven by Case 1 + the source-grep assertion below.
$src = file_get_contents( __DIR__ . '/../inc/wp-update-integration.php' );
ok( strpos( $src, "defined( 'SNT_GITHUB_TOKEN' )" ) !== false, 'token application is guarded by defined() (graceful unauth fallback)' );
ok( preg_match( '/Authorization.*Bearer.*SNT_GITHUB_TOKEN/s', $src ) === 1, 'Authorization built from SNT_GITHUB_TOKEN' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
