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

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
