<?php
/**
 * Standalone test: the masked login is not handed out by core's admin-location
 * redirect (2026-10-17). WordPress core's wp_redirect_admin_locations() sends
 * /login to wp_login_url(), which the slug filter rewrites to the secret door,
 * so `GET /login` answered `302 /sn-login` live: anyone who typed /login was
 * given the hidden URL. While the mask is active the redirect is unhooked, so
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
	foreach ( array( '/sn-login', '/sn-login?redirect_to=/wp-signup.php', '/notes/wp-signup-php/', '/', '/wp-admin/admin-ajax.php' ) as $u ) {
		ok( ! sn_login_request_is_core_login_file( $u ), "passes: $u" );
	}
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
