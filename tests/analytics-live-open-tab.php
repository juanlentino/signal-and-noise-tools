<?php
/**
 * Tests: returning to an open tab (inc/analytics-live-open-tab.php) and agent
 * pairing on the live readers (owner brief 2026-11-17). A visitor-day engaged
 * now with no page view today is counted apart as `open_tab`, never in `now`,
 * and its line says so; a (direct) reader near an automated hit is marked.
 *
 * Run: php tests/analytics-live-open-tab.php
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
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function url_to_postid( $u ) { return 0; }
function sn_analytics_config() { return array( 'account_id' => 'a' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
// Analytics Engine: the open-tab read answers $GLOBALS['open']; both live counts answer four humans; the rest is empty.
function sn_analytics_query( $sql ) {
	if ( false !== strpos( $sql, 'AS live' ) ) { return $GLOBALS['open']; }
	return false !== strpos( $sql, 'AS visitors' ) ? array( array( 'class' => 'human', 'visitors' => 4 ) ) : array();
}
$root = dirname( __DIR__ );
require $root . '/inc/analytics-sessions.php';
require $root . '/inc/analytics-realtime.php';
require $root . '/inc/analytics-live-pages.php';
require $root . '/inc/analytics-sources.php';
require $root . '/inc/link-codes.php';
require $root . '/inc/analytics-live-sources.php';
require $root . '/inc/analytics-live-readers.php';
require $root . '/inc/analytics-live.php';
require $root . '/inc/analytics-live-admin.php';

echo "\nGroup: who is returning to an open tab\n";
$vids = sn_analytics_live_open_tab_vids( array(
	array( 'vid' => 'tab', 'views' => 0, 'live' => 1 ),     // pinging now, no page view today
	array( 'vid' => 'read', 'views' => 2, 'live' => 1 ),    // a page view today: a reader
	array( 'vid' => 'gone', 'views' => 0, 'live' => 0 ),    // no signal in the window
	array( 'vid' => '', 'views' => 0, 'live' => 1 ),
) );
ok( array( 'tab' => true ) === $vids, 'only a visitor-day engaged now with no page view today returns to an open tab' );
$sql = sn_analytics_live_open_tab_sql();
ok( false !== strpos( $sql, "toStartOfInterval(now(), INTERVAL '1' DAY, 'America/New_York')" ), 'today is the visitor-day: from midnight in New York, where the code rotates' );
ok( false !== strpos( $sql, 'HAVING views = 0 AND live = 1' ) && false !== strpos( $sql, "sum(if(blob1 = 'pv', 1, 0)) AS views" ) && false !== strpos( $sql, sn_analytics_class_where( 'human' ) ), 'one grouped human read returns only the qualifying codes' );
ok( false !== strpos( $sql, SN_ANALYTICS_LIVE_ENGAGED_SQL . " AND timestamp >= now() - INTERVAL '5' MINUTE" ), 'live means an engagement signal in the live window, the rule Reading now uses' );
$GLOBALS['open'] = null;
ok( null === sn_analytics_live_open_tab_read(), 'a failed read is null, not "nobody"' );
$GLOBALS['open'] = array_fill( 0, SN_ANALYTICS_LIVE_OPEN_TAB_CAP, array( 'vid' => 'x', 'views' => 0, 'live' => 1 ) );
ok( null === sn_analytics_live_open_tab_read(), 'a capped read is not read' );

echo "\nGroup: each reader's line\n";
$now  = 1793500000;
$res  = static fn( $p ) => array( '/notes/x/' => array( 'A note', 'https://x.test/notes/x/' ) )[ $p ] ?? null;
$e    = static fn( $vid, $ago, $ev, $more = array() ) => array_merge( array( 'vid' => $vid, 'ts' => $now - $ago, 'ev' => $ev, 'path' => '/notes/x/', 'ref' => '', 'country' => 'AM', 'region' => 'Yerevan', 'city' => 'Yerevan', 'device' => 'desktop' ), $more );
$rows = array(
	$e( 'tab', 20, 'tm', array( 'dwell' => 10000 ) ),                         // a tab from yesterday: beacons only
	$e( 'yer', 600, 'pv' ), $e( 'yer', 30, 'sc' ),                            // (direct), an automated hit inside the visit
	$e( 'ref', 600, 'pv', array( 'ref' => 'www.linkedin.com' ) ), $e( 'ref', 40, 'sc' ), // referred, same time
);
$hits = array( $now - 300 );
$by   = array_column( array_map( static fn( $l ) => $l + array( 'k' => $l['source'] ), sn_analytics_live_readers_from_rows( $rows, $now, $res, array(), $hits, array( 'tab' => true ) ) ), null, 'k' );
ok( true === $by['']['open_tab'] && 'A note' === $by['']['page'] && 0 === $by['']['pages'], 'the open tab\'s line is marked, with the page they are on' );
ok( false === $by['Direct']['open_tab'] && false === $by['LinkedIn']['open_tab'], 'a visitor with a page view today is a reader, not an open tab' );
ok( true === $by['Direct']['paired'] && false === $by['LinkedIn']['paired'], 'a (direct) visit with a bot- or suspect-class page view inside it is paired; a referred one is not' );
ok( false === $by['']['paired'], 'a visit with no page view in view has no source, so it is not paired' );
$plain = sn_analytics_live_readers_from_rows( $rows, $now, $res, array() );
ok( array( null ) === array_values( array_unique( array_column( $plain, 'paired' ) ) ) && array( false ) === array_values( array_unique( array_column( $plain, 'open_tab' ) ) ), 'without the reads: paired null (not read), nobody marked an open tab' );

echo "\nGroup: counted apart, never in now\n";
$GLOBALS['open'] = array( array( 'vid' => 'tab', 'views' => 0, 'live' => 1 ) );
$GLOBALS['t']    = array();
sn_analytics_realtime_refresh();
ok( 1 === get_transient( SN_ANALYTICS_REALTIME_KEY )['open_tab'], 'the refresh caches the count' );
$adm = sn_analytics_live_payload( true );
ok( 1 === $adm['open_tab'] && 3 === $adm['now'] && 3 === $adm['classes']['human'], 'the admin answer counts the open tab apart and takes it out of now and the human class' );
$pub = sn_analytics_live_payload( false );
ok( ! array_key_exists( 'open_tab', $pub ) && 4 === $pub['now'], 'the public answer keeps its shape and its count' );
ok( 3 === sn_analytics_realtime_readers( 'human' ) && 0 === sn_analytics_realtime_readers( 'bot' ), 'the Overview and header figure (sn_analytics_realtime_readers) agrees with the admin answer' );
$GLOBALS['open'] = null;
sn_analytics_realtime_refresh();
$adm = sn_analytics_live_payload( true );
ok( null === $adm['open_tab'] && 4 === $adm['now'], 'a failed open-tab read: open_tab null, now not reduced' );

echo "\nGroup: in words on the Live block, and in the ability\n";
$html = sn_analytics_live_admin_html( array() );
ok( false !== strpos( $html, 'data-open-tab="returning to an open tab"' ) && false !== strpos( $html, 'data-paired="near an automated hit"' ) && false !== strpos( $html, 'not counted in Reading now' ), 'the strings ride on the markup' );
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) {
	ok( false, 'node is needed to run assets/live-now.js' );
} else {
	$lines = array( array( 'city' => 'Yerevan', 'region' => '', 'country' => 'AM', 'device' => 'desktop', 'source' => '', 'page' => 'A note', 'url' => '', 'pages' => 0, 'minutes' => 0, 'open_tab' => true, 'paired' => false ), array( 'city' => 'Yerevan', 'region' => '', 'country' => 'AM', 'device' => 'desktop', 'source' => 'Direct', 'page' => 'A note', 'url' => '', 'pages' => 1, 'minutes' => 9, 'open_tab' => false, 'paired' => true ) );
	$out   = json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/live-now-readers.js' ) . ' ' . escapeshellarg( json_encode( array( 'fetched' => 1, 'readers' => $lines, 'open_tab' => 1 ) ) ) ), true );
	ok( 'A note|returning to an open tab · Yerevan, AM · desktop' === $out['readers'][0]['text'], 'an open tab\'s line says so first, in words' );
	ok( 'A note|Yerevan, AM · desktop · from Direct · near an automated hit · 9 min, 1 page' === $out['readers'][1]['text'], 'a paired reader\'s line says so, in words' );
	ok( '1 returning to an open tab, not counted in Reading now' === $out['open'], 'the count is shown apart from Reading now' );
}
$ab = (string) file_get_contents( $root . '/inc/abilities-live-now.php' );
ok( false !== strpos( $ab, 'the exact count from the analytics worker\\\'s D1 store when `today_exact` is true' ) && false !== strpos( $ab, "'today_exact'  => array( 'type' => 'boolean' )" ), 'live-now says today is the exact D1 count when today_exact is true, and declares it' );
ok( false !== strpos( $ab, "'open_tab'     => array( 'type' => array( 'integer', 'null' ) )" ) && false !== strpos( $ab, 'counted apart, never in `now`' ), 'live-now declares open_tab and says it is never in now' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
