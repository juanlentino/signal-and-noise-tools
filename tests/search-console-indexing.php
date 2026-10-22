<?php
/**
 * The indexing guard (inc/search-console-indexing.php + -live.php): the weekly
 * request list, the 30-day stuck flag and the publish check.
 *
 * Run: php tests/search-console-indexing.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// id => [type, path]. 8 is /verify: a real Page the virtual route serves noindex.
$GLOBALS['P'] = array(
	1 => array( 'post', '/notes/a/' ), 2 => array( 'page', '/about/' ), 3 => array( 'post', '/notes/c/' ),
	4 => array( 'post', '/notes/d/' ), 5 => array( 'page', '/privacy-policy/' ), 6 => array( 'post', '/notes/young/' ),
	7 => array( 'post', '/notes/fine/' ), 8 => array( 'page', '/verify/' ), 9 => array( 'page', '/call/' ),
);
$GLOBALS['M'] = array( 9 => array( '_sn_noindex' => '1' ) );
function get_post( $id ) { $r = $GLOBALS['P'][ (int) $id ] ?? null; return $r ? (object) array( 'ID' => (int) $id, 'post_type' => $r[0], 'post_status' => 'publish', 'post_password' => '' ) : null; }
function get_permalink( $p ) { $id = is_object( $p ) ? (int) $p->ID : (int) $p; return 'https://example.test' . ( $GLOBALS['P'][ $id ][1] ?? '/' ); }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['M'][ (int) $id ][ $k ] ?? ''; }

require __DIR__ . '/../inc/search-console-indexing-live.php';

echo "indexing guard\n\n";
$now = 1792000000;
$day = DAY_IN_SECONDS;
$e   = static function ( $state, $path, $pid, $kind = 'post' ) {
	return array( 'indexed' => 0 === strpos( $state, 'Submitted and indexed' ), 'coverage_state' => $state, 'url' => 'https://example.test' . $path . '/', 'kind' => $kind, 'post_id' => $pid );
};
$entries = array(
	'/notes/a'        => $e( 'Crawled - currently not indexed', '/notes/a', 1 ),
	'/about'          => $e( 'Discovered - currently not indexed', '/about', 2 ),
	'/notes/c'        => $e( 'Discovered - currently not indexed', '/notes/c', 3 ),
	'/notes/d'        => $e( 'URL is unknown to Google', '/notes/d', 4 ),
	'/privacy-policy' => $e( 'Discovered - currently not indexed', '/privacy-policy', 5 ),
	'/notes/young'    => $e( 'Crawled - currently not indexed', '/notes/young', 6 ),
	'/notes/fine'     => $e( 'Submitted and indexed', '/notes/fine', 7 ),
	'/verify'         => $e( 'Crawled - currently not indexed', '/verify', 8 ),
	'/call'           => $e( 'Discovered - currently not indexed', '/call', 9 ),
	'/tag/x'          => $e( 'Discovered - currently not indexed', '/tag/x', 0, 'term' ),
);
$sub = static function ( $id, $age, $extra = array() ) use ( $now, $day ) {
	$t = $GLOBALS['P'][ $id ];
	return array_merge( array( 'post_id' => $id, 'path' => rtrim( $t[1], '/' ), 'url' => 'https://example.test' . $t[1], 'type' => $t[0], 'published' => $now - $age * $day, 'indexable' => sn_post_is_indexable( $id ), 'canonical' => '', 'core' => sn_indexing_is_core( $t[1] ) ), $extra );
};
$subjects = array( $sub( 1, 10 ), $sub( 2, 20 ), $sub( 3, 40 ), $sub( 4, 15 ), $sub( 5, 50 ), $sub( 6, 3 ), $sub( 7, 90 ), $sub( 8, 90 ), $sub( 9, 90 ) );
$inbound  = array(
	'/notes/a'    => array( 'inbound' => 2, 'linked_from' => array( 7, 3 ), 'linked_from_paths' => array( '/notes/fine', '/notes/c' ) ),
	'/about'      => array( 'inbound' => 0, 'linked_from' => array(), 'linked_from_paths' => array() ),
	'/notes/fine' => array( 'inbound' => 0, 'linked_from' => array(), 'linked_from_paths' => array() ),
);
$r = sn_indexing_build( $entries, $subjects, $inbound, $now, 'sc-domain:example.test' );

echo "Group: the core pages\n";
ok( sn_indexing_is_core( '/about/' ) && sn_indexing_is_core( 'https://example.test/about/uses/' ) && sn_indexing_is_core( '/provenance/over-detection/' ) && sn_indexing_is_core( '/maturity/' ) && sn_indexing_is_core( '/colophon' ), 'about, uses, the provenance children, the maturity pages and colophon are core' );
ok( sn_indexing_is_core( '/sobre-mi/', 'https://example.test/about/' ) && ! sn_indexing_is_core( '/sobre-mi/' ), 'a Spanish twin is core through its English counterpart' );
ok( ! sn_indexing_is_core( '/privacy-policy/' ) && ! sn_indexing_is_core( '/provenanceish/' ) && ! sn_indexing_is_core( '/notes/a/' ), 'other pages, a lookalike prefix and notes are not core' );

echo "\nGroup: the request list (7 days), ranked\n";
ok( array( '/notes/a', '/about', '/privacy-policy', '/notes/c', '/notes/d' ) === array_column( $r['request_list'], 'path' ), 'crawled-not-indexed first, then core pages, then the rest oldest first: ' . implode( ',', array_column( $r['request_list'], 'path' ) ) );
ok( ! in_array( '/verify', array_column( $r['request_list'], 'path' ), true ) && ! in_array( '/call', array_column( $r['request_list'], 'path' ), true ) && ! in_array( '/tag/x', array_column( $r['request_list'], 'path' ), true ), '/verify, /call and tag archives are never on it' );
ok( ! in_array( '/notes/young', array_column( $r['request_list'], 'path' ), true ), 'a note younger than 7 days waits' );
$a = $r['request_list'][0];
ok( 'https://example.test/notes/a/' === $a['url'] && 'Crawled - currently not indexed' === $a['coverage_state'] && 10 === $a['days'], 'a row carries url, Google\'s state verbatim and days since publish' );
ok( 1 === $a['inbound_indexed'], 'inbound counts only sources Google indexed (fine is indexed, c is not): ' . var_export( $a['inbound_indexed'], true ) );
ok( null === $r['request_list'][4]['inbound_indexed'] && 0 === $r['request_list'][1]['inbound_indexed'], 'a path the graph does not hold reads null, a node with no links reads 0' );
ok( 0 === strpos( $a['inspect_url'], 'https://search.google.com/search-console/inspect?resource_id=sc-domain%3Aexample.test&id=' ), 'each row has the URL Inspection deep link' );
ok( 5 === $r['request_total'], 'the true total rides beside the list' );

$big = $entries;
$bigsub = $subjects;
for ( $i = 100; $i < 112; $i++ ) {
	$GLOBALS['P'][ $i ] = array( 'post', "/notes/n$i/" );
	$big[ "/notes/n$i" ] = $e( 'Discovered - currently not indexed', "/notes/n$i", $i );
	$bigsub[] = $sub( $i, 8 );
}
$rb = sn_indexing_build( $big, $bigsub, $inbound, $now, '' );
ok( 10 === count( $rb['request_list'] ) && 17 === $rb['request_total'] && SNT_INDEXING_REQUEST_CAP === 10, 'capped at 10, with the true total (17) beside it' );

echo "\nGroup: the stuck flag (30 days)\n";
ok( array( '/privacy-policy', '/notes/c' ) === array_column( $r['stuck'], 'path' ) && 2 === $r['stuck_total'], 'only what is past 30 days, ranked the same way: ' . implode( ',', array_column( $r['stuck'], 'path' ) ) );
ok( 'Discovered - currently not indexed' === $r['stuck'][0]['coverage_state'] && array_key_exists( 'inbound_indexed', $r['stuck'][0] ), 'stuck rows carry the state and the inbound count' );
ok( 25 === SNT_INDEXING_STUCK_CAP && 30 === SNT_INDEXING_STUCK_DAYS, 'the stuck list is capped at 25' );

echo "\nGroup: the publish check (warn, never block)\n";
$GLOBALS['P'][20] = array( 'post', '/notes/linked/' );
$GLOBALS['P'][21] = array( 'post', '/notes/elsewhere/' );
$pc_sub = array( $sub( 20, 5 ), $sub( 21, 5, array( 'canonical' => 'https://other.test/x/' ) ), $sub( 6, 2 ), $sub( 5, 50 ), $sub( 8, 90, array( 'core' => true ) ) );
$pc_in  = array( '/notes/linked' => array( 'inbound' => 2, 'linked_from' => array( 7, 22 ), 'linked_from_paths' => array( '/notes/fine', '/notes/other' ) ), '/notes/elsewhere' => array( 'inbound' => 1, 'linked_from' => array( 7 ), 'linked_from_paths' => array( '/notes/fine' ) ) );
$pc     = sn_indexing_publish_check( $pc_sub, $entries + array( '/notes/other' => array( 'indexed' => true ) ), $pc_in, $now ); // 2026-10-22: two DISTINCT indexed sources (a source counts once).
$by     = array_column( $pc['failures'], 'reasons', 'path' );
ok( 3 === $pc['checked'], 'notes and core pages 3+ days old are checked; a 2-day note and a non-core page are not' );
ok( ! isset( $by['/notes/linked'] ), 'two inbound links from indexed pages, in the sitemap, self canonical: passes' );
ok( array( 'inbound', 'canonical' ) === ( $by['/notes/elsewhere'] ?? null ), 'one indexed inbound link and a canonical elsewhere: both named' );
ok( array( 'inbound', 'sitemap' ) === ( $by['/verify'] ?? null ), 'a core page the predicate refuses fails "sitemap"' );
$pc0 = sn_indexing_publish_check( $pc_sub, array(), $pc_in, $now );
ok( false === $pc0['inbound_judged'] && ! in_array( 'inbound', $pc0['failures'][0]['reasons'] ?? array(), true ), 'before the coverage map exists the inbound leg is not judged (unknown is not a failure)' );

echo "\nGroup: the live report shape\n";
$live = sn_indexing_report();
ok( array( 'request_list', 'request_total', 'stuck', 'stuck_total', 'publish_check', 'generated_at', 'coverage_run_at' ) === array_keys( $live ), 'request_list, stuck, publish_check, generated_at, coverage_run_at' );
ok( null === $live['coverage_run_at'] && array() === $live['request_list'], 'never synced: no run time, an empty list (not a guess)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
