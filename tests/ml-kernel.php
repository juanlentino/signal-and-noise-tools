<?php
/**
 * Tests for the SN ML kernel (inc/ml-kernel.php, PURE) and the pipeline
 * registry (inc/ml-pipelines.php, thin WP glue).
 *
 * The kernel has ZERO WordPress calls, so it is require()d directly — no
 * stubs, no seams (test-unguarded-fn-declarations rule). The registry file
 * needs only apply_filters + WP_Error, stubbed here with the registry-aware
 * pattern from tests/mcp-capabilities.php. The artifact fn
 * snt_ml_related_for_post() is deliberately defined MID-SUITE: the not-built
 * path must be asserted while the fn is genuinely absent (the real shape of
 * "artifacts not written yet"), then the built path against a fn honouring
 * the docblock contract — including [] as a real "nothing related" ANSWER
 * distinct from null "not built" (realtime-zero-vs-null rule).
 *
 * Run: php tests/ml-kernel.php
 * @since plugin v10.14.0 (SN ML kernel, stage 1)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

error_reporting( E_ALL );
// Any notice/warning/deprecation is a FAILURE (the "empty sets, no notices"
// requirement is a real assert, not a hope that nothing printed).
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

// Registry-aware apply_filters stub (pattern: tests/mcp-capabilities.php) —
// pass-through until add_test_filter() registers an override.
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) {
		foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); }
		return $v;
	}
}
function add_test_filter( $h, $cb ) { $GLOBALS['__filters'][ $h ][] = $cb; }

if ( ! class_exists( 'WP_Error' ) ) {
	// Models the three accessors the registry's callers rely on.
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-pipelines.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }
function feq( $a, $b, $eps = 1e-9 ) { return is_float( $a ) && abs( $a - $b ) < $eps; }

echo "SN ML kernel — pure primitives + pipeline registry\n\n";

echo "Group (a): tokenizer — determinism, stripping, stopwords, unicode\n";
$raw = '<!-- wp:paragraph {"dropCap":true} --><p>The Signal AND the Noise: 42 patterns, café-grade résumés!</p><!-- /wp:paragraph -->';
$t1  = snt_ml_tokenize( $raw );
$t2  = snt_ml_tokenize( $raw );
ok( $t1 === $t2, '(a) deterministic: two calls on the same input are identical' );
ok( $t1 === array( 'signal', 'noise', '42', 'patterns', 'café', 'grade', 'résumés' ),
	'(a) block comment JSON + tags stripped, lowercased, stopwords (the/and) dropped, digits kept: ' . implode( ',', $t1 ) );
ok( ! in_array( 'dropcap', $t1, true ) && ! in_array( 'true', $t1, true ), '(a) block-comment attribute JSON never leaks into tokens' );
ok( snt_ml_tokenize( 'signal noise café' ) === array( 'signal', 'noise', 'café' ), '(a) pre-stripped plain text accepted unchanged (stripping is a no-op)' );
ok( snt_ml_tokenize( 'a I x of to' ) === array(), '(a) min length 2 + stopwords: single chars and function words all drop' );
ok( snt_ml_tokenize( 'Über naïve Ω-θεωρία' ) === array( 'über', 'naïve', 'θεωρία' ),
	'(a) unicode-safe: mb lowercase (Ü→ü) and \p{L} split keep non-ASCII words; lone Ω drops on length' );
ok( snt_ml_tokenize( '' ) === array() && snt_ml_tokenize( '<!-- wp:x --><br/>' ) === array(), '(a) empty and markup-only inputs yield []' );
// 2026-08-14, found LIVE on the first reader-facing use of cluster labels: the
// tag-stripper removes <style> TAGS but keeps their CSS text, so an inline SVG
// figure's stylesheet dominated a real cluster's vocabulary — the public label
// read "currentcolor · fill". Container elements whose CONTENT is not prose
// (style, script) must drop whole. The SVG's visible <text> is prose and stays.
$svg = '<p>The provenance argument.</p><svg><style>.ph-box { fill: none; stroke: currentColor; } .ph-label { font-size: 13px; }</style><text>pipeline</text></svg><script>var ledger = "never";</script>';
$svg_tokens = snt_ml_tokenize( $svg );
ok( ! in_array( 'currentcolor', $svg_tokens, true ) && ! in_array( 'fill', $svg_tokens, true ) && ! in_array( 'stroke', $svg_tokens, true ) && ! in_array( '13px', $svg_tokens, true ), '(a) THE LIVE LABEL BUG: style-element CSS never tokenizes — a cluster label must be prose, not a stylesheet' );
ok( ! in_array( 'var', $svg_tokens, true ) && ! in_array( 'ledger', $svg_tokens, true ), '(a) script contents drop whole for the same reason' );
ok( in_array( 'provenance', $svg_tokens, true ) && in_array( 'pipeline', $svg_tokens, true ), '(a) prose around AND inside the figure (the visible <text>) still tokenizes — the fix removes noise, not signal' );
ok( snt_ml_tokenize( '<style>.a{fill:red}</style>signal' ) === array( 'signal' ), '(a) an unclosed-tag-free style block leaves only the prose after it' );
ok( snt_ml_tokenize( 'noise noise noise' ) === array( 'noise', 'noise', 'noise' ), '(a) duplicates preserved in order — TF depends on it' );

echo "\nGroup (b): corpus stats — df, smoothed idf edges, lengths\n";
$docs  = array(
	11 => array( 'common', 'rare' ),
	12 => array( 'common', 'alpha' ),
	13 => array( 'common', 'beta', 'beta' ),
);
$stats = snt_ml_corpus_stats( $docs );
ok( 3 === $stats['doc_count'], '(b) doc_count === 3' );
ok( array( 'common', 'rare', 'alpha', 'beta' ) === $stats['vocab'], '(b) vocab holds the 4 distinct terms' );
ok( 3 === $stats['df']['common'] && 1 === $stats['df']['rare'], '(b) df: common in all 3 docs, rare in 1' );
ok( 1 === $stats['df']['beta'], '(b) df counts DOCS, not occurrences (beta ×2 in one doc → df 1)' );
// Smoothing edge: term in ALL docs → log((N+1)/(N+1))+1 = exactly 1.0 (never
// 0, never negative); term in one doc → log((N+1)/2)+1.
ok( feq( $stats['idf']['common'], 1.0 ), '(b) idf smoothing floor: term in every doc weighs exactly 1.0' );
ok( feq( $stats['idf']['rare'], log( 2 ) + 1.0 ), '(b) idf(rare) === log(4/2)+1 ≈ 1.693147' );
ok( array( 11 => 2, 12 => 2, 13 => 3 ) === $stats['doc_lengths'], '(b) doc_lengths keyed by doc id' );
ok( feq( $stats['avg_length'], 7 / 3 ), '(b) avg_length === 7/3' );
$empty_stats = snt_ml_corpus_stats( array() );
ok( 0 === $empty_stats['doc_count'] && 0.0 === $empty_stats['avg_length'] && array() === $empty_stats['idf'],
	'(b) empty corpus: zero counts, 0.0 avg, empty maps — no division warning' );

echo "\nGroup (c): tf-idf — L2 norm, sparsity, OOV terms\n";
$vec = snt_ml_tfidf_vector( array( 'common', 'rare', 'rare' ), $stats );
$norm = sqrt( array_sum( array_map( function ( $w ) { return $w * $w; }, $vec ) ) );
ok( feq( $norm, 1.0 ), '(c) vector is L2-normalized: ||v|| ≈ 1.0' );
ok( array( 'common', 'rare' ) === array_keys( $vec ), '(c) sparse: only the doc\'s own terms appear' );
ok( $vec['rare'] > $vec['common'], '(c) rarer term (higher idf × tf 2) outweighs the everywhere-term' );
ok( array() === snt_ml_tfidf_vector( array(), $stats ), '(c) no tokens → empty vector (not a zero-filled vocab)' );
$oov = snt_ml_tfidf_vector( array( 'unseen' ), $stats );
ok( isset( $oov['unseen'] ) && feq( $oov['unseen'], 1.0 ), '(c) out-of-corpus term gets the df=0 smoothed idf and still normalizes to 1.0' );
// Mutation-survivor fix (PR #410 review): a single-term vector normalizes to
// 1.0 under ANY positive idf, so the assert above cannot pin the df=0
// smoothing itself. A two-term vector can: the weight RATIO must equal
// idf_oov/idf_common = (log(N+1)+1)/1.0 = log(4)+1 exactly.
$two = snt_ml_tfidf_vector( array( 'common', 'unseen' ), $stats );
ok( feq( $two['unseen'] / $two['common'], log( 4 ) + 1.0 ), '(c) OOV/in-corpus weight ratio pins the df=0 smoothed idf (log(N+1)+1)' );

echo "\nGroup (d): cosine — identity, orthogonal, empty\n";
ok( feq( snt_ml_cosine( $vec, $vec ), 1.0 ), '(d) identity: cos(v, v) ≈ 1.0' );
ok( 0.0 === snt_ml_cosine( array( 'x' => 1.0 ), array( 'y' => 1.0 ) ), '(d) orthogonal (no shared terms) === 0.0' );
ok( 0.0 === snt_ml_cosine( array(), $vec ) && 0.0 === snt_ml_cosine( $vec, array() ) && 0.0 === snt_ml_cosine( array(), array() ),
	'(d) empty vector on either side → 0.0, no warnings' );
$sym_a = snt_ml_tfidf_vector( array( 'common', 'alpha' ), $stats );
ok( feq( snt_ml_cosine( $vec, $sym_a ), snt_ml_cosine( $sym_a, $vec ) ), '(d) symmetric: cos(a,b) === cos(b,a) despite the small-side iteration' );
ok( snt_ml_cosine( $vec, $sym_a ) > 0.0 && snt_ml_cosine( $vec, $sym_a ) < 1.0, '(d) partial overlap lands strictly between 0 and 1' );

echo "\nGroup (e): bm25 — topical ranking + length normalization\n";
$bm_docs  = array(
	'on'    => array( 'espresso', 'crema', 'espresso', 'grind', 'tamp' ),
	'off'   => array( 'football', 'stadium', 'goal', 'referee', 'goal' ),
	'short' => array( 'apple', 'apple', 'banana' ),
	'long'  => array( 'apple', 'apple', 'banana', 'cherry', 'cherry', 'cherry' ),
);
$bm_stats = snt_ml_corpus_stats( $bm_docs );
$q        = array( 'espresso', 'crema' );
ok( snt_ml_bm25_score( $q, $bm_docs['on'], $bm_stats ) > snt_ml_bm25_score( $q, $bm_docs['off'], $bm_stats ),
	'(e) on-topic doc outranks the off-topic one' );
// Mutation-survivor fix (PR #410 review): orderings alone let a per-term
// constant scale (e.g. dropping the (k1+1) numerator) survive. This constant
// was independently recomputed from the textbook Okapi formula (review's
// Python derivation matches to 10dp) — it pins the exact formula, not the
// implementation's echo.
ok( feq( snt_ml_bm25_score( $q, $bm_docs['on'], $bm_stats ), 4.4723657671 ), '(e) exact-value pin: bm25(on-topic) === 4.4723657671 (independently derived)' );
ok( 0.0 === snt_ml_bm25_score( $q, $bm_docs['off'], $bm_stats ), '(e) zero term overlap scores exactly 0.0' );
// Length normalization: identical tf (apple ×2) — the shorter doc must win.
$s_short = snt_ml_bm25_score( array( 'apple' ), $bm_docs['short'], $bm_stats );
$s_long  = snt_ml_bm25_score( array( 'apple' ), $bm_docs['long'], $bm_stats );
ok( $s_short > $s_long, '(e) same tf, shorter doc scores higher (b = 0.75 length normalization)' );
ok( feq( snt_ml_bm25_score( array( 'apple' ), $bm_docs['short'], $bm_stats, 1.2, 0.0 ),
	snt_ml_bm25_score( array( 'apple' ), $bm_docs['long'], $bm_stats, 1.2, 0.0 ) ),
	'(e) b = 0 switches length normalization OFF: equal tf → equal score' );
ok( 0.0 === snt_ml_bm25_score( array(), $bm_docs['on'], $bm_stats ) && 0.0 === snt_ml_bm25_score( $q, array(), $bm_stats ),
	'(e) empty query or empty doc → 0.0' );
ok( feq( snt_ml_bm25_score( array( 'espresso', 'espresso' ), $bm_docs['on'], $bm_stats ),
	snt_ml_bm25_score( array( 'espresso' ), $bm_docs['on'], $bm_stats ) ),
	'(e) duplicate query terms count once (short-query convention pinned)' );

echo "\nGroup (f): graph signals — jaccard edges, direct link both directions\n";
$post_a = array( 'slug' => 'alpha', 'tags' => array( 'php', 'wp', 'ml' ), 'links_out' => array( 'x', 'y', 'z', 'beta' ) );
$post_b = array( 'slug' => 'beta', 'tags' => array( 'wp', 'ml', 'seo' ), 'links_out' => array( 'y', 'z', 'w' ) );
$sig    = snt_ml_graph_signals( $post_a, $post_b );
ok( feq( $sig['tag_overlap'], 0.5 ), '(f) tag jaccard: |{wp,ml}| / |{php,wp,ml,seo}| === 0.5' );
ok( 1 === $sig['direct_link'], '(f) a → b link detected (beta in alpha\'s links_out)' );
ok( feq( $sig['co_link'], 2 / 5 ), '(f) co_link jaccard: {y,z} over {x,y,z,beta,w} === 0.4' );
$rev = snt_ml_graph_signals(
	array( 'slug' => 'p', 'tags' => array(), 'links_out' => array() ),
	array( 'slug' => 'q', 'tags' => array(), 'links_out' => array( 'p' ) )
);
ok( 1 === $rev['direct_link'], '(f) direction-agnostic: b → a alone still flags direct_link' );
$none = snt_ml_graph_signals( array(), array() );
ok( array( 'tag_overlap' => 0.0, 'direct_link' => 0, 'co_link' => 0.0 ) === $none,
	'(f) fully empty posts: 0.0 / 0 / 0.0 — empty union never divides' );
ok( 0 === snt_ml_graph_signals( array( 'slug' => '' , 'links_out' => array( '' ) ), array( 'slug' => '' ) )['direct_link'],
	'(f) empty slugs never self-match into a phantom direct link' );

echo "\nGroup (g): related_score — default blend, custom weights, partial override\n";
$signals = array( 'tag_overlap' => 0.4, 'direct_link' => 1, 'co_link' => 0.2 );
ok( feq( snt_ml_related_score( 0.5, $signals ), 0.535 ),
	'(g) default blend: .55×.5 + .25×.4 + .15×1 + .05×.2 === 0.535' );
ok( feq( snt_ml_related_score( 0.5, $signals, array( 'lexical' => 1.0, 'tags' => 0.0, 'direct_link' => 0.0, 'co_link' => 0.0 ) ), 0.5 ),
	'(g) all-lexical weights: score === the cosine alone' );
ok( feq( snt_ml_related_score( 0.5, $signals, array( 'direct_link' => 0.45 ) ), 0.275 + 0.1 + 0.45 + 0.01 ),
	'(g) partial override: missing keys keep their defaults' );
ok( feq( snt_ml_related_score( 0.0, array() ), 0.0 ), '(g) no signal at all → 0.0, absent signal keys tolerated' );

echo "\nGroup (h): kernel purity — grep-pin the file text\n";
$kernel_src = file_get_contents( __DIR__ . '/../inc/ml-kernel.php' );
foreach ( array( 'apply_filters', 'get_posts', 'get_option', 'add_filter', 'add_action', 'do_action', 'get_transient', 'wp_cache' ) as $wp_fn ) {
	ok( false === strpos( $kernel_src, $wp_fn ), "(h) kernel file text contains no '$wp_fn'" );
}
// PR #410 review: the name list above whitelists only 8 functions — a future
// wp_json_encode()/esc_html() slip would pass it. Pin the whole wp_* CALL
// namespace instead (the 'wp:' block-comment literal in the tokenizer's
// docblock/regex is not a call and must not trip this).
ok( 0 === preg_match( '/\bwp_\w+\s*\(/', $kernel_src ), '(h) no wp_*() call of ANY name in the kernel file' );
ok( 0 === preg_match( '/\besc_\w+\s*\(/', $kernel_src ), '(h) no esc_*() call either — the kernel returns data, renderers escape' );

echo "\nGroup (i): pipeline registry — shipped map, unknown slug, filter seam\n";
$map = snt_ml_pipelines();
ok( isset( $map['related'] ) && 'snt_ml_pipeline_related' === $map['related'], '(i) \'related\' ships in the registry, bound to snt_ml_pipeline_related' );
$err = snt_ml_run( 'no-such-pipeline' );
ok( is_wp_error( $err ) && 'snt_ml_unknown_pipeline' === $err->get_error_code(), '(i) unknown slug → WP_Error snt_ml_unknown_pipeline' );
ok( is_array( $err->get_error_data() ) && 404 === $err->get_error_data()['status'], '(i) unknown-pipeline error is 404-shaped' );
add_test_filter( 'snt_ml_pipelines', function ( $p ) {
	$p['echo'] = function ( $args ) { return array( 'ok' => true, 'echo' => $args ); };
	return $p;
} );
$echoed = snt_ml_run( 'echo', array( 'x' => 1 ) );
ok( is_array( $echoed ) && array( 'x' => 1 ) === $echoed['echo'], '(i) a filter-registered pipeline resolves and runs through snt_ml_run' );
ok( isset( snt_ml_pipelines()['related'] ), '(i) filter EXTENDS the map — the shipped pipeline survives' );

echo "\nGroup (j): 'related' pipeline — args gate, not-built vs built vs empty\n";
$bad = snt_ml_run( 'related', array() );
ok( is_wp_error( $bad ) && 'snt_ml_invalid_args' === $bad->get_error_code(), '(j) missing post_id → snt_ml_invalid_args (400-shaped)' );
// The artifact fn does not exist yet at this point in the suite — the REAL
// pre-build state, not a stub of it.
ok( ! function_exists( 'snt_ml_related_for_post' ), '(j) precondition: artifact fn genuinely absent' );
$unbuilt = snt_ml_run( 'related', array( 'post_id' => 5 ) );
ok( is_wp_error( $unbuilt ) && 'snt_ml_not_built' === $unbuilt->get_error_code(), '(j) absent artifact layer → WP_Error snt_ml_not_built' );
ok( is_array( $unbuilt->get_error_data() ) && 503 === $unbuilt->get_error_data()['status'], '(j) not-built error is 503-shaped (temporarily unavailable, not 404)' );

// Now the artifact layer "arrives", honouring the docblock contract: a
// score-descending list of {post_id, score}, [] for no matches, null for
// not-built. The stub records its received $limit so the clamp is observable.
// Declared CONDITIONALLY: an unconditional top-level `function` is hoisted at
// compile time and would exist before the not-built asserts above ran.
if ( ! function_exists( 'snt_ml_related_for_post' ) ) {
	function snt_ml_related_for_post( $post_id, $limit ) {
		$GLOBALS['__artifact_calls'][] = array( 'post_id' => $post_id, 'limit' => $limit );
		if ( ! empty( $GLOBALS['__artifact_null'] ) ) { return null; }
		if ( ! empty( $GLOBALS['__artifact_empty'] ) ) { return array(); }
		$rows = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$rows[] = array( 'post_id' => 100 + $i, 'score' => round( 1.0 - $i * 0.05, 4 ) );
		}
		return $rows;
	}
}

$GLOBALS['__artifact_calls'] = array();
$out = snt_ml_run( 'related', array( 'post_id' => 5 ) );
ok( is_array( $out ) && true === $out['ok'], '(j) built path returns ok => true' );
ok( 4 === count( $out['related'] ), '(j) default limit 4 rows returned' );
ok( array( 'post_id' => 101, 'score' => 0.95 ) === $out['related'][0], '(j) rows keep {post_id, score} shape, top score first' );
ok( is_int( $out['related'][0]['post_id'] ) && is_float( $out['related'][0]['score'] ), '(j) types pinned: int post_id, float score' );
ok( array( array( 'post_id' => 5, 'limit' => 4 ) ) === $GLOBALS['__artifact_calls'], '(j) artifact fn called once with (5, 4)' );

$GLOBALS['__artifact_calls'] = array();
$capped = snt_ml_run( 'related', array( 'post_id' => 5, 'limit' => 99 ) );
ok( 10 === count( $capped['related'] ) && 10 === $GLOBALS['__artifact_calls'][0]['limit'], '(j) limit 99 clamps to the cap of 10 — both requested and returned' );
$floor = snt_ml_run( 'related', array( 'post_id' => 5, 'limit' => -3 ) );
ok( 1 === count( $floor['related'] ), '(j) limit −3 clamps to the floor of 1' );

$GLOBALS['__artifact_empty'] = true;
$none_related = snt_ml_run( 'related', array( 'post_id' => 5 ) );
ok( is_array( $none_related ) && true === $none_related['ok'] && array() === $none_related['related'],
	'(j) [] from artifacts is an ANSWER: ok => true with zero rows, NOT an error' );
$GLOBALS['__artifact_empty'] = false;

$GLOBALS['__artifact_null'] = true;
$went_null = snt_ml_run( 'related', array( 'post_id' => 5 ) );
ok( is_wp_error( $went_null ) && 'snt_ml_not_built' === $went_null->get_error_code(),
	'(j) null from artifacts (index vanished) → snt_ml_not_built — never conflated with the real 0-match answer' );
$GLOBALS['__artifact_null'] = false;

echo "\nGroup (l): topic clusters — deterministic components over the cosine graph (v10.21.0)\n";
// Vectors BY CONSTRUCTION (memberships provable on paper, never echoed from
// the kernel): identical sparse vectors ⇒ cosine ≡ 1.0; disjoint vocabularies
// ⇒ cosine ≡ 0.0. Two components {1,2,3} and {10,11}, singleton 20.
$va = array( 'alpha' => 0.6, 'beta' => 0.8 );
$vb = array( 'gamma' => 1.0 );
$vc = array( 'delta' => 0.8, 'epsilon' => 0.6 );
$cluster_vectors = array(
	2  => $va,
	1  => $va,
	3  => $va,
	11 => $vb,
	10 => $vb,
	20 => $vc,
);
$clusters = snt_ml_topic_clusters( $cluster_vectors, 0.5 );
ok( array( array( 1, 2, 3 ), array( 10, 11 ) ) === $clusters,
	'(l) membership pinned by construction: {1,2,3} then {10,11}; members ascending; clusters size-desc; SINGLETON 20 excluded (a topic needs two notes)' );
ok( array() === snt_ml_topic_clusters( array(), 0.5 ), '(l) empty vector map → [] with zero notices' );
ok( array() === snt_ml_topic_clusters( array( 5 => array( 'x' => 1.0 ) ), 0.5 ), '(l) a lone document clusters with nobody → []' );

// Threshold boundary is INCLUSIVE (>=): a pair sitting exactly ON the
// threshold clusters. cos( (1,0), (√.5,√.5) ) = √.5 exactly — hand-derivable.
$r2 = 0.7071067811865476; // 1/√2 to PHP float precision.
$boundary = array( 1 => array( 'x' => 1.0 ), 2 => array( 'x' => $r2, 'y' => $r2 ) );
ok( array( array( 1, 2 ) ) === snt_ml_topic_clusters( $boundary, $r2 ), '(l) cosine == threshold clusters (inclusive >=)' );
ok( array() === snt_ml_topic_clusters( $boundary, 0.7072 ), '(l) …and a hair above the same cosine does not' );

// Equal-size tiebreak: first-member ascending.
$tie = array(
	7 => array( 'p' => 1.0 ),
	8 => array( 'p' => 1.0 ),
	4 => array( 'q' => 1.0 ),
	5 => array( 'q' => 1.0 ),
);
ok( array( array( 4, 5 ), array( 7, 8 ) ) === snt_ml_topic_clusters( $tie, 0.5 ), '(l) equal-size clusters order by first member ascending' );

// Transitivity: A~B and B~C but A≁C still one component (that IS the
// clustering choice — components, not cliques). Hand-derived: with unit
// vectors, cos(A,B)=cos(B,C)=1/√2 ≥ 0.7, cos(A,C)=0.
$chain = array(
	1 => array( 'x' => 1.0 ),
	2 => array( 'x' => $r2, 'y' => $r2 ),
	3 => array( 'y' => 1.0 ),
);
ok( array( array( 1, 2, 3 ) ) === snt_ml_topic_clusters( $chain, 0.7 ), '(l) components, not cliques: the chain A~B~C is ONE topic even though A≁C' );

echo "\nGroup (m): cluster labels — top shared weight, deterministic (v10.21.0)\n";
// Hand-derived: summed weights across {1,2}: beta 1.6, alpha 1.2 → 'beta · alpha'.
$label_vectors = array(
	1 => array( 'alpha' => 0.6, 'beta' => 0.8 ),
	2 => array( 'alpha' => 0.6, 'beta' => 0.8 ),
);
ok( 'beta · alpha' === snt_ml_cluster_label( $label_vectors, array( 1, 2 ) ), '(m) label = top-2 terms by summed weight, middot-joined' );
ok( 'gamma' === snt_ml_cluster_label( array( 9 => array( 'gamma' => 1.0 ) ), array( 9 ) ), '(m) a one-term vocabulary labels with the one term' );
ok( '' === snt_ml_cluster_label( $label_vectors, array( 999 ) ), '(m) unknown members → empty label, zero notices' );
// Equal-weight tiebreak: alphabetical, so the label never flaps between builds.
$flat = array( 1 => array( 'zeta' => 0.5, 'eta' => 0.5 ) );
ok( 'eta · zeta' === snt_ml_cluster_label( $flat, array( 1 ) ), '(m) equal weights tie-break alphabetically — labels are build-stable' );

echo "\nGroup (n): cadence deviation — EWMA + z-score, hand-derived (v10.22.0)\n";
// Hand derivation (decimal-exact at 4dp): events 0,100,180,320,400; now 700.
// intervals = [100, 80, 140, 80]
// EWMA (alpha .3, seeded on the first interval):
//   100 → .3*80+.7*100 = 94 → .3*140+.7*94 = 107.8 → .3*80+.7*107.8 = 99.46
// population variance vs plain mean 100: (0+400+1600+400)/4 = 600 → std 24.4949
// current gap = 700-400 = 300 → z = (300-99.46)/24.494897… = 8.1870 (4dp)
$dev = snt_ml_cadence_deviation( array( 0, 100, 180, 320, 400 ), 700 );
ok( is_array( $dev ) && 4 === $dev['intervals'], '(n) four intervals measured from five events' );
ok( 99.46 === round( $dev['ewma'], 4 ), '(n) EWMA pinned by hand: 99.46' );
ok( 24.4949 === round( $dev['std'], 4 ), '(n) population std pinned by hand: 24.4949' );
ok( 300.0 === (float) $dev['current_gap'], '(n) current gap = now - last event' );
ok( 8.187 === round( $dev['z'], 4 ), '(n) z pinned by hand: 8.187' );

// Unsorted input yields the identical verdict — order is canonicalized inside.
$dev2 = snt_ml_cadence_deviation( array( 320, 0, 400, 100, 180 ), 700 );
ok( $dev === $dev2, '(n) input order never changes the verdict' );

// History floor: fewer than five events is UNKNOWN, never a confident z.
ok( null === snt_ml_cadence_deviation( array( 0, 100, 200, 300 ), 500 ), '(n) four events → null (not enough history — unknown, not zero)' );
ok( null === snt_ml_cadence_deviation( array(), 500 ), '(n) empty history → null with zero notices' );

// Zero spread: a metronome corpus measures no variance, so surprise is
// unquantifiable — z is null (unknown), never infinity, never 0.
$metro = snt_ml_cadence_deviation( array( 0, 100, 200, 300, 400 ), 1000 );
ok( is_array( $metro ) && null === $metro['z'] && 0.0 === (float) $metro['std'], '(n) zero std → z null: an unquantifiable surprise is UNKNOWN, not a number' );

echo "\nGroup (n2): robust cadence deviation — median/MAD, burst-resistant (v10.32.0)\n";
// Hand derivation: events 0,100,180,320,400 → intervals [100,80,140,80].
// sorted [80,80,100,140] → median = (80+100)/2 = 90.
// |x-90| = [10,10,50,10] → sorted [10,10,10,50] → MAD = (10+10)/2 = 10.
// Consistency constant 1.4826 makes MAD a σ-equivalent for normal data.
// current gap = 700-400 = 300 → z = (300-90)/(1.4826*10).
$rob = snt_ml_cadence_deviation_robust( array( 0, 100, 180, 320, 400 ), 700 );
ok( is_array( $rob ) && 4 === $rob['intervals'], '(n2) four intervals measured from five events' );
ok( 90.0 === (float) $rob['median'], '(n2) median interval pinned by hand: 90' );
ok( 10.0 === (float) $rob['mad'], '(n2) MAD pinned by hand: 10' );
ok( 400.0 === (float) $rob['span'], '(n2) span = last event - first event (the window\'s wall-clock reach)' );
ok( 300.0 === (float) $rob['current_gap'], '(n2) current gap = now - last event' );
ok( feq( $rob['z'], ( 300 - 90 ) / ( 1.4826 * 10 ) ), '(n2) z = (gap - median) / (1.4826 * MAD)' );

// The whole point: a burst poisons mean/σ far more than median/MAD. Series =
// 20 tight firings then one long interval; the outlier moves the mean but not
// the median, so the robust expectation stays the honest one.
$burst = array( 0 );
for ( $i = 1; $i <= 20; $i++ ) { $burst[] = $i * 60; }
$burst[] = 1200 + 100000; // One huge interval at the end.
$plain   = snt_ml_cadence_deviation( $burst, 1200 + 100000 + 60 );
$robust  = snt_ml_cadence_deviation_robust( $burst, 1200 + 100000 + 60 );
ok( 60.0 === (float) $robust['median'], '(n2) one 100k outlier interval never moves the median off 60' );
ok( $plain['ewma'] > 1000.0, '(n2) …while the EWMA is dragged into the thousands by that same outlier' );

// MAD hits exactly 0 as soon as a strict MAJORITY of intervals repeat — a far
// easier condition than the old population-σ's "every interval identical", and
// real cron (a system crontab hitting wp-cron.php) produces bit-identical gaps
// by the dozen. Zeroing z there would be a WORSE blind spot than the code this
// replaces, so a degenerate MAD falls back to the mean absolute deviation
// (scaled by sqrt(pi/2)); only a PERFECTLY rigid history stays unquantifiable.
$near_metro = array( 0.0, 3660.0 ); // First interval 3660, then 47 of exactly 3600.
for ( $i = 2; $i <= 48; $i++ ) { $near_metro[] = $near_metro[ $i - 1 ] + 3600; }
$nm = snt_ml_cadence_deviation_robust( $near_metro, end( $near_metro ) + 5 * 86400 );
ok( 48 === $nm['intervals'] && 3600.0 === (float) $nm['median'] && 0.0 === (float) $nm['mad'], '(n2) one odd interval among 47 identical ones: MAD is exactly 0 (the majority rule)' );
ok( null !== $nm['z'], '(n2) …yet a near-metronome five days silent is STILL quantifiable — MAD 0 is not the end of the road' );
ok( feq( $nm['scale'], sqrt( M_PI / 2 ) * ( 60.0 / 48.0 ) ), '(n2) the fallback scale is sqrt(pi/2) x the mean absolute deviation from the median' );
ok( feq( $nm['z'], ( 5 * 86400 - 3600 ) / ( sqrt( M_PI / 2 ) * ( 60.0 / 48.0 ) ) ), '(n2) …and z uses that fallback scale' );

ok( null === snt_ml_cadence_deviation_robust( array( 0, 100, 200, 300 ), 500 ), '(n2) four events → null: the history floor is unchanged' );
ok( null === snt_ml_cadence_deviation_robust( array(), 500 ), '(n2) empty history → null with zero notices' );
$rmetro = snt_ml_cadence_deviation_robust( array( 0, 100, 200, 300, 400 ), 1000 );
ok( is_array( $rmetro ) && null === $rmetro['z'] && 0.0 === (float) $rmetro['mad'], '(n2) zero MAD → z null: the metronome stays an honest unknown' );
$rord = snt_ml_cadence_deviation_robust( array( 320, 0, 400, 100, 180 ), 700 );
ok( $rob === $rord, '(n2) input order never changes the verdict' );

// ── (d) corpus drift — R4 4A, pipeline #9 ────────────────────────────────────
// WHY DOCUMENT SHARE AND NOT TF-IDF: idf is computed with N = the docs in THAT
// call (see snt_ml_corpus_stats), so a term's tf-idf weight is relative to its
// own bucket. Comparing 2024's weights to 2025's would compare two different
// scales and report drift that is an artefact of how many notes each year held.
// Document share (docs containing the term / docs in the period) is on one
// scale across every bucket, and is robust to a single verbose note repeating a
// word — which is exactly the noise that dominates at small N.
echo "\nGroup (d): corpus drift — per-term movement, and a bucket that refuses to speak\n";

$mk = function ( array $docs ) { return $docs; };
$before_docs = $mk( array(
	1 => array( 'provenance', 'ledger', 'anchor' ),
	2 => array( 'provenance', 'ledger', 'signature' ),
	3 => array( 'provenance', 'crawler' ),
	4 => array( 'crawler', 'robots' ),
	5 => array( 'crawler', 'robots', 'anchor' ),
) );
$after_docs = $mk( array(
	6  => array( 'provenance', 'agent', 'door' ),
	7  => array( 'agent', 'door', 'token' ),
	8  => array( 'agent', 'door' ),
	9  => array( 'agent', 'ledger' ),
	10 => array( 'door', 'token', 'ledger' ),
) );

$share = snt_ml_doc_share( $before_docs );
ok( 5 === $share['docs'], '(d) doc share reports its own bucket size' );
ok( abs( $share['shares']['provenance'] - 0.6 ) < 1e-9, '(d) share is docs-containing / docs, not token frequency (provenance in 3 of 5 = 0.6)' );
ok( ! isset( $share['shares']['agent'] ), '(d) a term absent from the bucket is ABSENT from shares, never a 0.0 entry — absent and zero are different answers' );

$drift = snt_ml_corpus_drift( $before_docs, $after_docs, 5, 12 );
ok( 'ok' === $drift['verdict'], '(d) two buckets at the floor produce a real verdict' );
ok( 5 === $drift['docs']['before'] && 5 === $drift['docs']['after'], '(d) the verdict carries both bucket sizes — a reader must be able to judge the base' );

$risen_terms = array_column( $drift['risen'], 'term' );
$fallen_terms = array_column( $drift['fallen'], 'term' );
$entered_terms = array_column( $drift['entered'], 'term' );
$silenced_terms = array_column( $drift['silenced'], 'term' );

ok( in_array( 'provenance', $fallen_terms, true ), '(d) provenance fell (0.6 -> 0.2) and lands in fallen' );
ok( in_array( 'agent', $entered_terms, true ) && in_array( 'door', $entered_terms, true ), '(d) terms with no before-bucket presence are ENTERED, not a delta from zero' );
ok( in_array( 'crawler', $silenced_terms, true ) && in_array( 'robots', $silenced_terms, true ), '(d) terms that stopped appearing are SILENCED, not a fall to zero' );
ok( ! in_array( 'agent', $risen_terms, true ) && ! in_array( 'agent', $fallen_terms, true ), '(d) an entered term never ALSO appears as movement — the four lists are disjoint' );
ok( ! in_array( 'ledger', $entered_terms, true ) && ! in_array( 'ledger', $silenced_terms, true ), '(d) a term present in both buckets is movement, never entry/exit (ledger 0.4 -> 0.4)' );
ok( ! in_array( 'ledger', $risen_terms, true ) && ! in_array( 'ledger', $fallen_terms, true ), '(d) and an UNCHANGED term appears in no list at all — no movement is not a movement of zero' );

// Determinism: ties must break on the term, never on hash order.
$tie_a = array( 1 => array( 'alpha' ), 2 => array( 'beta' ), 3 => array( 'x' ), 4 => array( 'x' ), 5 => array( 'x' ) );
$tie_b = array( 6 => array( 'alpha', 'beta' ), 7 => array( 'alpha', 'beta' ), 8 => array( 'x' ), 9 => array( 'x' ), 10 => array( 'x' ) );
$d1 = snt_ml_corpus_drift( $tie_a, $tie_b, 5, 12 );
$d2 = snt_ml_corpus_drift( $tie_a, $tie_b, 5, 12 );
ok( $d1 === $d2, '(d) same input, byte-identical output' );
$tie_risen = array_column( $d1['risen'], 'term' );
ok( array( 'alpha', 'beta' ) === array_slice( $tie_risen, 0, 2 ), '(d) equal deltas break on the term ascending, never on array/hash order' );

// THE THIN GATE. A bucket too small cannot speak, and "thin" is a DIFFERENT
// answer from "no drift" — a confident 0.00 over three notes is the failure
// this exists to prevent (the cadence SPAN lesson, applied to corpus size).
$thin = snt_ml_corpus_drift( array( 1 => array( 'a' ), 2 => array( 'b' ) ), $after_docs, 5, 12 );
ok( 'thin' === $thin['verdict'], '(d) THE THIN GATE: a bucket under the floor refuses to speak' );
ok( array() === $thin['risen'] && array() === $thin['fallen'] && array() === $thin['entered'] && array() === $thin['silenced'], '(d) and a thin verdict carries NO term movement — not one row a writer could mistake for a finding' );
ok( 2 === $thin['docs']['before'], '(d) but it still reports the size that disqualified it, so the writer knows WHY' );
// Mutation pin on the floor itself: raise it above a bucket that just passed
// and the verdict must flip. Without this, a floor of 0 would pass every
// assertion above and the gate would be decoration.
ok( 'thin' === snt_ml_corpus_drift( $before_docs, $after_docs, 6, 12 )['verdict'], '(d) the floor is LOAD-BEARING: raising it above a passing bucket flips the verdict' );
ok( 'ok' === snt_ml_corpus_drift( $before_docs, $after_docs, 5, 12 )['verdict'], '(d) and lowering it back restores it — the gate reads the floor, not a constant' );

// The top cap, and that it caps rather than silently truncating meaning.
$capped = snt_ml_corpus_drift( $before_docs, $after_docs, 5, 1 );
ok( 1 >= count( $capped['risen'] ) && 1 >= count( $capped['entered'] ), '(d) top caps each list independently' );

// ── (p) reading paths — R4 4B, the ordering the stored partition never had ──
// The chain is a READING order, so adjacency is the property that matters:
// each step goes to the most similar unvisited member (greedy NN), starting
// from the most central member (highest summed cosine to the rest). Central
// first because the hub note is the one a reader can enter cold; NN after
// because consecutive notes should share the most vocabulary.
echo "\nGroup (p): reading paths — a deterministic chain through a cluster\n";

// Geometry fixture: A and B nearly identical, C angled between the A/B pair
// and D, D distant from all but still in-cluster. Central = C (summed cosine
// 2.10 vs B's 2.05, verified by hand): the BRIDGE note is the most central,
// because it shares vocabulary with both wings — which is exactly the note a
// reader can enter cold.
$pv = array(
	1 => array( 'x' => 0.9, 'y' => 0.1 ),                 // A
	2 => array( 'x' => 0.8, 'y' => 0.2 ),                 // B
	3 => array( 'x' => 0.6, 'y' => 0.4 ),                 // C — the bridge, central
	4 => array( 'y' => 0.5, 'z' => 0.9 ),                 // D — the outlier
);
$path = snt_ml_cluster_path( $pv, array( 1, 2, 3, 4 ) );
ok( is_array( $path ) && 4 === count( $path ) && array() === array_diff( array( 1, 2, 3, 4 ), $path ), '(p) the path visits every member exactly once' );
ok( 3 === $path[0], '(p) the chain starts at the CENTRAL member — here the bridge note, the one sharing vocabulary with both wings' );
ok( 4 === $path[3], '(p) the outlier lands at the END of the chain, never in the middle of the flow' );
ok( $path === snt_ml_cluster_path( $pv, array( 4, 3, 2, 1 ) ), '(p) member input order never changes the chain' );

// Ties must break on the id, never hash order: two IDENTICAL vectors.
$tv = array( 7 => array( 'a' => 1.0 ), 9 => array( 'a' => 1.0 ), 8 => array( 'a' => 1.0 ) );
$tp = snt_ml_cluster_path( $tv, array( 9, 7, 8 ) );
ok( array( 7, 8, 9 ) === $tp, '(p) identical vectors chain by ascending id — deterministic, never insertion order' );

// Degenerates are answers.
ok( array( 5, 6 ) === snt_ml_cluster_path( array( 5 => array( 'a' => 1.0 ), 6 => array( 'a' => 0.9, 'b' => 0.1 ) ), array( 6, 5 ) ), '(p) a two-member cluster chains central-first' );
ok( array() === snt_ml_cluster_path( $pv, array( 1 ) ), '(p) a single member is NO path — a chain of one is not a chain (the singleton rule travels)' );
ok( array() === snt_ml_cluster_path( $pv, array() ), '(p) an empty member list is no path, no notices' );
ok( array() === snt_ml_cluster_path( array(), array( 1, 2 ) ), '(p) members with no vectors cannot chain — no fabricated order over missing geometry' );

echo "\nGroup (k): no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], '(k) zero notices/warnings/deprecations raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
