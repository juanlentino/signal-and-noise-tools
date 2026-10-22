<?php
/**
 * The index_gap watch: a published note or page Google has not indexed past 14 days.
 *
 * Properties: (1) ripe only for kind=post entries not indexed, older than the
 * threshold since publish; tag archives and pages excluded by noindex on purpose
 * never count; (2) the note names each path, Google's state verbatim, the days,
 * a direct URL Inspection link, Bing's answer when read, and that the owner
 * clicks Request indexing (the API cannot); (3) it clears when the weekly map
 * reads indexed; (4) an unsynced map is never ripe; (5) the row is registered
 * and its `read` names no admin screen.
 *
 * Run: php tests/search-console-index-watch.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
function did_action( $h ) { return 0; }
function get_option( $k, $d = false ) { return $d; }

function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
function __( $s, $d = null ) { return $s; }
// Post meta: post 7 is /call, marked noindex by the plugin's own per-page flag.
$GLOBALS['meta'] = array( 7 => array( '_sn_noindex' => '1' ) );
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ (int) $id ][ $k ] ?? ''; }
require __DIR__ . '/../inc/post-settings.php';
require __DIR__ . '/../inc/watches.php';
require __DIR__ . '/../inc/bing-webmaster.php';
require __DIR__ . '/../inc/bing-url-info.php';
require __DIR__ . '/../inc/search-console-index-watch.php';

echo "search console index watch\n\n";

$now  = 1792000000;
$prop = 'sc-domain:example.test';
$link = snt_gsc_inspect_link( $prop, 'https://example.test/notes/a b/' );
ok( 'https://search.google.com/search-console/inspect?resource_id=sc-domain%3Aexample.test&id=https%3A%2F%2Fexample.test%2Fnotes%2Fa%20b%2F' === $link, 'the inspect link carries the property and the URL, both escaped: ' . $link );

$e = static function ( $state, $url, $kind, $pid ) {
	return array( 'indexed' => 0 === strpos( $state, 'Submitted and indexed' ), 'coverage_state' => $state, 'url' => $url, 'kind' => $kind, 'post_id' => $pid );
};
$map = array( 'synced_at' => $now, 'entries' => array(
	'/about'          => $e( 'Crawled - currently not indexed', 'https://example.test/about/', 'post', 1 ),
	'/notes/new'      => $e( 'URL is unknown to Google', 'https://example.test/notes/new/', 'post', 2 ),
	'/notes/old'      => $e( 'Discovered - currently not indexed', 'https://example.test/notes/old/', 'post', 3 ),
	'/notes/fine'     => $e( 'Submitted and indexed', 'https://example.test/notes/fine/', 'post', 4 ),
	'/verify'         => $e( 'Excluded by ‘noindex’ tag', 'https://example.test/verify/', 'post', 5 ),
	'/tag/x'          => $e( 'Discovered - currently not indexed', 'https://example.test/tag/x/', 'term', 0 ),
	'/notes/err'      => array( 'error' => 'x', 'kind' => 'post', 'post_id' => 6 ),
	// Google has not reported the noindex (it never fetched the page, or saw it
	// before the flag), so only the plugin's own decision can keep it out.
	'/call'           => $e( 'Crawled - currently not indexed', 'https://example.test/call/', 'post', 7 ),
) );
$pub = array( 1 => $now - 400 * DAY_IN_SECONDS, 2 => $now - 5 * DAY_IN_SECONDS, 3 => $now - 15 * DAY_IN_SECONDS, 4 => $now - 90 * DAY_IN_SECONDS, 5 => $now - 90 * DAY_IN_SECONDS, 6 => $now - 90 * DAY_IN_SECONDS, 7 => $now - 90 * DAY_IN_SECONDS );

$stuck = snt_gsc_index_stuck( $map['entries'], $pub, $now, SNT_GSC_INDEX_WATCH_DAYS );
ok( array( '/about', '/notes/old' ) === array_column( $stuck, 'path' ), 'only published notes and pages past the line, oldest first: ' . implode( ',', array_column( $stuck, 'path' ) ) );
ok( 14 === SNT_GSC_INDEX_WATCH_DAYS, 'the line is 14 days: one weekly run past the day-10 single inspection' );

$bing = array( '/about' => array( 'state' => 'crawled', 'crawled' => '2026-10-01', 'discovered' => '', 'error' => '', 'checked_at' => $now ) );
$v = snt_watch_ripe_index_gap( array(), $now, $map, $pub, $prop, $bing );
ok( true === $v['ripe'], 'ripe while a published page sits unindexed past the line' );
foreach ( array( '/about', 'Crawled - currently not indexed', '400 days', 'resource_id=sc-domain%3Aexample.test', 'Bing crawled 2026-10-01', '/notes/old', 'Bing not read yet', 'Request indexing', 'cannot request' ) as $needle ) {
	ok( false !== strpos( $v['note'], $needle ), 'the note carries "' . $needle . '"' );
}
ok( false === strpos( $v['note'], '/notes/new' ) && false === strpos( $v['note'], '/tag/x' ) && false === strpos( $v['note'], '/verify' ), 'young notes, tag archives and noindex pages stay out of the note' );
ok( false === strpos( $v['note'], '/call' ), 'a page the plugin marks noindex (_sn_noindex, as /call) stays out even when Google\'s state does not say noindex' );
ok( false === snt_gsc_index_gap_candidate( $map['entries']['/call'] ) && true === snt_gsc_index_gap_candidate( $map['entries']['/about'] ), 'the candidate test asks the plugin\'s noindex decision; a normal unindexed page still counts' );

// Clears as the weekly map sees them indexed.
$map2 = $map;
$map2['entries']['/about']['indexed']     = true;
$map2['entries']['/notes/old']['indexed'] = true;
$v2 = snt_watch_ripe_index_gap( array(), $now, $map2, $pub, $prop, array() );
ok( false === $v2['ripe'] && false !== strpos( $v2['note'], '1 newer' ), 'clears once indexed, and says what is still young: ' . $v2['note'] );
ok( false === snt_watch_ripe_index_gap( array(), $now, null, $pub, $prop, array() )['ripe'], 'an unsynced map is never ripe' );

// Registration.
$row = null;
foreach ( snt_watches() as $w ) { if ( 'index_gap' === $w['id'] ) { $row = $w; } }
ok( null !== $row && 'snt_watch_ripe_index_gap' === $row['ripe'] && empty( $row['date_only'] ), 'index_gap is a registered state watch' );
ok( null !== $row && false === strpos( (string) $row['read'], '›' ), 'its read names no admin screen: ' . ( $row['read'] ?? '' ) );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' ), "inc/search-console-index-watch.php'" ) && false !== strpos( (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' ), "inc/bing-url-info.php'" ), 'the plugin loads the watch and the Bing reader' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
