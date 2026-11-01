<?php
/**
 * Tests: who is reading now, one line per reader (inc/analytics-live-readers.php).
 * Owner brief "MCP full analytics access", 2026-10-31: Live gains city,
 * device and entry source per reader; admin only, held in the realtime
 * cache, no visitor code; the public live answer unchanged.
 *
 * Run: php tests/analytics-live-readers.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {}
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $t, $v ) { return $v; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $a = null ) { return true; }
function sn_setting( $p, $d = null ) { return $d; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function __( $s, $d = null ) { return $s; }
function sn_analytics_query( $sql ) { $GLOBALS['queries'][] = $sql; return $GLOBALS['ae'] ?? null; }
$root = dirname( __DIR__ );
require $root . '/inc/analytics-sessions.php';
require $root . '/inc/analytics-realtime.php';
require $root . '/inc/analytics-live-pages.php';
require $root . '/inc/analytics-sources.php';
require $root . '/inc/link-codes.php';
require $root . '/inc/analytics-live-readers.php';
require $root . '/inc/analytics-live.php';
require $root . '/inc/analytics-live-admin.php';
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }

$now     = 1793500000;
$resolve = static fn( $p ) => array( '/notes/x/' => array( 'A note', 'https://x.test/notes/x/' ), '/about/' => array( 'About', 'https://x.test/about/' ) )[ $p ] ?? null;
$e       = static fn( $vid, $ago, $ev, $path, $more = array() ) => array_merge( array( 'vid' => $vid, 'ts' => $now - $ago, 'ev' => $ev, 'path' => $path, 'ref' => '', 'country' => 'US', 'region' => 'Massachusetts', 'city' => 'Boston', 'device' => 'desktop' ), $more );
$rows    = array(
	$e( 'vid-secret-a', 1500, 'pv', '/about/', array( 'ref' => 'www.linkedin.com' ) ),
	$e( 'vid-secret-a', 700, 'pv', '/notes/x/', array( 'ref' => '(internal)' ) ),
	$e( 'vid-secret-a', 60, 'sc', '/notes/x/' ),
	$e( 'vid-secret-b', 30, 'pv', '/r/hera', array( 'country' => 'AR', 'region' => 'Buenos Aires', 'city' => 'Buenos Aires', 'device' => 'mobile' ) ),
	$e( 'vid-secret-c', 900, 'pv', '/about/' ),
	$e( 'vid-secret-d', 20, 'tm', '/wp-login.php', array( 'country' => 'U S<b>', 'city' => "Evil\ncity" ) ),
	$e( 'vid-secret-e', 7000, 'pv', '/about/', array( 'ref' => 'news.ycombinator.com' ) ),
	$e( 'vid-secret-e', 100, 'pv', '/about/' ),
);

echo "\nGroup: one line per current reader\n";
$r = sn_analytics_live_readers_from_rows( $rows, $now, $resolve, array() );
ok( 4 === count( $r ), 'four readers: a visit with an event in the last 5 minutes; one whose last event is older is not reading now' );
ok( array( '(unmatched)', 'Resume via hera', 'A note', 'About' ) === array_column( $r, 'page' ), 'newest activity first; the page each is on now, a coded link by its code, anything not the site\'s own content (unmatched)' );
$a = $r[2];
ok( 'LinkedIn' === $a['source'] && 2 === $a['pages'] && 25 === $a['minutes'] && 'https://x.test/notes/x/' === $a['url'], 'entry source: the visit\'s first page view, not the internal click that brought them to this page; pages read and minutes into the visit' );
ok( 'Boston' === $a['city'] && 'Massachusetts' === $a['region'] && 'US' === $a['country'] && 'desktop' === $a['device'], 'place and device' );
ok( 'Buenos Aires' === $r[1]['city'] && 'mobile' === $r[1]['device'] && 'Direct' === $r[1]['source'], 'no referrer reads Direct' );
ok( '' === $r[0]['source'] && 0 === $r[0]['pages'] && '' === $r[0]['country'] && 'Evilcity' === $r[0]['city'], 'a visit with no page view in view: no source; a value that is not a country code is dropped, control characters stripped' );
ok( 'Hacker News' !== $r[3]['source'] && 'Direct' === $r[3]['source'], 'a gap longer than the idle gap starts a new visit, with its own entry source' );
$json = json_encode( $r );
ok( false === strpos( $json, 'vid-secret' ) && false === strpos( $json, '_ts' ) && false === strpos( $json, 'wp-login' ), 'no visitor code, no internal key, no visitor-written path' );
$many = array();
for ( $i = 0; $i < 30; $i++ ) { $many[] = $e( 'v' . $i, $i, 'pv', '/about/' ); }
ok( SN_ANALYTICS_LIVE_READERS_MAX === count( sn_analytics_live_readers_from_rows( $many, $now, $resolve, array() ) ), 'at most twenty lines' );

echo "\nGroup: the query\n";
$sql = sn_analytics_live_readers_sql();
ok( false !== strpos( $sql, sn_analytics_referrer_value_sql( 'blob3' ) . ' AS ref' ) && false !== strpos( $sql, "blob1 IN ('pv','sc','tm')" ) && false !== strpos( $sql, "INTERVAL '120' MINUTE" ) && false !== strpos( $sql, sn_analytics_class_where( 'human' ) ), 'human events of the last two hours, the internal click relabelled' );
$GLOBALS['ae'] = null;
ok( null === sn_analytics_live_readers_read(), 'a failed read is null (not read), never an empty list' );

echo "\nGroup: admin only, the realtime cache only\n";
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array( 'human' => 4 ), 'views_today' => 9, 'fetched' => 9, 'readers' => $r );
ok( ! array_key_exists( 'readers', sn_analytics_live_payload( false ) ), 'the public live answer never carries readers' );
ok( $r === sn_analytics_live_payload( true )['readers'], 'the admin answer carries them as cached' );
unset( $GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ]['readers'] );
ok( null === sn_analytics_live_payload( true )['readers'], 'a cache written before the field answers null' );
ok( false === strpos( (string) file_get_contents( "$root/inc/analytics-live-slots.php" ) . file_get_contents( "$root/inc/analytics-live-slots-parts.php" ), 'readers_read' ), 'the stored slots never take the readers' );
ok( false !== strpos( sn_analytics_live_admin_html( array() ), 'data-sn-live-readers' ), 'the admin Right now block carries the list' );

echo "\nGroup: the Live block draws each reader in words\n";
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) {
	ok( false, 'node is needed to run assets/live-now.js' );
} else {
	$run = static fn( $ans ) => json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/live-now-readers.js' ) . ' ' . escapeshellarg( json_encode( $ans ) ) ), true );
	$li  = $run( array( 'fetched' => 1, 'readers' => $r ) );
	ok( 'SPAN' === $li[0]['tag'] && '(unmatched)|Evilcity, Massachusetts · desktop' === $li[0]['text'], 'no page view in view: no source and no visit length, nothing invented' );
ok( 'Resume via hera|Buenos Aires, Buenos Aires, AR · mobile · from Direct · 0 min, 1 page' === $li[1]['text'], 'one page reads "1 page"' );
	ok( 'A' === $li[2]['tag'] && 'https://x.test/notes/x/' === $li[2]['href'] && 'A note|Boston, Massachusetts, US · desktop · from LinkedIn · 25 min, 2 pages' === $li[2]['text'], 'the page links where it can; then place, device, entry source and how far into the visit' );
	ok( array( array( 'tag' => 'LI', 'href' => '', 'text' => 'Nobody reading right now.' ) ) === $run( array( 'fetched' => 1, 'readers' => array() ) ), 'nobody reading says so' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
