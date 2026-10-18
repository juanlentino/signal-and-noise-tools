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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
