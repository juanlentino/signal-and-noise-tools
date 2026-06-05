<?php
/**
 * Tests for the /sn-login serve-form noindex header (X-Robots-Tag).
 *
 * The serve-form branch of sn_login_handle_request() cannot be tested
 * headlessly — it does `require_once ABSPATH . 'wp-login.php'; die`. And
 * header() is a PHP builtin that cannot be stubbed, while headers_sent()
 * is false under CLI so emitted headers aren't capturable without xdebug.
 *
 * So the testable seam is the PURE decision function
 * sn_login_serve_form_headers(), which returns the header list the
 * handler emits (behind a headers_sent() guard) before loading
 * wp-login.php. This locks the X-Robots-Tag contract. The handler's
 * require/die branches remain verified by reading + the live curl UAT
 * (same limitation as tests/login-intercept.php, which tests the
 * plugins_loaded classifier, not the wp_loaded responder).
 *
 * @since 4.5.7
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
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

// === Test 1: function exists ===
assertEq( true, function_exists( 'sn_login_serve_form_headers' ), 'sn_login_serve_form_headers() is defined' );

// === Test 2: returns an array ===
$headers = sn_login_serve_form_headers();
assertEq( true, is_array( $headers ), 'returns an array of header strings' );

// === Test 3: emits the noindex X-Robots-Tag ===
assertEq( true, in_array( 'X-Robots-Tag: noindex, nofollow', $headers, true ), 'includes X-Robots-Tag: noindex, nofollow' );

// === Test 4: exactly one header (no accidental extras) ===
assertEq( 1, count( $headers ), 'returns exactly one header (the noindex tag)' );

// === Test 5: every entry is a non-empty string (well-formed for header()) ===
$all_strings = true;
foreach ( $headers as $h ) {
	if ( ! is_string( $h ) || '' === $h ) {
		$all_strings = false;
	}
}
assertEq( true, $all_strings, 'all entries are non-empty strings' );

// === Test 6: handler still registered on wp_loaded (regression guard) ===
$wp_loaded_registered = false;
foreach ( $GLOBALS['__actions']['wp_loaded'] ?? array() as $action ) {
	if ( $action['cb'] === 'sn_login_handle_request' ) {
		$wp_loaded_registered = true;
	}
}
assertEq( true, $wp_loaded_registered, 'sn_login_handle_request still registered on wp_loaded' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
