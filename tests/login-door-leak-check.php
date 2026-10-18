<?php
/**
 * Standalone test: a daily check that the login door is published nowhere
 * (2026-10-18). The door leaked three ways in one night (core redirects, the
 * OpenStation launch URLs, the speculation rules printed on every page), each
 * found only by hand. The owner's rule: the address gets out only if the
 * owner hands it out. This check reads the public surfaces a stranger reads
 * (pages, a 404, feeds, the REST index, sitemaps, robots.txt, response
 * headers) once a day and raises a watch when any of them contains the door.
 * It never stores or reports the slug itself, only where it was found.
 *
 * Run: php tests/login-door-leak-check.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_DOOR_LEAK_TEST', true );
const DAY_IN_SECONDS = 86400;
function home_url( $p = '' ) { return 'https://site.test' . $p; }
function sn_login_get_slug() { return 'door-k7q'; }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function get_posts( $a ) { return array( 42 ); }
function get_permalink( $id ) { return 'https://site.test/notes/latest/'; }
require dirname( __DIR__ ) . '/inc/login-door-leak-check.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$s = sn_door_leak_surfaces();
foreach ( array( 'https://site.test/', 'https://site.test/notes/latest/', 'https://site.test/feed/', 'https://site.test/wp-json/', 'https://site.test/robots.txt', 'https://site.test/wp-sitemap.xml' ) as $u ) {
	ok( in_array( $u, $s, true ), "it reads $u" );
}
ok( 1 === count( array_filter( $s, static fn( $u ) => false !== strpos( $u, 'sn-door-check-' ) ) ), 'it reads one 404, where a stranger lands on a dead link' );

ok( sn_door_leak_contains( '<script type="speculationrules">{"href_matches":"/door-k7q/*"}</script>', 'door-k7q' ), 'finds the door in a page body (the speculation-rules leak)' );
ok( sn_door_leak_contains( "Location: https://site.test/door-k7q?redirect_to=x", 'door-k7q' ), 'finds it in a header line' );
ok( sn_door_leak_contains( 'href="/door-k7q"', 'door-k7q' ), 'finds a bare path' );
ok( ! sn_door_leak_contains( 'href="/door-k7q-notes/"', 'door-k7q' ), 'a longer path that only starts like the door is not the door' );
ok( ! sn_door_leak_contains( 'a page about doors', 'door-k7q' ), 'clean text is clean' );

$GLOBALS['__opt'] = array();
$fetch = static function ( $url ) {
	if ( false !== strpos( $url, '/feed/' ) ) {
		return array( 'ok' => true, 'body' => '<rss>clean</rss>', 'headers' => array() );
	}
	if ( false !== strpos( $url, '/notes/latest/' ) ) {
		return array( 'ok' => true, 'body' => '<script type="speculationrules">"/door-k7q/*"</script>', 'headers' => array() );
	}
	if ( false !== strpos( $url, 'robots' ) ) {
		return array( 'ok' => false, 'body' => '', 'headers' => array() );
	}
	return array( 'ok' => true, 'body' => '<p>ok</p>', 'headers' => array( 'x-test' => 'fine' ) );
};
$r = sn_door_leak_run( $fetch, 1000 );
ok( array( 'https://site.test/notes/latest/' ) === $r['found'], 'the run names the page that published the door' );
ok( 1 === $r['errors'], 'an unreadable surface is counted, not treated as clean' );
$stored = (string) json_encode( get_option( SN_DOOR_LEAK_OPT ) );
ok( false === strpos( $stored, 'door-k7q' ), 'the stored result never contains the slug' );

$w = snt_watch_ripe_login_door_leak( array(), 2000 );
ok( true === $w['ripe'] && false !== strpos( $w['note'], '/notes/latest/' ) && false === strpos( $w['note'], 'door-k7q' ), 'the watch is ripe and names where, never the slug' );
$GLOBALS['__opt'][ SN_DOOR_LEAK_OPT ] = array( 'at' => 1000, 'found' => array(), 'checked' => 9, 'errors' => 0 );
ok( false === snt_watch_ripe_login_door_leak( array(), 2000 )['ripe'], 'quiet when the last run found nothing' );
$GLOBALS['__opt'][ SN_DOOR_LEAK_OPT ] = array( 'at' => 1000, 'found' => array(), 'checked' => 9, 'errors' => 0 );
ok( true === snt_watch_ripe_login_door_leak( array(), 1000 + 3 * DAY_IN_SECONDS )['ripe'], 'ripe when the check has not run for over two days (a silent check is not a clean one)' );
$GLOBALS['__opt'][ SN_DOOR_LEAK_OPT ] = array( 'at' => 1000, 'found' => array(), 'checked' => 9, 'errors' => 9 );
ok( true === snt_watch_ripe_login_door_leak( array(), 2000 )['ripe'], 'ripe when no surface could be read (a blind run is not a clean one)' );
$GLOBALS['__opt'][ SN_DOOR_LEAK_OPT ] = array( 'at' => 1000, 'found' => array(), 'checked' => 9, 'errors' => 5 );
ok( true === snt_watch_ripe_login_door_leak( array(), 2000 )['ripe'], 'ripe when most surfaces could not be read' );
$GLOBALS['__opt'][ SN_DOOR_LEAK_OPT ] = array( 'at' => 1000, 'found' => array(), 'checked' => 9, 'errors' => 1 );
ok( false === snt_watch_ripe_login_door_leak( array(), 2000 )['ripe'], 'one unreadable surface out of nine stays quiet' );
unset( $GLOBALS['__opt'][ SN_DOOR_LEAK_OPT ] );
ok( false === snt_watch_ripe_login_door_leak( array(), 2000 )['ripe'], 'not ripe before the first run' );

$root = dirname( __DIR__ );
ok( false !== strpos( (string) file_get_contents( "$root/inc/watches.php" ), "'login_door_leak'" ), 'the watch is registered' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/cron-dashboard.php" ), 'SN_DOOR_LEAK_HOOK' ), 'the daily job is in the cron dashboard registry' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/cron-lifecycle.php" ), 'SN_DOOR_LEAK_HOOK' ), 'and cleared on deactivation' );
ok( false !== strpos( (string) file_get_contents( "$root/signal-and-noise-tools.php" ), 'inc/login-door-leak-check.php' ), 'the module is loaded' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
