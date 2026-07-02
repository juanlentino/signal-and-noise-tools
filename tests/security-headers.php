<?php
/**
 * Standalone fixture tests for the author-enumeration guard in
 * inc/security-headers.php.
 *
 * The guard's decision logic lives in sn_security_author_enum_should_redirect()
 * (split from the redirect+exit action so tests never cross exit). Covers:
 *   - anonymous /?author=N request → redirect (the enumeration block)
 *   - logged-in user → no redirect
 *   - sn_security_block_author_enum filter false → no redirect
 *   - no author param → no redirect
 *   - REMOVAL GUARDS (v8.1.5): the v8.1.4 ActivityPub exemption
 *     (sn_security_is_activitypub_request + the sn_security_author_enum_exempt
 *     filter) was removed when the owner declined the ActivityPub adoption
 *     entirely (2026-07-02, never re-propose). These guards keep it removed.
 *
 * Run: php tests/security-headers.php
 *
 * @since plugin v8.1.4 (suite), v8.1.5 (exemption removed)
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

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
$GLOBALS['__test_logged_in'] = false;
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
}

require_once __DIR__ . '/../inc/security-headers.php';

$pass = 0;
$fail = 0;
function sh_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}

// ─── Test 1: anonymous ?author=N → redirect ──────────────────────────
echo "\nTest 1: anonymous /?author=N redirects\n";
$_GET = array( 'author' => '616000' );
$GLOBALS['__test_filters']   = array();
$GLOBALS['__test_logged_in'] = false;
sh_eq( true, sn_security_author_enum_should_redirect(), 'enumeration probe is blocked' );

// ─── Test 2: logged-in keeps standard behaviour ──────────────────────
echo "\nTest 2: logged-in user is not redirected\n";
$GLOBALS['__test_logged_in'] = true;
sh_eq( false, sn_security_author_enum_should_redirect(), 'logged-in requests pass through' );
$GLOBALS['__test_logged_in'] = false;

// ─── Test 3: kill-switch filter ──────────────────────────────────────
echo "\nTest 3: sn_security_block_author_enum false disables the guard\n";
$GLOBALS['__test_filters'] = array( 'sn_security_block_author_enum' => false );
sh_eq( false, sn_security_author_enum_should_redirect(), 'filter off means no redirect' );
$GLOBALS['__test_filters'] = array();

// ─── Test 4: no author param ─────────────────────────────────────────
echo "\nTest 4: request without ?author is untouched\n";
$_GET = array();
sh_eq( false, sn_security_author_enum_should_redirect(), 'no author param means no redirect' );

// ─── Test 5: removal guards — the ActivityPub exemption stays gone ───
echo "\nTest 5: v8.1.4 ActivityPub exemption removed (v8.1.5) and stays removed\n";
sh_eq( false, function_exists( 'sn_security_is_activitypub_request' ), 'sn_security_is_activitypub_request() does not exist' );
$module_src = file_get_contents( __DIR__ . '/../inc/security-headers.php' );
sh_eq( false, strpos( $module_src, 'sn_security_author_enum_exempt' ) !== false, 'sn_security_author_enum_exempt filter absent from the module' );
sh_eq( false, stripos( $module_src, 'activitypub' ) !== false, 'no ActivityPub references remain in the module' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
