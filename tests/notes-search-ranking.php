<?php
/**
 * Tests: notes search served by the kernel (v14.0.0).
 *
 * The spec: docs/proposals/2026-09-11-notes-search-kernel-design.md. Three
 * groups — ranking, the posts_clauses shaping, the snippet — plus the
 * ACCEPTANCE FIXTURE: a two-word query where core's every-word LIKE misses
 * the note the kernel ranks first. Asserted red against the unhooked clauses
 * before green, so the guard is proven able to fail.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); } // corpus-integrity-scan.php's top-level const needs this.

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

// ── WP stubs ────────────────────────────────────────────────────────────
$GLOBALS['__filters'] = array();
function apply_filters( $h, $v ) { foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v, ...array_slice( func_get_args(), 2 ) ); } return $v; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $h ][] = $cb; return true; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { return true; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
$GLOBALS['__posts'] = array();
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses( $s, $allowed ) {
	// Minimal faithful stand-in: keep only the allowed tag names, drop every other tag whole.
	$keep = implode( '|', array_map( 'preg_quote', array_keys( (array) $allowed ) ) );
	return preg_replace( '#</?(?!(?:' . $keep . ')\b)[a-z][^>]*>#i', '', (string) $s );
}
class WPDB_Stub { public $posts = 'wp_posts'; }
$GLOBALS['wpdb'] = new WPDB_Stub();
class WPQ_Stub {
	private $vars;
	public function __construct( array $vars ) { $this->vars = $vars; }
	public function get( $k, $d = '' ) { return $this->vars[ $k ] ?? $d; }
}

require_once __DIR__ . '/../inc/ml-kernel.php';
require_once __DIR__ . '/../inc/corpus-integrity-scan.php'; // snt_corpus_integrity_sentence_at()
require_once __DIR__ . '/../inc/notes-search-ranking.php';

// ── Fixture corpus: six notes, built with the kernel itself ─────────────
$corpus = array(
	11 => 'The provenance ledger protects every note to Bitcoin. A ledger entry is a signed record.',
	12 => 'Detection scales the wrong way. Watermarks fade; detection budgets grow. Nothing here about ledgers.',
	13 => 'Verifying the artist is not enough. A signature proves a key, not a person.',
	14 => 'A ledger without an anchor is a diary. The anchor is what makes the ledger public evidence.',
	15 => 'Analytics without cookies: the beacon carries no identity, and the salt rotates daily.',
	16 => 'The provenance question, restated: who wrote this, and can a stranger check without trusting me?',
);
$docs = array();
foreach ( $corpus as $id => $text ) { $docs[ $id ] = snt_ml_tokenize( $text ); }
$stats = snt_ml_corpus_stats( $docs );
$search_docs = array();
foreach ( $docs as $id => $t ) { $search_docs[ $id ] = array( 'tf' => array_count_values( $t ), 'len' => count( $t ) ); }
function seed_index( $search_docs, $stats, $built_at = 1000 ) {
	$GLOBALS['__options']['snt_ml_search_index'] = array( 'built_at' => $built_at, 'built_by' => 't', 'stats' => array( 'idf' => $stats['idf'], 'avg_length' => $stats['avg_length'] ), 'docs' => $search_docs );
	$GLOBALS['__options']['snt_ml_corpus_meta']  = array( 'built_at' => 1000, 'fingerprint' => 'x', 'posts' => count( $search_docs ) );
}
function like_and( $term, $text ) { // core search: every word as a substring, ANDed
	foreach ( preg_split( '/\s+/', trim( $term ) ) as $w ) { if ( '' !== $w && false === stripos( $text, $w ) ) { return false; } }
	return true;
}
// Define the reader here ONLY if ml-artifacts.php is not loaded (it is not, in this suite).
if ( ! function_exists( 'snt_ml_search_index' ) ) {
	function snt_ml_search_index() {
		$i = get_option( 'snt_ml_search_index', false ); $m = get_option( 'snt_ml_corpus_meta', false );
		return ( is_array( $i ) && is_array( $m ) && (int) $i['built_at'] === (int) $m['built_at'] ) ? $i : null;
	}
}

echo "Group: ranking\n";
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true ); // reset memo
$r = snt_search_rank_notes( 'ledger anchor' );
ok( array() !== $r && 14 === $r[0], 'the note carrying BOTH query words ranks first (14)' );
ok( in_array( 11, $r, true ) && in_array( 16, $r, true ) === false, 'a note with one of the words is IN the ranking (11); a note with neither is not (16)' );
ok( ! in_array( 15, $r, true ) && ! in_array( 13, $r, true ), 'notes with no query word are absent' );
ok( array() === snt_search_rank_notes( 'the of and' ), 'a stopword-only query ranks nothing' );
ok( array() === snt_search_rank_notes( '' ), 'an empty query ranks nothing' );
$GLOBALS['__options']['snt_ml_search_index']['built_at'] = 999;
snt_search_rank_notes( '', true );
ok( array() === snt_search_rank_notes( 'ledger' ), 'a stale index (built_at != corpus meta) ranks nothing' );
unset( $GLOBALS['__options']['snt_ml_search_index'] );
snt_search_rank_notes( '', true );
ok( array() === snt_search_rank_notes( 'ledger' ), 'a missing index ranks nothing' );
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
add_filter( 'snt_search_ranking_ids', function ( $ids ) { return array( 12, '13', 'x', -4 ); } );
ok( array( 12, 13 ) === snt_search_rank_notes( 'ledger' ), 'the snt_search_ranking_ids filter replaces the list; non-positive and non-numeric entries are dropped' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );
// Tie-break: two docs with the same score → higher ID first. Build a tie on purpose.
$tie_docs = array( 21 => snt_ml_tokenize( 'salt rotates' ), 22 => snt_ml_tokenize( 'salt rotates' ) );
$tie_stats = snt_ml_corpus_stats( $tie_docs );
seed_index( array( 21 => array( 'tf' => array_count_values( $tie_docs[21] ), 'len' => 2 ), 22 => array( 'tf' => array_count_values( $tie_docs[22] ), 'len' => 2 ) ), $tie_stats );
snt_search_rank_notes( '', true );
ok( array( 22, 21 ) === snt_search_rank_notes( 'salt' ), 'ties break by ID DESC' );
// The cap.
$big = array(); $big_docs = array();
for ( $i = 1; $i <= 520; $i++ ) { $big_docs[ 1000 + $i ] = snt_ml_tokenize( 'anchor anchor' ); }
$big_stats = snt_ml_corpus_stats( $big_docs );
foreach ( $big_docs as $id => $t ) { $big[ $id ] = array( 'tf' => array_count_values( $t ), 'len' => count( $t ) ); }
seed_index( $big, $big_stats );
snt_search_rank_notes( '', true );
ok( SNT_SEARCH_RANK_CAP === count( snt_search_rank_notes( 'anchor' ) ) && 500 === SNT_SEARCH_RANK_CAP, 'the ranking is capped at 500 ids' );

echo "\nGroup: posts_clauses — the theme's query, widened and ordered\n";
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
$base = array( 'where' => " AND (((wp_posts.post_title LIKE '%ledger%') OR (wp_posts.post_content LIKE '%ledger%'))) AND wp_posts.post_type IN ('post','page') AND wp_posts.post_status = 'publish'", 'orderby' => 'wp_posts.post_date DESC', 'join' => '', 'groupby' => '', 'limits' => 'LIMIT 0, 50', 'distinct' => '', 'fields' => 'wp_posts.*' );

// THE ACCEPTANCE FIXTURE. "ledger anchor": note 11 has "ledger" but not "anchor".
// Core's every-word LIKE misses it; the kernel ranks it (14 first, then 11).
ok( false === like_and( 'ledger anchor', $corpus[11] ), 'fixture: core LIKE-AND misses note 11 for "ledger anchor" (it lacks "anchor")' );
ok( true === like_and( 'ledger anchor', $corpus[14] ), 'fixture: core LIKE-AND finds note 14 (it has both words)' );
$untouched = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger anchor' ) ) ); // NO sn_notes_search flag
ok( $untouched === $base, 'RED HALF: without the sn_notes_search flag the clauses are byte-identical — the query would still miss note 11' );
$shaped = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger anchor', 'sn_notes_search' => true ) ) );
ok( false !== strpos( $shaped['where'], 'wp_posts.ID IN (14,11)' ), 'GREEN HALF: the WHERE now admits the ranked ids (14,11) — note 11 is reachable' );
ok( 0 === strpos( $shaped['where'], ' AND ( (1=1' . $base['where'] . ') OR (' ), 'the existing WHERE is kept whole inside the OR' );
ok( false !== strpos( $shaped['where'], "wp_posts.post_status = 'publish' AND wp_posts.post_password = ''" ), 'the OR branch re-applies publish + no-password scope, so widening never out-scopes the query' );
ok( 0 === strpos( $shaped['orderby'], 'FIELD(wp_posts.ID, 11,14) DESC, wp_posts.post_date DESC' ), 'FIELD() lists the ranked ids REVERSED (so the best gets the highest position under DESC), then the date order the theme had' );
foreach ( array( 'join', 'groupby', 'limits', 'distinct', 'fields' ) as $k ) { ok( $shaped[ $k ] === $base[ $k ], "clause '$k' untouched" ); }
ok( $base === snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'the of', 'sn_notes_search' => true ) ) ), 'a stopword-only term leaves the clauses byte-identical' );
$no_order = $base; $no_order['orderby'] = '';
$shaped2 = snt_search_posts_clauses( $no_order, new WPQ_Stub( array( 's' => 'ledger', 'sn_notes_search' => true ) ) );
ok( 0 === strpos( $shaped2['orderby'], 'FIELD(' ), 'an empty theme orderby gets FIELD() alone, no dangling comma' );
ok( ! str_ends_with( trim( $shaped2['orderby'] ), ',' ), 'no trailing comma' );
// Injection guard: a string id from a rogue filter never reaches SQL.
add_filter( 'snt_search_ranking_ids', function ( $ids ) { return array( '14; DROP TABLE wp_posts', 11 ); } );
snt_search_rank_notes( '', true );
$shaped3 = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger', 'sn_notes_search' => true ) ) );
ok( false === strpos( $shaped3['where'], 'DROP' ) && false !== strpos( $shaped3['where'], 'IN (14,11)' ), 'ids are int-cast before they touch SQL' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );
// Negative control: a wrong order must red the order pin.
add_filter( 'snt_search_ranking_ids', function ( $ids ) { return array( 11, 14 ); } );
snt_search_rank_notes( '', true );
$shaped4 = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger', 'sn_notes_search' => true ) ) );
ok( 0 !== strpos( $shaped4['orderby'], 'FIELD(wp_posts.ID, 11,14)' ), 'control: a reversed ranking produces a DIFFERENT FIELD() list — the order pin can fail' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );

echo "\nGroup: the snippet — the row shows its evidence\n";
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
$GLOBALS['__posts'][11] = (object) array( 'ID' => 11, 'post_content' => "<!-- wp:paragraph -->\n<p>The provenance ledger anchors every note to Bitcoin.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>A ledger entry is a signed record.</p>\n<!-- /wp:paragraph -->" );
$GLOBALS['__posts'][12] = (object) array( 'ID' => 12, 'post_content' => '<p>Detection scales the wrong way.</p>' );
$s = snt_search_snippet( 'EXCERPT', 11, 'ledger anchor' );
ok( 'EXCERPT' !== $s, 'a ranked note gets a snippet, not its excerpt' );
ok( false !== strpos( $s, '<mark>ledger</mark>' ) && false === strpos( $s, '<mark>anchors</mark>' ), 'the query word is marked; the tokenizer does no stemming, so "anchors" is NOT marked for "anchor"' );
ok( false === strpos( $s, '<!--' ) && false === strpos( $s, '<p>' ), 'block markup never survives' );
ok( false !== strpos( $s, 'The provenance <mark>ledger</mark> anchors' ), 'the sentence chosen is the one holding the highest-idf query token, marked in place' );
ok( 'EXCERPT' === snt_search_snippet( 'EXCERPT', 12, 'ledger anchor' ), 'a note the ranking did not score keeps its excerpt (no evidence to show)' );
ok( 'EXCERPT' === snt_search_snippet( 'EXCERPT', 11, '' ), 'an empty term keeps the excerpt' );
ok( 'EXCERPT' === snt_search_snippet( 'EXCERPT', 999, 'ledger' ), 'an unknown post keeps the excerpt' );
// Title-only match: the token is in the ranking (index) but not in the prose → excerpt.
$GLOBALS['__posts'][14] = (object) array( 'ID' => 14, 'post_content' => '<p>Nothing from the query appears in this body at all.</p>' );
ok( 'EXCERPT' === snt_search_snippet( 'EXCERPT', 14, 'ledger anchor' ), 'no sentence carries a query token → excerpt' );
// Word boundary: "ledgers" must not mark "ledger" inside it… but "ledger" inside "ledgers" IS a substring —
// the rule is whole-word, so a query "ledger" leaves "ledgers" unmarked.
$GLOBALS['__posts'][16] = (object) array( 'ID' => 16, 'post_content' => '<p>Ledgers everywhere, and one ledger here.</p>' );
add_filter( 'snt_search_ranking_ids', function () { return array( 16 ); } );
snt_search_rank_notes( '', true );
$s16 = snt_search_snippet( 'EXCERPT', 16, 'ledger' );
ok( false !== strpos( $s16, 'Ledgers everywhere' ) && false === strpos( $s16, '<mark>Ledgers' ) && false !== strpos( $s16, 'one <mark>ledger</mark> here' ), 'marking is whole-word and case-insensitive: "Ledgers" untouched, "ledger" marked' );
// XSS pin: planted script in prose is asserted PRESENT first, then absent after.
$GLOBALS['__posts'][16] = (object) array( 'ID' => 16, 'post_content' => '<p>A ledger <script>alert(1)</script> line.</p>' );
ok( false !== strpos( $GLOBALS['__posts'][16]->post_content, '<script>' ), 'fixture: the script tag IS in the source (so the next pin cannot be vacuous)' );
$sx = snt_search_snippet( 'EXCERPT', 16, 'ledger' );
ok( false === strpos( $sx, '<script' ), 'no <script> survives (the tag is stripped before escaping; its text, if any, is escaped prose)' );
ok( 1 === preg_match( '#^[^<]*(<mark>[^<]*</mark>[^<]*)*$#', $sx ), 'the only tag in a snippet is <mark>' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );
// Length cap at a word boundary with an ellipsis.
$long = str_repeat( 'word ', 120 ) . 'ledger.';
$GLOBALS['__posts'][11] = (object) array( 'ID' => 11, 'post_content' => '<p>' . $long . '</p>' );
$sl = snt_search_snippet( 'EXCERPT', 11, 'ledger' );
ok( str_ends_with( $sl, '…' ) && mb_strlen( $sl ) < mb_strlen( $long ) && ! str_ends_with( rtrim( $sl, '…' ), 'wor' ), 'a long sentence is cut at a word boundary with an ellipsis' );
// Entity-fragment pin: a token that spells part of an HTML entity must never mark inside it.
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
$GLOBALS['__posts'][11] = (object) array( 'ID' => 11, 'post_content' => "<p>It's a ledger &amp; 039 line.</p>" );
$sa = snt_search_snippet( 'EXCERPT', 11, 'ledger 039 amp' );
ok( false === strpos( $sa, '&#<mark>' ) && false === strpos( $sa, '&<mark>amp' ) && false !== strpos( $sa, '<mark>ledger</mark>' ), 'a token that spells an entity fragment (039, amp) never marks inside &#039; or &amp;; the real word still marks' );
// Invalid-UTF-8 fallback pin: a sentence the shared helper truncates mid-codepoint must fall back to the excerpt, not an empty row.
$accented = str_repeat( 'é', 140 ) . ' ledger.';
$GLOBALS['__posts'][11] = (object) array( 'ID' => 11, 'post_content' => '<p>' . $accented . '</p>' );
$raw_prose  = wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', ' ', $GLOBALS['__posts'][11]->post_content ) );
$raw_prose  = trim( preg_replace( '/\s+/u', ' ', $raw_prose ) );
$raw_pos    = strpos( $raw_prose, 'ledger' );
$raw_at     = snt_corpus_integrity_sentence_at( $raw_prose, $raw_pos );
ok( false === mb_check_encoding( $raw_at, 'UTF-8' ), 'fixture: the shared helper\'s byte-level cut really does produce invalid UTF-8 here (so the fallback pin cannot be vacuous)' );
$sacc = snt_search_snippet( 'EXCERPT', 11, 'ledger' );
ok( 'EXCERPT' === $sacc, 'a sentence the shared helper truncates mid-codepoint falls back to the excerpt, never an empty row' );
ok( ! function_exists( 'sn_prov_normalize_v2' ), 'harness note: the ledger normaliser is not loaded here, so these pins exercise the strip fallback; the normaliser has its own suite' );

echo "\nGroup: two findings from the whole-branch review\n";
// (a) The query word "mark": a per-token loop re-scanned text that already held
// <mark>…</mark> from an earlier token and marked the tag name itself.
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
$GLOBALS['__abilities'] = array();
$GLOBALS['__posts'][11] = (object) array( 'ID' => 11, 'post_content' => '<p>A ledger is a mark the world can check.</p>' );
add_filter( 'snt_search_ranking_ids', function () { return array( 11 ); } );
snt_search_rank_notes( '', true );
$sm = snt_search_snippet( 'EXCERPT', 11, 'ledger mark' );
ok( false === strpos( $sm, '<<mark>' ) && false === strpos( $sm, '</<mark>' ) && false !== strpos( $sm, '<mark>ledger</mark>' ) && false !== strpos( $sm, '<mark>mark</mark>' ), 'a query containing the word "mark" marks the WORD, never the tag — one pass over the escaped sentence, not N' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );
// (b) A hand-crafted /tag/x/?s=foo carries a tax_query AND the flag; widening the
// WHERE with ranked ids would admit notes outside the tag. The clauses step aside.
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
$tagged = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger', 'sn_notes_search' => true, 'tax_query' => array( array( 'taxonomy' => 'post_tag', 'terms' => 3 ) ) ) ) );
ok( $tagged === $base, 'a flagged query that also carries a tax_query is left byte-identical — ranking never widens a tag archive' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
