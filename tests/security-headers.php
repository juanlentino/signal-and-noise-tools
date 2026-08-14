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
 *   - registration priority (v8.1.6): the guard must register on
 *     template_redirect at priority 9 so it runs BEFORE core's
 *     redirect_canonical (priority 10), which otherwise 301s /?author=N
 *     with the nicename leaked in the Location header first.
 *
 * Run: php tests/security-headers.php
 *
 * @since plugin v8.1.4 (suite), v8.1.5 (exemption removed), v8.1.6 (priority)
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// Recording stub: the module registers its hooks at require time, so the
// registrations land in this global for the priority assert (Test 6).
$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook = '', $callback = null, $priority = 10 ) {
		$GLOBALS['__test_actions'][] = array( $hook, $callback, $priority );
	}
}
$GLOBALS['__test_filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( array_key_exists( $hook, $GLOBALS['__test_filters'] ) ) {
			return $GLOBALS['__test_filters'][ $hook ];
		}
		return $value;
	}
}
// Record filter registrations too, so the xmlrpc_methods priority can be
// pinned. The module registers at require time; a no-op stub would lose the
// priority. Behaviour-neutral for every test above (none inspects filter regs).
$GLOBALS['__test_filter_regs'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook = '', $callback = null, $priority = 10 ) {
		$GLOBALS['__test_filter_regs'][] = array( $hook, $callback, $priority );
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

// ─── Test 6: guard registered at priority 9 (v8.1.6, audit LOW-1) ────
// Core adds redirect_canonical to template_redirect at priority 10 before
// any plugin loads, so at the same priority core wins by registration
// order and leaks the author nicename in a 301 Location before the guard
// runs. Priority 9 puts the guard first. Defense-in-depth on current
// config (CF edge 404s /?author=N), but the registration is the contract.
echo "\nTest 6: guard registered before core redirect_canonical\n";
$enum_priority = null;
foreach ( $GLOBALS['__test_actions'] as $reg ) {
	if ( 'template_redirect' === $reg[0] && 'sn_security_author_enum_guard' === $reg[1] ) {
		$enum_priority = $reg[2];
	}
}
sh_eq( 9, $enum_priority, 'sn_security_author_enum_guard on template_redirect at priority 9' );

// ─── Test 7: dangerous-method list names the two that matter ─────────
// system.multicall is the brute-force amplifier (one HTTP request tries
// hundreds of logins, bypassing wp-login.php limits and the login-guard
// worker); pingback.ping is the SSRF vector. Both must be in the list a
// future edit cannot quietly shrink without reddening this.
echo "\nTest 7: the dangerous-method list names multicall and pingback\n";
$dangerous = sn_security_xmlrpc_dangerous_methods();
sh_eq( true, in_array( 'system.multicall', $dangerous, true ), 'system.multicall is on the dangerous list' );
sh_eq( true, in_array( 'pingback.ping', $dangerous, true ), 'pingback.ping is on the dangerous list' );

// ─── Test 8: full-disable empties the whole map (unchanged behaviour) ─
echo "\nTest 8: sn_security_disable_xmlrpc true empties the method map\n";
$full = array( 'system.multicall' => 'cb', 'pingback.ping' => 'cb', 'jetpack.verifyRegistration' => 'cb', 'demo.sayHello' => 'cb' );
$GLOBALS['__test_filters'] = array( 'sn_security_disable_xmlrpc' => true );
sh_eq( array(), sn_security_xmlrpc_methods_filter( $full ), 'every method removed when the endpoint is fully disabled' );

// ─── Test 9: THE JETPACK CASE — endpoint on, amplifier still stripped ─
// The switch that motivated this: XML-RPC left ON (full-disable off) for a
// client that needs it, but system.multicall + pingback removed anyway. A
// legitimate method (jetpack.*, demo.*) survives; the dangerous ones do not.
echo "\nTest 9: full-disable OFF but strip ON removes ONLY the dangerous methods\n";
$GLOBALS['__test_filters'] = array( 'sn_security_disable_xmlrpc' => false, 'sn_security_strip_xmlrpc_dangerous' => true );
$stripped = sn_security_xmlrpc_methods_filter( $full );
sh_eq( false, array_key_exists( 'system.multicall', $stripped ), 'THE AMPLIFIER IS GONE: system.multicall removed even with the endpoint on' );
sh_eq( false, array_key_exists( 'pingback.ping', $stripped ), 'pingback.ping removed even with the endpoint on' );
sh_eq( true, array_key_exists( 'jetpack.verifyRegistration', $stripped ), 'THE DISCRIMINATOR: a legitimate method SURVIVES, so the strip is scoped not total' );
sh_eq( true, array_key_exists( 'demo.sayHello', $stripped ), 'and another legitimate method survives too' );

// ─── Test 10: both switches off leaves the map untouched ─────────────
// Without this, "return $methods unchanged" and "strip runs" are
// indistinguishable when the map happens to hold no dangerous methods.
echo "\nTest 10: full-disable OFF and strip OFF leaves the map untouched\n";
$GLOBALS['__test_filters'] = array( 'sn_security_disable_xmlrpc' => false, 'sn_security_strip_xmlrpc_dangerous' => false );
sh_eq( $full, sn_security_xmlrpc_methods_filter( $full ), 'both hardenings off means the full map passes through, multicall included' );

// ─── Test 11: strip is DEFAULT-ON — it survives disabling the full block ─
// The whole point: turning off sn_security_disable_xmlrpc (as one must for a
// client that needs the endpoint) must NOT also turn off the amplifier strip.
// Only the full-disable switch is set here; the strip default (true) must hold.
echo "\nTest 11: the strip defaults ON, independently of the full-disable switch\n";
$GLOBALS['__test_filters'] = array( 'sn_security_disable_xmlrpc' => false );
$default_strip = sn_security_xmlrpc_methods_filter( $full );
sh_eq( false, array_key_exists( 'system.multicall', $default_strip ), 'with only full-disable turned off, multicall is STILL stripped by default' );

// ─── Test 12: non-array input degrades to empty, never a warning ─────
echo "\nTest 12: a non-array method map degrades safely\n";
$GLOBALS['__test_filters'] = array( 'sn_security_disable_xmlrpc' => false, 'sn_security_strip_xmlrpc_dangerous' => true );
sh_eq( array(), sn_security_xmlrpc_methods_filter( null ), 'null method map returns an empty array rather than erroring' );

// ─── Test 13: the filter is registered at priority 99 ────────────────
// Late, so it runs after anything that populates the map (Jetpack adds its
// methods on the same filter). Registering early would strip nothing because
// the dangerous keys would not be present yet.
echo "\nTest 13: xmlrpc_methods filter registered late (priority 99)\n";
$xmlrpc_priority = null;
foreach ( $GLOBALS['__test_filter_regs'] as $reg ) {
	if ( 'xmlrpc_methods' === $reg[0] && 'sn_security_xmlrpc_methods_filter' === $reg[1] ) {
		$xmlrpc_priority = $reg[2];
	}
}
sh_eq( 99, $xmlrpc_priority, 'sn_security_xmlrpc_methods_filter on xmlrpc_methods at priority 99' );

$GLOBALS['__test_filters'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
