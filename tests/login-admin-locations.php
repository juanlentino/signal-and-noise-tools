<?php
/**
 * Standalone test: the masked login is not handed out by core's admin-location
 * redirect (2026-10-17). WordPress core's wp_redirect_admin_locations() sends
 * /login to wp_login_url(), which the slug filter rewrites to the secret door,
 * so `GET /login` answered `302 /sn-login` live: anyone who typed /login was
 * given the hidden URL. For a visitor who is not signed in the redirect is unhooked, so
 * /login, /admin and /dashboard fall through to a 404 like /wp-login.php.
 *
 * Run: php tests/login-admin-locations.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/login-hide.php' );
$rm  = "remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );";
$at  = strpos( $src, $rm );
ok( false !== $at, "core's admin-location redirect is unhooked (core registers it at priority 1000, so removal must name 1000)" );
ok( false !== $at && $at > strpos( $src, "SN_LOGIN_BYPASS" ) && $at > strpos( $src, '$wps_basename' ), 'only while the mask is active: the emergency bypass and the wps-hide-login stand-down return before it' );
ok( false !== strpos( (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $src ), $rm ), 'it is live code, not only a comment' );

// 2026-11-10: the removal is for strangers only. A signed-in user keeps core's
// /admin, /dashboard and /login shortcuts; a stranger's answer does not change.
$code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $src );
ok( 1 === substr_count( $code, $rm ), 'the removal appears once in live code' );
ok( 1 === preg_match( '/function sn_login_scope_admin_locations\(\) \{.*?\n}\n/s', $src, $fn ) && false !== strpos( $fn[0], $rm ), 'and it lives inside sn_login_scope_admin_locations(), never at file scope' );
ok( 1 === preg_match( "/add_action\( 'template_redirect', 'sn_login_scope_admin_locations', (\d+) \);/", $code, $pm ) && (int) $pm[1] < 1000, "it runs on template_redirect before core's priority 1000" );
if ( ! empty( $fn[0] ) ) {
	$GLOBALS['__removed'] = array();
	$GLOBALS['__in']      = false;
	function remove_action( ...$a ) { $GLOBALS['__removed'][] = $a; return true; }
	eval( $fn[0] ); // phpcs:ignore -- test-only: lift the function out of the module.
	sn_login_scope_admin_locations();
	ok( array( array( 'template_redirect', 'wp_redirect_admin_locations', 1000 ) ) === $GLOBALS['__removed'], 'fails closed: with no is_user_logged_in() the redirect is removed' );
	function is_user_logged_in() { return $GLOBALS['__in']; }
	$GLOBALS['__removed'] = array();
	sn_login_scope_admin_locations();
	ok( array( array( 'template_redirect', 'wp_redirect_admin_locations', 1000 ) ) === $GLOBALS['__removed'], "a stranger: core's redirect is removed, so /admin, /login and /dashboard 404 as before" );
	$GLOBALS['__removed'] = array();
	$GLOBALS['__in']      = true;
	sn_login_scope_admin_locations();
	ok( array() === $GLOBALS['__removed'], "signed in: core's redirect stays, so /admin and /dashboard work as shortcuts (/login stays 404 in the redirect guard)" );
}

// The signup and activation scripts hand the door out too: on a single site
// wp-signup.php redirects to wp_registration_url() (the masked slug with
// ?action=register), and wp-activate.php to wp-login.php?action=register.
// Both 404 like wp-login.php (live 2026-10-17: /wp-signup.php -> 302 /sn-login?action=register).
ok( false !== strpos( $src, 'function sn_login_request_is_core_login_file(' ), 'one predicate names the core login-side scripts that must 404' );
if ( false !== strpos( $src, 'function sn_login_request_is_core_login_file(' ) ) {
	if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
	if ( ! function_exists( 'sn_login_request_path' ) ) {
		preg_match( '/function sn_login_request_path\(.*?\n}\n/s', $src, $m1 );
		eval( $m1[0] ); // phpcs:ignore -- test-only: lift the pure helpers out of the module.
	}
	preg_match( '/function sn_login_request_is_core_login_file\(.*?\n}\n/s', $src, $m2 );
	eval( $m2[0] ); // phpcs:ignore
	foreach ( array( '/wp-login.php', '/wp-signup.php', '/wp-activate.php', '/wp-signup.php?blog=1', '//wp-signup.php', '/WP-SIGNUP.PHP' ) as $u ) {
		ok( sn_login_request_is_core_login_file( $u ), "404s: $u" );
	}
	// Hunt 2026-10-25, live: these served the real login form (200) while /wp-login.php answered 404.
	foreach ( array( '/wp-login%2ephp', '/wp-login%2Ephp', '/%77p-login.php', '/%77%70%2d%6c%6f%67%69%6e%2e%70%68%70', '/wp-%73ignup.php', '/%2fwp-login.php', '/wp-json/%2e%2e/wp-login.php', '/wp-json/..%2fwp-login.php', '/%2e%2e/wp-login.php', '/notes/./../wp-login.php' ) as $u ) {
		ok( sn_login_request_is_core_login_file( $u ), "404s once decoded: $u" );
	}
	foreach ( array( '/sn-login?redirect_to=%2Fwp-login.php', '/wp-login%3Fx', '/wp-login.php%2500' ) as $u ) {
		ok( ! sn_login_request_is_core_login_file( $u ), "passes (an encoded query, ? or a double-encoded byte is not the file): $u" );
	}
	foreach ( array( '/sn-login', '/sn-login?redirect_to=/wp-signup.php', '/notes/wp-signup-php/', '/', '/wp-admin/admin-ajax.php' ) as $u ) {
		ok( ! sn_login_request_is_core_login_file( $u ), "passes: $u" );
	}
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
