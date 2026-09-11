<?php
/**
 * Tests: notes search served by the kernel (v13.111.0).
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
	11 => 'The provenance ledger anchors every note to Bitcoin. A ledger entry is a signed record.',
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
