<?php
/**
 * Tests for inc/redirects-handler.php — the two template_redirect hooks:
 * sn_redirect_maybe() (owner-authored 30x) and sn_redirect_capture_404().
 *
 * Coverage the v9.1.0 audit flagged as the highest-value gap: the 301/302 status
 * coercion, the is_admin()/is_404()/GET guards, and the allowed_redirect_hosts
 * whitelisting for owner-authored external targets. Pure unit fixture — the real
 * exit after wp_safe_redirect() is unwound by having the stub throw, so the
 * handler is observable without terminating the suite. Run: php tests/redirects-handler.php
 * @since plugin v9.1.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

// ── request + collaborator seams ────────────────────────────────────
$GLOBALS['__is_admin']  = false;
$GLOBALS['__is_404']    = false;
$GLOBALS['__target']    = array();   // sn_redirect_target() return
$GLOBALS['__redirect']  = null;      // [location, status] captured from wp_safe_redirect
$GLOBALS['__hosts']     = array();   // hosts added via the allowed_redirect_hosts filter
$GLOBALS['__404']       = null;      // [uri, referer] captured from sn_404_log_record

function is_admin() { return (bool) $GLOBALS['__is_admin']; }
function is_404() { return (bool) $GLOBALS['__is_404']; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_get_referer() { return $GLOBALS['__referer'] ?? false; }
function add_action() {} // no-op: suppress hook registration on require
function add_filter( $hook, $cb ) {
	// Apply the allowed_redirect_hosts closure to an empty list so the test can
	// observe which host the handler whitelisted for this request.
	if ( 'allowed_redirect_hosts' === $hook ) { $GLOBALS['__hosts'] = call_user_func( $cb, array() ); }
}
// Collaborators from sibling modules — stubbed so this fixture stays a unit test.
function sn_redirect_target( $uri ) { return $GLOBALS['__target']; }
function sn_404_log_record( $uri, $referer ) { $GLOBALS['__404'] = array( $uri, $referer ); }
// wp_safe_redirect records then throws so the handler's `exit` is never reached.
class SN_Redirected extends Exception {}
function wp_safe_redirect( $location, $status = 302 ) {
	$GLOBALS['__redirect'] = array( $location, $status );
	throw new SN_Redirected();
}

require __DIR__ . '/../inc/redirects-handler.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// Helper: run sn_redirect_maybe() catching the redirect-unwind exception.
function run_redirect() {
	$GLOBALS['__redirect'] = null; $GLOBALS['__hosts'] = array();
	try { sn_redirect_maybe(); return true; } catch ( SN_Redirected $e ) { return true; }
}

echo "Redirects handler — sn_redirect_maybe() + sn_redirect_capture_404()\n\n";

echo "Group: sn_redirect_maybe — guards\n";
$_SERVER['REQUEST_URI'] = '/old-path/';
$GLOBALS['__is_admin']  = true;
$GLOBALS['__target']    = array( 'to' => '/new/', 'status' => 301 );
run_redirect();
ok( null === $GLOBALS['__redirect'], 'admin request is skipped (no redirect even with a matching target)' );
$GLOBALS['__is_admin'] = false;

$GLOBALS['__target'] = array(); // no match
run_redirect();
ok( null === $GLOBALS['__redirect'], 'no matching redirect → returns, no 30x' );

echo "\nGroup: sn_redirect_maybe — status coercion + internal target\n";
$GLOBALS['__target'] = array( 'to' => '/new-home/', 'status' => '301' ); // string status from the store
run_redirect();
ok( is_array( $GLOBALS['__redirect'] ) && '/new-home/' === $GLOBALS['__redirect'][0], 'internal target: redirects to the mapped path' );
ok( 301 === $GLOBALS['__redirect'][1], 'status is coerced to int 301 (string "301" → 301)' );

$GLOBALS['__target'] = array( 'to' => '/temp/', 'status' => '302' );
run_redirect();
ok( 302 === $GLOBALS['__redirect'][1], 'a 302 target is honored (temporary redirect)' );

echo "\nGroup: sn_redirect_maybe — external target whitelists its host\n";
$GLOBALS['__target'] = array( 'to' => 'https://panaceastud.io/booking', 'status' => 301 );
run_redirect();
ok( is_array( $GLOBALS['__redirect'] ) && 'https://panaceastud.io/booking' === $GLOBALS['__redirect'][0], 'external target: passed to wp_safe_redirect' );
ok( in_array( 'panaceastud.io', $GLOBALS['__hosts'], true ), 'external host is whitelisted via allowed_redirect_hosts (owner-authored, not open redirect)' );

$GLOBALS['__target'] = array( 'to' => '/internal/', 'status' => 301 );
run_redirect();
ok( ! in_array( 'panaceastud.io', $GLOBALS['__hosts'], true ) && array() === $GLOBALS['__hosts'], 'internal target adds NO host to the whitelist' );

echo "\nGroup: sn_redirect_capture_404 — guards\n";
$GLOBALS['__is_404'] = false;
$GLOBALS['__404']    = null;
$_SERVER['REQUEST_METHOD'] = 'GET';
sn_redirect_capture_404();
ok( null === $GLOBALS['__404'], 'a non-404 request is not logged' );

$GLOBALS['__is_404'] = true;
$_SERVER['REQUEST_METHOD'] = 'POST';
$GLOBALS['__404'] = null;
sn_redirect_capture_404();
ok( null === $GLOBALS['__404'], 'a POST 404 is not logged (only GET broken links)' );

$GLOBALS['__is_404'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/missing/';
$GLOBALS['__referer'] = 'https://example.com/from/';
$GLOBALS['__404'] = null;
sn_redirect_capture_404();
ok( is_array( $GLOBALS['__404'] ) && '/missing/' === $GLOBALS['__404'][0] && 'https://example.com/from/' === $GLOBALS['__404'][1], 'a GET 404 records the uri + referer' );

$GLOBALS['__referer'] = false; // wp_get_referer() can return false
$GLOBALS['__404'] = null;
sn_redirect_capture_404();
ok( is_array( $GLOBALS['__404'] ) && '' === $GLOBALS['__404'][1], 'a false referer is normalized to an empty string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
