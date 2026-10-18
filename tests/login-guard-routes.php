<?php
/**
 * Standalone test: the login guard follows the login slug (2026-10-18).
 *
 * The sn-login-guard worker guards the door by Cloudflare route, and a route
 * is a literal pattern. It used to live in the worker's wrangler.toml, so a
 * slug changed in S&N Home went unguarded until someone also edited that
 * file. The worker now declares no routes (wrangler then leaves them alone),
 * and saving the Login URL moves the guard's routes through the API: add the
 * new door, confirm it by re-reading, save the slug, remove the old door. A
 * save the guard cannot confirm is refused, so the door is never unguarded.
 *
 * Run: php tests/login-guard-routes.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_LOGIN_GUARD_ROUTES_TEST', true );
$GLOBALS['__calls'] = array();
$GLOBALS['__routes'] = array();
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function sn_cf_get_token() { return $GLOBALS['__token'] ?? 'tok'; }
function sn_cf_get_zone() { return 'zone1'; }
function __( $s, $d = null ) { return $s; }
function sn_lg_api( $method, $path, $body = null ) {
	$GLOBALS['__calls'][] = $method . ' ' . $path;
	if ( ! empty( $GLOBALS['__fail'][ $method ] ) ) { return array( 'http' => 403, 'body' => array( 'success' => false, 'errors' => array( array( 'message' => 'Authentication error' ) ) ) ); }
	if ( 'GET' === $method ) { return array( 'http' => 200, 'body' => array( 'success' => true, 'result' => array_values( $GLOBALS['__routes'] ) ) ); }
	if ( 'POST' === $method ) {
		$id = 'r' . ( count( $GLOBALS['__routes'] ) + 10 );
		if ( empty( $GLOBALS['__swallow'] ) ) { $GLOBALS['__routes'][ $id ] = array( 'id' => $id, 'pattern' => $body['pattern'], 'script' => $body['script'] ); }
		return array( 'http' => 200, 'body' => array( 'success' => true, 'result' => array( 'id' => $id ) ) );
	}
	if ( 'DELETE' === $method ) { $id = substr( $path, strrpos( $path, '/' ) + 1 ); unset( $GLOBALS['__routes'][ $id ] ); return array( 'http' => 200, 'body' => array( 'success' => true ) ); }
}
require dirname( __DIR__ ) . '/inc/login-guard-routes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function reset_routes() {
	$GLOBALS['__calls'] = array(); $GLOBALS['__fail'] = array(); $GLOBALS['__swallow'] = false;
	$GLOBALS['__routes'] = array(
		'a' => array( 'id' => 'a', 'pattern' => 'juanlentino.com/sn-login*', 'script' => 'sn-login-guard' ),
		'b' => array( 'id' => 'b', 'pattern' => 'www.juanlentino.com/sn-login*', 'script' => 'sn-login-guard' ),
		's' => array( 'id' => 's', 'pattern' => 'juanlentino.com/_sn/login-guard/status', 'script' => 'sn-login-guard' ),
		'o' => array( 'id' => 'o', 'pattern' => 'juanlentino.com/_sn/version', 'script' => 'sn-analytics' ),
	);
}
$patterns = static fn() => array_column( array_values( $GLOBALS['__routes'] ), 'pattern' );

ok( array( 'juanlentino.com/door-x*', 'www.juanlentino.com/door-x*' ) === sn_lg_door_patterns( 'door-x' ), 'the door is guarded on the apex and www, with the trailing * that covers ?action= and the POSTs' );

reset_routes();
$r = sn_lg_guard_slug( 'door-x' );
ok( true === $r['ok'], 'a new slug is guarded: both routes added and confirmed by re-reading' );
ok( in_array( 'juanlentino.com/door-x*', $patterns(), true ) && in_array( 'www.juanlentino.com/door-x*', $patterns(), true ), 'the new door routes exist' );
ok( in_array( 'juanlentino.com/sn-login*', $patterns(), true ), 'guarding the new door does not yet remove the old one (the slug is not saved yet)' );

sn_lg_prune_doors( 'door-x' );
ok( ! in_array( 'juanlentino.com/sn-login*', $patterns(), true ) && ! in_array( 'www.juanlentino.com/sn-login*', $patterns(), true ), 'after the save, the old door routes are removed' );
ok( in_array( 'juanlentino.com/_sn/login-guard/status', $patterns(), true ), 'the guard status route is never touched' );
ok( in_array( 'juanlentino.com/_sn/version', $patterns(), true ), 'routes of other workers are never touched' );

reset_routes();
$GLOBALS['__calls'] = array();
$r = sn_lg_guard_slug( 'sn-login' );
ok( true === $r['ok'] && ! in_array( 'POST /zones/zone1/workers/routes', $GLOBALS['__calls'], true ), 'an already-guarded slug adds nothing' );

reset_routes();
$GLOBALS['__fail'] = array( 'POST' => true );
$r = sn_lg_guard_slug( 'door-y' );
ok( false === $r['ok'] && false !== stripos( $r['error'], 'Authentication' ), 'a refused add is reported with Cloudflare\'s own words' );

reset_routes();
$GLOBALS['__swallow'] = true;
$r = sn_lg_guard_slug( 'door-z' );
ok( false === $r['ok'], 'an add Cloudflare accepted but the re-read does not show is not trusted' );

reset_routes();
$GLOBALS['__token'] = '';
$r = sn_lg_guard_slug( 'door-q' );
ok( false === $r['ok'] && array() === $GLOBALS['__calls'], 'no Cloudflare token: refused without a call' );
unset( $GLOBALS['__token'] );

$root = dirname( __DIR__ );
$save = (string) file_get_contents( "$root/inc/admin-post-actions/system.php" );
ok( 1 === preg_match( '/function sn_handle_save_login\(.*?sn_lg_guard_slug\(.*?sn_setting_update\(.*?sn_lg_prune_doors\(/s', $save ), 'the save guards the new door BEFORE storing the slug, and prunes the old one after' );
ok( 1 === preg_match( '/function sn_handle_save_login\(.*?return \'login_guard_failed\'/s', $save ), 'an unconfirmed guard refuses the save' );
$flash = (string) file_get_contents( "$root/inc/admin-flash-messages.php" );
ok( false !== strpos( $flash, "'login_guard_failed'" ), 'the refusal has a message' );
$grants = (string) file_get_contents( "$root/inc/cloudflare-credentials.php" );
ok( false !== strpos( $grants, 'Workers Routes' ), 'the token\'s grant list names Workers Routes › Edit' );
$main = (string) file_get_contents( "$root/signal-and-noise-tools.php" );
ok( false !== strpos( $main, "inc/login-guard-routes.php" ), 'the module is loaded' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
