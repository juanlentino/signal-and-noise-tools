<?php
/**
 * Standalone test: no redirect hands a stranger the login door (2026-10-18).
 *
 * Live after the slug change: /wp-admin/customize.php (core's auth_redirect)
 * and the OpenStation launch URLs answered 302 to the new door, because every
 * redirect to wp-login.php is rewritten to the masked slug. One guard on
 * wp_redirect now refuses, with the theme's 404, any redirect to the door for
 * a visitor who is not logged in, unless the request is already at the door
 * (logout, lost password and the rest of wp-login.php's own flows).
 *
 * Run: php tests/login-redirect-guard.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/login-hide.php' );
ok( false !== strpos( $src, 'function sn_login_redirect_reveals_door(' ), 'one predicate decides whether a redirect would reveal the door' );
if ( false !== strpos( $src, 'function sn_login_redirect_reveals_door(' ) ) {
	if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
	if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); } }
	foreach ( array( 'sn_login_request_path', 'sn_login_redirect_reveals_door' ) as $fn ) {
		preg_match( '/function ' . $fn . '\(.*?\n}\n/s', $src, $m );
		eval( $m[0] ); // phpcs:ignore -- test-only: lift the pure helpers out of the module.
	}
	$door = 'https://site.test/door-k7?redirect_to=https%3A%2F%2Fsite.test%2Fwp-admin%2Fcustomize.php&reauth=1';
	ok( sn_login_redirect_reveals_door( $door, '/wp-admin/customize.php', 'door-k7', false ), "a stranger at customize.php is not sent to the door (core's auth_redirect)" );
	ok( sn_login_redirect_reveals_door( 'https://site.test/door-k7?redirect_to=x', '/wp-admin/admin.php?page=openstation', 'door-k7', false ), 'nor from the OpenStation launch URL' );
	ok( sn_login_redirect_reveals_door( 'https://site.test/door-k7/', '/openstation', 'door-k7', false ), 'nor from /openstation, trailing slash or not' );
	ok( sn_login_redirect_reveals_door( '/door-k7', '/anything', 'door-k7', false ), 'a relative redirect to the door is caught too' );
	ok( ! sn_login_redirect_reveals_door( $door, '/wp-admin/customize.php', 'door-k7', true ), 'a logged-in user is never stopped' );
	ok( ! sn_login_redirect_reveals_door( 'https://site.test/door-k7?loggedout=true', '/door-k7?action=logout', 'door-k7', false ), "the door's own flows keep working (logout lands back on the door)" );
	ok( ! sn_login_redirect_reveals_door( 'https://site.test/door-k7?checkemail=confirm', '/door-k7/?action=lostpassword', 'door-k7', false ), 'lost password from the door keeps working' );
	ok( ! sn_login_redirect_reveals_door( 'https://site.test/notes/', '/wp-admin/customize.php', 'door-k7', false ), 'redirects anywhere else are untouched' );
	ok( ! sn_login_redirect_reveals_door( 'https://site.test/door-k7-notes/', '/x', 'door-k7', false ), 'a path that merely starts like the door is not the door' );
}
ok( 1 === preg_match( "/add_filter\\( 'wp_redirect', 'sn_login_guard_redirect', (\\d+)/", $src, $p ) && (int) $p[1] > 10, 'it runs after the slug rewrite (which turns wp-login.php into the door at priority 10)' );
ok( 1 === preg_match( '/function sn_login_guard_redirect\(.*?status_header\( 404 \).*?get_query_template\( .404. \).*?exit;/s', $src ), 'a refused redirect answers with the theme 404 and stops, like /wp-login.php' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
