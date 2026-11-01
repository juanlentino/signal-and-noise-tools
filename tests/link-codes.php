<?php
/**
 * Tests: coded résumé links (inc/link-codes.php). /r/CODE serves the résumé
 * page itself (200, no redirect) so the code survives email apps; the code is
 * read back out of the stored path, never stored apart. Owner request,
 * 2026-10-31.
 *
 * Run: php tests/link-codes.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$GLOBALS['hooks'] = array();
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) { add_filter( $h, $cb, $p, $a ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function __( $s, $d = null ) { return $s; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_permalink( $p = 0 ) { return 'https://x.test/resume/'; }
function get_the_title( $p = 0 ) { return 'Resume'; }
$GLOBALS['page'] = (object) array( 'ID' => 1184, 'post_status' => 'publish', 'post_password' => '' );
function sn_jsonresume_page( $slug ) { return 'resume' === $slug ? $GLOBALS['page'] : null; }
$root = dirname( __DIR__ );
require $root . '/inc/link-codes.php';

echo "\nGroup: the code, read out of a path\n";
foreach ( array( '/r/hera' => 'hera', '/r/hera/' => 'hera', '/r/ab' => 'ab', '/r/start-her-2' => 'start-her-2', '/r/' . str_repeat( 'a', 40 ) => str_repeat( 'a', 40 ) ) as $p => $want ) {
	ok( $want === sn_link_code_from_path( $p ), "$p -> $want" );
}
foreach ( array( '/r/a', '/r/' . str_repeat( 'a', 41 ), '/r/Hera', '/r/Bad_Code!', '/r/he.ra', '/r/hera/x', '/r/', '/r', '/rx/hera', '/resume/', '', '/R/hera', '/r/hera?x=1', '//r/hera' ) as $p ) {
	ok( '' === sn_link_code_from_path( $p ), "not a code: '$p'" );
}

echo "\nGroup: the route\n";
$route = static function ( $uri, $vars = array( 'pagename' => 'r/hera' ) ) { return sn_link_code_request( $vars, $uri ); };
ok( array( 'page_id' => 1184 ) === $route( '/r/hera' ), '/r/hera asks WordPress for the résumé page itself' );
ok( array( 'page_id' => 1184 ) === $route( '/r/hera/?utm_source=x' ), 'a trailing slash and a query string still find it' );
ok( array( 'pagename' => 'r/hera' ) === $route( '/r/Bad_Code!' ), 'a bad code leaves the request alone: the normal 404' );
ok( array( 'pagename' => 'about' ) === $route( '/about/', array( 'pagename' => 'about' ) ), 'every other request is untouched' );
$GLOBALS['page'] = null;
ok( array( 'pagename' => 'r/hera' ) === $route( '/r/hera' ), 'no published résumé: the normal 404, never another page' );
$GLOBALS['page'] = (object) array( 'ID' => 1184 );
ok( in_array( 'sn_link_code_request', $GLOBALS['hooks']['request'] ?? array(), true ), 'wired on request' );

echo "\nGroup: no redirect, noindex, canonical left to the page\n";
ok( false === sn_link_code_no_redirect( 'https://x.test/resume/', '/r/hera' ), 'core never redirects a code to /resume/ (the code would be lost)' );
ok( 'https://x.test/b/' === sn_link_code_no_redirect( 'https://x.test/b/', '/a' ), 'every other canonical redirect stands' );
ok( in_array( 'noindex', sn_link_code_robots( array( 'max-image-preview:large' ), '/r/hera' ), true ), 'the meta robots says noindex' );
ok( array( 'x' ) === sn_link_code_robots( array( 'x' ), '/resume/' ), 'the résumé itself keeps its robots' );
ok( 'noindex' === ( sn_link_code_headers( array(), '/r/hera' )['X-Robots-Tag'] ?? '' ), 'and an X-Robots-Tag header, the one the edge Markdown twin copies' );
ok( array() === sn_link_code_headers( array(), '/resume/' ), 'no header elsewhere' );
$hdr = sn_link_code_headers( array( 'Content-Type' => 'text/html' ), '/r/hera' );
ok( ! isset( $hdr['Set-Cookie'] ) && 'text/html' === $hdr['Content-Type'], 'adds no cookie, keeps the rest' );
foreach ( array( 'redirect_canonical' => 'sn_link_code_no_redirect', 'sn_seo_robots_directives' => 'sn_link_code_robots', 'wp_headers' => 'sn_link_code_headers' ) as $h => $cb ) {
	ok( in_array( $cb, $GLOBALS['hooks'][ $h ] ?? array(), true ), "wired on $h" );
}

echo "\nGroup: Live, admin only\n";
$live = sn_link_codes_live_from_rows( array(
	array( 'path' => '/r/hera', 'readers' => 1 ),
	array( 'path' => '/r/hera/', 'readers' => 2 ),
	array( 'path' => '/r/zed', 'readers' => 4 ),
	array( 'path' => '/about/', 'readers' => 9 ),
	array( 'path' => '/r/Bad', 'readers' => 3 ),
) );
ok( array( array( 'label' => 'Resume via zed', 'url' => 'https://x.test/resume/', 'readers' => 4 ), array( 'label' => 'Resume via hera', 'url' => 'https://x.test/resume/', 'readers' => 3 ) ) === $live, 'one row per code, both spellings summed, most readers first, labeled with the page title' );
ok( array() === sn_link_codes_live_from_rows( array( array( 'path' => '/about/', 'readers' => 1 ) ) ), 'no code read now: an empty list' );

echo "\nGroup: the production path (core passes one argument; the URI is the request's)\n";
$_SERVER['REQUEST_URI'] = '/r/hera?utm_source=mail';
ok( array( 'page_id' => 1184 ) === sn_link_code_request( array( 'pagename' => 'r/hera' ) ), 'request: read from REQUEST_URI' );
ok( false === sn_link_code_no_redirect( 'https://x.test/resume/' ), 'redirect_canonical: read from REQUEST_URI' );
ok( in_array( 'noindex', sn_link_code_robots( array() ), true ), 'robots: read from REQUEST_URI' );
ok( 'noindex' === ( sn_link_code_headers( array() )['X-Robots-Tag'] ?? '' ) && 'noindex' === ( sn_link_code_headers( array(), new stdClass() )['X-Robots-Tag'] ?? '' ), 'wp_headers: read from REQUEST_URI, even if core hands over its WP object' );
$_SERVER['REQUEST_URI'] = '/about/';
ok( array( 'pagename' => 'about' ) === sn_link_code_request( array( 'pagename' => 'about' ) ) && 'u' === sn_link_code_no_redirect( 'u' ) && array() === sn_link_code_headers( array() ), 'any other request: untouched' );
unset( $_SERVER['REQUEST_URI'] );
ok( '' === sn_link_code_requested(), 'no request URI (cron, CLI): no code' );

echo "\nGroup: served content, the one shared test\n";
ok( sn_path_is_served( '/r/hera', array() ) && sn_path_is_served( '/about', array( '/about' => true ) ), 'a coded link or a real page' );
ok( ! sn_path_is_served( '/gone', array( '/about' => true ) ) && ! sn_path_is_served( '/r/Bad_Code', array() ), 'anything else is not' );

echo "\nGroup: the live read hands the coded rows to the admin list\n";
if ( ! defined( 'SN_ANALYTICS_REALTIME_WINDOW_MIN' ) ) { define( 'SN_ANALYTICS_REALTIME_WINDOW_MIN', 5 ); }
function sn_analytics_source( $f, $k = 'pageviews' ) { return 'ds'; }
function sn_analytics_trailing_from( $d ) { return '2026-10-30'; }
function sn_analytics_class_where( $c = 'human' ) { return '1=1'; }
function sn_analytics_excluded_path_sql() { return ''; }
function sn_analytics_overcap_where() { return ''; }
function url_to_postid( $u ) { return 0; }
$GLOBALS['live_rows'] = array( array( 'path' => '/r/hera', 'readers' => 2 ), array( 'path' => '/nowhere', 'readers' => 1 ) );
function sn_analytics_query( $sql ) { return $GLOBALS['live_rows']; }
require $root . '/inc/analytics-live-pages.php';
$links = null;
$pages = sn_analytics_live_pages_read( $links );
ok( array( array( 'label' => 'Resume via hera', 'url' => 'https://x.test/resume/', 'readers' => 2 ) ) === $links, 'the same read fills the admin list' );
ok( array() === $pages, 'and the public list stays without it (a coded path resolves to no page)' );
$GLOBALS['live_rows'] = null;
$links = 'stale';
ok( null === sn_analytics_live_pages_read( $links ) && null === $links, 'a failed read: both absent, never a stale list' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
