<?php
/**
 * Tests for the login intercept handler (v4.2.1).
 *
 * Verifies that sn_login_intercept_request() correctly classifies
 * incoming requests into one of three states:
 *   - 'serve_form'      — REQUEST_URI matches the custom slug → include wp-login.php
 *   - 'block_wp_login'  — REQUEST_URI hits /wp-login.php directly → 404
 *   - (neither)         — pass through (allowlisted endpoints + unrelated URLs)
 *
 * The intercept replaces the v1.5.0–v4.2.0 add_rewrite_rule routing
 * approach. It runs at plugins_loaded priority 2 — before WP's rewrite
 * engine — so it works regardless of rewrite_rules option state,
 * .htaccess directives, or CDN edge cache.
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/tmp/wp-plugins' );
}

// In-memory option store with the test slug pre-set.
$GLOBALS['__options'] = array(
	'sn_settings' => array( 'login' => array( 'slug' => 'backend' ) ),
);
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function get_bloginfo( $what ) {
	return $what === 'name' ? 'TestSite' : '';
}

function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function untrailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' );
}
function is_admin() {
	return false;
}
function is_plugin_active( $slug ) {
	return false;
}

// Action/filter capture (unused by the intercept itself but required by
// the file-level add_action/add_filter calls in login-hide.php).
$GLOBALS['__actions'] = array();
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = array( 'cb' => $callback, 'priority' => $priority );
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}

require __DIR__ . '/../inc/settings.php';
require __DIR__ . '/../inc/login-hide.php';

$pass = 0;
$fail = 0;

function assertEq( $expected, $actual, $label ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "PASS: $label\n";
	} else {
		$fail++;
		echo "FAIL: $label — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n";
	}
}

function resetIntercept() {
	unset( $GLOBALS['sn_login_serve_form'], $GLOBALS['sn_login_block_wp_login'] );
	global $pagenow;
	$pagenow = 'index.php';
}

// === Test 1: custom slug match → serve_form + $pagenow set ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/backend';
sn_login_intercept_request();
assertEq( true, ! empty( $GLOBALS['sn_login_serve_form'] ), '/backend sets sn_login_serve_form' );
global $pagenow;
assertEq( 'wp-login.php', $pagenow, '/backend sets $pagenow to wp-login.php' );
assertEq( true, empty( $GLOBALS['sn_login_block_wp_login'] ), '/backend does NOT set block_wp_login' );

// === Test 2: custom slug with trailing slash → same behavior ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/backend/';
sn_login_intercept_request();
assertEq( true, ! empty( $GLOBALS['sn_login_serve_form'] ), '/backend/ (trailing slash) sets sn_login_serve_form' );

// === Test 3: custom slug with query string → same behavior ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/backend?action=login';
sn_login_intercept_request();
assertEq( true, ! empty( $GLOBALS['sn_login_serve_form'] ), '/backend?action=login sets sn_login_serve_form (POST path)' );

// === Test 4: wp-login.php direct → block_wp_login set ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/wp-login.php';
sn_login_intercept_request();
assertEq( true, ! empty( $GLOBALS['sn_login_block_wp_login'] ), '/wp-login.php sets sn_login_block_wp_login' );
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ), '/wp-login.php does NOT set serve_form' );

// === Test 5: admin-ajax.php → no flags (allowlist precedence) ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=heartbeat';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ), 'admin-ajax does NOT set serve_form' );
assertEq( true, empty( $GLOBALS['sn_login_block_wp_login'] ), 'admin-ajax does NOT set block_wp_login' );

// === Test 6: async-upload.php → no flags ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/wp-admin/async-upload.php';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ), 'async-upload does NOT set serve_form' );

// === Test 7: wp-cron.php → no flags ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/wp-cron.php?doing_wp_cron=1';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_block_wp_login'] ), 'wp-cron does NOT set block_wp_login' );

// === Test 8: /wp-json/ → no flags ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ), 'REST does NOT set serve_form' );

// === Test 9: /feed → no flags ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/feed';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ), 'feed does NOT set serve_form' );

// === Test 10: unrelated frontend URL → no flags ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/notes/some-post';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ), 'unrelated frontend URL does NOT set serve_form' );
assertEq( true, empty( $GLOBALS['sn_login_block_wp_login'] ), 'unrelated frontend URL does NOT set block_wp_login' );

// === Test 11: substring match prevention ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/backend-something';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ), '/backend-something (similar but not equal) does NOT match slug' );

// === Test 12: nested path /backend/foo should NOT match ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/backend/foo';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ), '/backend/foo (nested under slug) does NOT match' );

// === Test 13: plugins_loaded action registered at priority 2 ===
$intercept_priority = null;
foreach ( $GLOBALS['__actions']['plugins_loaded'] ?? array() as $action ) {
	if ( $action['cb'] === 'sn_login_intercept_request' ) {
		$intercept_priority = $action['priority'];
	}
}
assertEq( 2, $intercept_priority, 'plugins_loaded handler registered at priority 2' );

// === Test 14: wp_loaded action registered ===
$wp_loaded_registered = false;
foreach ( $GLOBALS['__actions']['wp_loaded'] ?? array() as $action ) {
	if ( $action['cb'] === 'sn_login_handle_request' ) {
		$wp_loaded_registered = true;
	}
}
assertEq( true, $wp_loaded_registered, 'wp_loaded handler registered' );

// === Test 15: allowlist matches PATH only — query-string bypass closed (v4.14.2) ===
// `/wp-admin/?x=/feed` must NOT be allowlisted by the `/feed` needle appearing
// in the query string (that bypassed the unauth-/wp-admin decoy-404).
assertEq( false, sn_login_request_is_allowlisted( '/wp-admin/?x=/feed' ), '/wp-admin/?x=/feed is NOT allowlisted (query-string /feed bypass closed)' );
assertEq( false, sn_login_request_is_allowlisted( '/wp-admin/?redirect=/wp-json/' ), '/wp-admin/?...=/wp-json/ in query is NOT allowlisted' );
assertEq( true,  sn_login_request_is_allowlisted( '/wp-admin/admin-ajax.php?action=heartbeat' ), 'real admin-ajax.php PATH is still allowlisted' );
assertEq( true,  sn_login_request_is_allowlisted( '/wp-json/wp/v2/posts' ), 'real /wp-json/ PATH is still allowlisted' );
assertEq( true,  sn_login_request_is_allowlisted( '/blog/feed/' ), 'real /feed PATH is still allowlisted' );
assertEq( false, sn_login_request_is_allowlisted( '/notes/some-post' ), 'unrelated path is NOT allowlisted' );

// === Test 16: path-substring smuggle siblings closed (v4.14.4) ===
// v4.14.2 narrowed the match to the PATH but kept a substring test, so a needle
// appearing as a NON-terminal path segment under /wp-admin still allowlisted the
// request and skipped the unauth-/wp-admin decoy-404. Anchor each needle to its
// real path shape: nothing under /wp-admin except admin-ajax/async-upload is a
// real public endpoint.
assertEq( false, sn_login_request_is_allowlisted( '/wp-admin/feed' ),                   '/wp-admin/feed is NOT allowlisted (path-substring /feed smuggle closed)' );
assertEq( false, sn_login_request_is_allowlisted( '/wp-admin/feed/anything' ),          '/wp-admin/feed/anything is NOT allowlisted' );
assertEq( false, sn_login_request_is_allowlisted( '/wp-admin/network/admin-ajax.php' ), '/wp-admin/<fake>/admin-ajax.php is NOT allowlisted (non-terminal admin-ajax smuggle closed)' );
assertEq( false, sn_login_request_is_allowlisted( '/wp-admin/x/wp-json/y' ),            '/wp-admin/.../wp-json/... is NOT allowlisted' );
// Real public endpoints still allowlisted — incl. subdirectory installs and the
// // network-path form of a genuine admin-ajax request.
assertEq( true,  sn_login_request_is_allowlisted( '/wp-admin/admin-ajax.php' ),         'real /wp-admin/admin-ajax.php still allowlisted' );
assertEq( true,  sn_login_request_is_allowlisted( '/blog/wp-admin/admin-ajax.php' ),    'subdirectory-install admin-ajax.php still allowlisted' );
assertEq( true,  sn_login_request_is_allowlisted( '//wp-admin/admin-ajax.php' ),        '//-prefixed real admin-ajax.php still allowlisted (leading slashes normalized)' );
assertEq( true,  sn_login_request_is_allowlisted( '/wp-cron.php?doing_wp_cron=1' ),     '/wp-cron.php still allowlisted' );
assertEq( true,  sn_login_request_is_allowlisted( '/category/news/feed/' ),             'real trailing /feed/ still allowlisted' );

// === Test 17: unauth-/wp-admin decoy decision is path-anchored (v4.14.4) ===
// Branch-3 of the wp_loaded handler previously decided "is this an unauth
// /wp-admin request?" with strpos($request_uri,'/wp-admin')===0 on the RAW URI,
// so a `//wp-admin/...` network-path form (the webserver still serves wp-admin
// after merging slashes) had '/wp-admin' at offset 1 and dodged the decoy; and
// `/wp-administrator` falsely matched the prefix. sn_login_request_targets_wp_admin()
// anchors on the //-normalized PATH with a segment boundary.
assertEq( true,  sn_login_request_targets_wp_admin( '/wp-admin' ),          '/wp-admin targets wp-admin' );
assertEq( true,  sn_login_request_targets_wp_admin( '/wp-admin/' ),         '/wp-admin/ targets wp-admin' );
assertEq( true,  sn_login_request_targets_wp_admin( '/wp-admin/edit.php' ), '/wp-admin/edit.php targets wp-admin' );
assertEq( true,  sn_login_request_targets_wp_admin( '/wp-admin/?x=1' ),     '/wp-admin/?x=1 targets wp-admin (query ignored)' );
assertEq( true,  sn_login_request_targets_wp_admin( '//wp-admin/' ),        '//wp-admin/ targets wp-admin (network-path decoy-evasion closed)' );
assertEq( true,  sn_login_request_targets_wp_admin( '///wp-admin/x' ),      '///wp-admin/x targets wp-admin (multi-slash normalized)' );
assertEq( false, sn_login_request_targets_wp_admin( '/notes/x' ),           '/notes/x does NOT target wp-admin' );
assertEq( false, sn_login_request_targets_wp_admin( '/wp-administrator' ),  '/wp-administrator does NOT target wp-admin (segment-anchored, not prefix-substring)' );
assertEq( false, sn_login_request_targets_wp_admin( '//notes/x' ),          '//notes/x does NOT target wp-admin' );

// === Test 18: block branch is PATH-anchored — query-string 'wp-login.php' must NOT 404 the slug (v6.19.3) ===
// The block branch previously did strpos($request_uri,'wp-login.php') on the RAW
// REQUEST_URI (path+query), so a custom-slug request whose query carried a
// redirect_to=...wp-login.php... value tripped block_wp_login and 404'd BEFORE the
// serve_form path match. The WordPress Two Factor plugin's backup-method links
// (Two_Factor_Core::login_url with redirect_to) and core's own round-tripped
// redirect_to defaults ('wp-login.php?checkemail=registered' etc.) carry exactly that
// substring. Anchor on the parsed PATH ending in /wp-login.php, mirroring the v4.14.2
// allowlist PATH-anchoring; shares sn_login_request_path().
resetIntercept();
$_SERVER['REQUEST_URI'] = '/backend?action=validate_2fa&redirect_to=wp-login.php%3Fcheckemail%3Dregistered';
sn_login_intercept_request();
assertEq( true, ! empty( $GLOBALS['sn_login_serve_form'] ),     'slug + query-string wp-login.php → serve_form (not the 404 block branch)' );
assertEq( true, empty( $GLOBALS['sn_login_block_wp_login'] ),   'slug + query-string wp-login.php → does NOT set block_wp_login' );

// A frontend URL with 'wp-login.php' as a NON-terminal substring must also not block.
resetIntercept();
$_SERVER['REQUEST_URI'] = '/notes/why-i-renamed-wp-login.php-and-you-should-too';
sn_login_intercept_request();
assertEq( true, empty( $GLOBALS['sn_login_block_wp_login'] ),   'frontend path containing wp-login.php (non-terminal) does NOT block' );

// === Test 19: genuine direct /wp-login.php access STILL blocks (protection preserved) ===
resetIntercept();
$_SERVER['REQUEST_URI'] = '/wp-login.php?action=lostpassword&error=expiredkey';
sn_login_intercept_request();
assertEq( true, ! empty( $GLOBALS['sn_login_block_wp_login'] ), '/wp-login.php?... (direct, with query) still blocks' );
assertEq( true, empty( $GLOBALS['sn_login_serve_form'] ),       '/wp-login.php?... does NOT serve_form' );

// Subdirectory-install direct access still blocks (path ENDS in /wp-login.php).
resetIntercept();
$_SERVER['REQUEST_URI'] = '/blog/wp-login.php';
sn_login_intercept_request();
assertEq( true, ! empty( $GLOBALS['sn_login_block_wp_login'] ), '/blog/wp-login.php (subdir install) still blocks' );

// // network-path form of a genuine /wp-login.php still blocks (normalized).
resetIntercept();
$_SERVER['REQUEST_URI'] = '//wp-login.php';
sn_login_intercept_request();
assertEq( true, ! empty( $GLOBALS['sn_login_block_wp_login'] ), '//wp-login.php (network-path form) still blocks (leading slashes normalized)' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
