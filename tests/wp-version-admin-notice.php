<?php
/**
 * Verifies the v4.6.0 WP-version admin notice renders correctly under
 * the dispatch matrix: WP version < 7.0, dismissed state, capability gate.
 *
 * @since plugin v4.6.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// Stub WP functions for the unit test.
$GLOBALS['__test_caps'] = array( 'manage_options' => true );
$GLOBALS['__test_user_id'] = 1;
$GLOBALS['__test_user_meta'] = array();

function current_user_can( $cap ) { return ! empty( $GLOBALS['__test_caps'][ $cap ] ); }
function get_current_user_id() { return $GLOBALS['__test_user_id']; }
function get_user_meta( $uid, $key, $single = false ) {
	return $GLOBALS['__test_user_meta'][ $uid ][ $key ] ?? '';
}
function update_user_meta( $uid, $key, $val ) {
	$GLOBALS['__test_user_meta'][ $uid ][ $key ] = $val;
	return true;
}
function add_action( ...$args ) { /* no-op for these tests */ }
function wp_nonce_url( $url ) { return $url . '&_wpnonce=stub'; }
function add_query_arg( $key, $val ) { return '?' . $key . '=' . $val; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { return $u; }
// Note: version_compare() is a PHP built-in — the SUT calls it directly and
// it resolves to the native implementation, so no stub is needed (and one
// would fatal with "Cannot redeclare").

require_once dirname( __DIR__ ) . '/inc/admin-notice-wp-version.php';

$pass = 0; $fail = 0;
echo "WP version admin notice — v4.6.0\n\n";

// Test 1: WP < 7.0, no dismissal, manage_options — notice renders.
$GLOBALS['SNT_WP_VERSION_OVERRIDE'] = '6.4.2';
ob_start();
snt_render_wp_version_notice();
$out = ob_get_clean();
if ( str_contains( $out, 'v5.0.0 will require WordPress 7.0' ) && str_contains( $out, '6.4.2' ) ) {
	echo "  ✓ Test 1: notice renders for WP < 7.0\n"; $pass++;
} else {
	echo "  ✗ Test 1: notice did NOT render. Got: " . substr( $out, 0, 120 ) . "\n"; $fail++;
}

// Test 2: WP >= 7.0 → notice suppressed (version gate).
$GLOBALS['SNT_WP_VERSION_OVERRIDE'] = '7.0';
ob_start();
snt_render_wp_version_notice();
$out = ob_get_clean();
if ( '' === $out ) {
	echo "  ✓ Test 2: WP >= 7.0 suppresses notice\n"; $pass++;
} else {
	echo "  ✗ Test 2: WP 7.0 rendered notice anyway: " . substr( $out, 0, 120 ) . "\n"; $fail++;
}

// Test 3: WP < 7.0 + user dismissed → notice suppressed.
$GLOBALS['SNT_WP_VERSION_OVERRIDE'] = '6.4.2'; // back to <7.0
$GLOBALS['__test_user_meta'][1]['snt_dismissed_wp_version_notice_v460'] = '1';
ob_start();
snt_render_wp_version_notice();
$out = ob_get_clean();
if ( '' === $out ) {
	echo "  ✓ Test 3: dismissal sentinel suppresses notice\n"; $pass++;
} else {
	echo "  ✗ Test 3: dismissed but rendered: " . substr( $out, 0, 120 ) . "\n"; $fail++;
}

// Test 4: WP < 7.0, un-dismissed, but non-admin user → notice suppressed.
$GLOBALS['__test_user_meta'][1]['snt_dismissed_wp_version_notice_v460'] = '';
$GLOBALS['__test_caps'] = array(); // strip manage_options
ob_start();
snt_render_wp_version_notice();
$out = ob_get_clean();
if ( '' === $out ) {
	echo "  ✓ Test 4: non-admin user does not see notice\n"; $pass++;
} else {
	echo "  ✗ Test 4: non-admin saw notice: " . substr( $out, 0, 120 ) . "\n"; $fail++;
}

echo "\n$pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
