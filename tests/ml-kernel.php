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

echo "\nGroup (k): no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], '(k) zero notices/warnings/deprecations raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
