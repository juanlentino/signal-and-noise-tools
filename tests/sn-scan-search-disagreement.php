<?php
/**
 * sn-scan "search_disagreement" — measurement weave Phase 3 (v13.57.0).
 *
 * Properties: (1) each of the three detectors fires on exactly its reading and
 * not on the others' — with the thresholds at their boundaries; (2) a missing
 * keyword pipeline disables ONLY the site-level query reading and says so;
 * (3) the adapter refuses (503) when nothing has synced — a skip is not an
 * empty list; (4) the join goes through the shared key on BOTH sides, so a
 * stored path and a permalink that spell one page differently still join.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function __( $s, $d = null ) { return $s; }
function apply_filters( $h, $v ) { return $v; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
class WP_Error { public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; } public function get_error_data() { return $this->data; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

require_once __DIR__ . '/../inc/path-join-key.php';
require_once __DIR__ . '/../inc/search-console-derive.php';
require_once __DIR__ . '/../inc/sn-scan-search-disagreement.php';
require_once __DIR__ . '/../inc/sn-scan-detectors.php';
require_once __DIR__ . '/../inc/sn-scan-adapters.php';
require_once __DIR__ . '/../inc/abilities-sn-scan.php';

echo "sn-scan search_disagreement — v13.57.0\n\n";

$ids = static function ( $r, $det ) {
	$o = array();
	foreach ( $r['candidates'] as $c ) { if ( $det === $c['evidence']['detector'] ) { $o[] = $c['target_identity']; } }
	sort( $o ); return $o;
};
$THIN = SNT_SEARCH_THIN_WORDS; $MIN = SNT_GSC_DRIFT_MIN_IMPRESSIONS;

// ─── (1) detectors at their boundaries ───
$posts = array(
	array( 'id' => 1, 'path' => '/notes/long-unseen',  'word_count' => $THIN ),      // exactly the floor: has content, zero imp → no_impressions
	array( 'id' => 2, 'path' => '/notes/short-unseen', 'word_count' => $THIN - 1 ),  // thin AND unseen: neither reading (nothing to refresh, too thin to call indexation)
	array( 'id' => 3, 'path' => '/notes/thin-found',   'word_count' => $THIN - 1 ),  // thin, imp == MIN → thin_but_found
	array( 'id' => 4, 'path' => '/notes/thin-barely',  'word_count' => $THIN - 1 ),  // thin, imp == MIN-1 → nothing
	array( 'id' => 5, 'path' => '/notes/long-found',   'word_count' => 2000 ),       // healthy: nothing
);
$pages = array(
	'/notes/thin-found'  => array( 'impressions' => $MIN, 'clicks' => 1 ),
	'/notes/thin-barely' => array( 'impressions' => $MIN - 1, 'clicks' => 0 ),
	'/notes/long-found'  => array( 'impressions' => 500, 'clicks' => 20 ),
);
$r = snt_search_disagreement_impl( $posts, $pages, array(), array() );
ok( array( '/notes/long-unseen' ) === $ids( $r, 'no_impressions' ), 'no_impressions: only the post AT the word floor with no row (the thin unseen one is not an indexation finding)' );
ok( array( '/notes/thin-found' ) === $ids( $r, 'thin_but_found' ), 'thin_but_found: only the thin post AT the impression floor (one below is nothing)' );
ok( 2 === count( $r['candidates'] ), 'exactly two page-level candidates; the healthy long post and the barely-shown thin post fire nothing' );
$c = $r['candidates'][0];
ok( 1 === $c['targets'][0]['post_id'] && null === $c['apply_hint'] && SNT_SN_SCAN_CONF_SEARCH_DISAGREEMENT === $c['confidence'], 'candidate shape: post target, no apply path, the heuristic confidence' );
ok( $c['content_fingerprint'] !== snt_search_disagreement_candidate( 'no_impressions', '/notes/long-unseen', 1, 3, $THIN, '' )['content_fingerprint'], 'fingerprint changes when impressions change — a page Google starts showing is a NEW candidate, not the old one resurrected' );

// ─── (1b) v13.63.0: coverage evidence rides the no_impressions candidate ───
$rc = snt_search_disagreement_impl( $posts, $pages, array(), array(), array(
	'/notes/long-unseen' => array( 'indexed' => false, 'coverage_state' => 'Crawled - currently not indexed', 'last_crawl_time' => '2026-08-01T00:00:00Z', 'verdict' => 'NEUTRAL' ),
) );
$ne = array_values( array_filter( $rc['candidates'], static fn( $c ) => 'no_impressions' === $c['evidence']['detector'] ) )[0]['evidence'];
ok( false === $ne['coverage']['indexed'] && 'Crawled - currently not indexed' === $ne['coverage']['coverage_state'], 'no_impressions carries the stored coverage state when the path was inspected' );
ok( null === $r['candidates'][0]['evidence']['coverage'], 'and null — not a guess — when it was not' );
$rce = snt_search_disagreement_impl( $posts, $pages, array(), array(), array( '/notes/long-unseen' => array( 'error' => 'snt_gsc_api_error' ) ) );
ok( array( 'error' => 'snt_gsc_api_error' ) === array_values( array_filter( $rce['candidates'], static fn( $c ) => 'no_impressions' === $c['evidence']['detector'] ) )[0]['evidence']['coverage'], 'an inspection error is reported as an error, never as not-indexed' );

// ─── (1c) v13.65.0: inbound_links rides no_impressions ───
$ri = snt_search_disagreement_impl( $posts, $pages, array(), array(), array(), array( '/notes/long-unseen' => array( 'inbound' => 2 ) ) );
ok( 2 === array_values( array_filter( $ri['candidates'], static fn( $c ) => 'no_impressions' === $c['evidence']['detector'] ) )[0]['evidence']['inbound_links'], 'no_impressions carries inbound_links when counts are passed' );
ok( null === $r['candidates'][0]['evidence']['inbound_links'], 'and null when they were not computed' );
ok( 0 === snt_search_disagreement_impl( $posts, $pages, array(), array(), array(), array() )['candidates'][0]['evidence']['inbound_links'], 'computed but absent → a real zero' );

// ─── (2) the site-level query reading ───
$queries = array(
	array( 'key' => 'provenance ledger',  'impressions' => 50, 'clicks' => 2, 'position' => 9.4 ), // claimed by post 5's keyword "ledger"
	array( 'key' => 'quantum toaster',    'impressions' => 50, 'clicks' => 0, 'position' => 30.0 ), // nobody is about this
	array( 'key' => 'quantum kettle',     'impressions' => $MIN - 1 ),                               // under the floor: ignored
	array( 'key' => 'a of it',            'impressions' => 90 ),                                     // only short tokens: not evidence
	array( 'key' => 'TOASTER quantum',    'impressions' => 12 ),                                     // unclaimed, case-folded
);
$kw = array( 5 => array( 'provenance ledger', 'signed notes' ), 1 => array() );
$rq = snt_search_disagreement_impl( $posts, $pages, $queries, $kw );
ok( array( 'quantum toaster', 'toaster quantum' ) === $ids( $rq, 'query_unclaimed' ), 'query_unclaimed: fires on queries no keyword token claims; the claimed one, the sub-floor one and the short-token one do not' );
ok( true === $rq['keyword_pipeline'], 'keyword pipeline reported present' );
$rn = snt_search_disagreement_impl( $posts, $pages, $queries, null );
ok( array() === $ids( $rn, 'query_unclaimed' ) && false === $rn['keyword_pipeline'] && 2 === count( $rn['candidates'] ), 'no keyword pipeline: the query reading is OFF and reported off; page readings still run' );

// ─── registry ───
ok( in_array( 'search_disagreement', SNT_SN_SCAN_TYPES, true ), 'scan_type is in SNT_SN_SCAN_TYPES (the v10.52.1 lesson: an adapter without this entry is unreachable)' );
ok( 'snt_sn_scan_adapter_search_disagreement' === ( snt_sn_scan_adapters()['search_disagreement'] ?? null ), 'adapter registered' );
$det = array_map( static fn( $d ) => $d['id'], snt_sn_scan_detectors_for( 'search_disagreement' ) );
ok( array( 'no_impressions', 'thin_but_found', 'query_unclaimed' ) === $det, 'three detectors, one per reading' );
$qdet = snt_sn_scan_detectors_for( 'search_disagreement' )[2]['triggers_on'];
ok( false !== stripos( $qdet, 'NOT derivable' ) && false !== strpos( $qdet, (string) $MIN ), 'the query detector SAYS the page-level reading is not derivable, and quotes the resolved threshold' );

// ─── (3) never synced → 503, never an empty list ───
$GLOBALS['__gsc'] = null;
function snt_gsc_data() { return $GLOBALS['__gsc']; }
$e = snt_sn_scan_adapter_search_disagreement( null );
ok( is_wp_error( $e ) && 503 === ( $e->get_error_data()['status'] ?? 0 ), 'never synced: WP_Error 503 — a skip must not become "measured, clean"' );

// ─── (4) the join goes through the shared key on both sides ───
$GLOBALS['__gsc'] = array( 'synced_at' => 1, 'pages' => array( 'notes/foo/' => array( 'impressions' => 400, 'clicks' => 3 ) ), 'queries' => array() );
function get_posts( $args ) { $GLOBALS['__get_posts_args'] = $args; return isset( $args['post__in'] ) ? $args['post__in'] : array( 7 ); }
function get_post( $id ) { $p = new stdClass(); $p->ID = (int) $id; $p->post_content = 'tiny'; return $p; }
function get_permalink( $id ) { return 'https://example.test/notes/foo'; }
function wp_strip_all_tags( $s ) { return $s; }
$ra = snt_sn_scan_adapter_search_disagreement( null );
ok( is_array( $ra ) && 1 === count( $ra['candidates'] ) && 'thin_but_found' === $ra['candidates'][0]['evidence']['detector'] && '/notes/foo' === $ra['candidates'][0]['target_identity'],
	'a bare stored path "notes/foo/" joins a permalink "https://…/notes/foo": both sides normalized through sn_path_join_key' );
ok( 1 === $ra['posts_examined'] && false === $ra['truncated'], 'envelope counts the posts walked' );
$re = snt_sn_scan_adapter_search_disagreement( array() );
ok( array() === $re['candidates'] && 0 === $re['posts_examined'], 'an explicitly EMPTY scope walks nothing — never inverts to "all posts"' );
$rs = snt_sn_scan_adapter_search_disagreement( array( 7 ) );
ok( array( 7 ) === ( $GLOBALS['__get_posts_args']['post__in'] ?? null ), 'scope.post_ids narrows the walk to the named posts' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
