<?php
/**
 * Standalone test: the 404 a stranger gets at /wp-admin/ is the front-end 404
 * (2026-10-18).
 *
 * Live: /wp-admin/ answered with the theme's 404 rendered while is_admin()
 * was still true. Core skips the block stylesheets in the admin
 * (wp-block-library, wp-block-navigation and nine more were missing), so the
 * header's navigation collapsed into its overlay menu at desktop width, and
 * the body carried admin-bar instead of error404. sn_login_front_context()
 * makes the request read as the front end before the template renders.
 *
 * Run: php tests/login-404-front-context.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// Core's is_admin(), verbatim in logic: a current screen decides, else WP_ADMIN.
define( 'WP_ADMIN', true );
function is_admin() {
	if ( isset( $GLOBALS['current_screen'] ) ) {
		return $GLOBALS['current_screen']->in_admin();
	}
	return defined( 'WP_ADMIN' ) && WP_ADMIN;
}
class Fake_Query { public $is_404 = false; public function set_404() { $this->is_404 = true; } }
$GLOBALS['wp_query'] = new Fake_Query();

$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/login-hide.php' );
ok( 1 === preg_match( '/function sn_login_front_context\(.*?\n}\n/s', $src, $m ), 'one helper sets the front-end context' );
if ( $m ) {
	eval( $m[0] ); // phpcs:ignore -- test-only: lift the helper out of the module.
	ok( true === is_admin(), 'precondition: inside the admin bootstrap is_admin() is true' );
	sn_login_front_context();
	ok( false === is_admin(), 'after it, is_admin() is false, so core prints the block stylesheets' );
	ok( true === $GLOBALS['wp_query']->is_404, 'and the query is a 404 (body class error404)' );
	ok( 'front' === $GLOBALS['current_screen']->id && false === $GLOBALS['current_screen']->is_block_editor(), 'the stand-in screen answers what callers ask of a screen' );
}
ok( 1 === preg_match( '/function sn_login_send_404\(.*?sn_login_front_context\(\).*?get_query_template/s', $src ), 'the 404 sets the context before resolving the template' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
