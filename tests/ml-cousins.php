<?php
/**
 * Standalone tests for near-duplicate cousin detection (v10.16.0):
 * inc/ml-cousins.php + the 'near-duplicates' pipeline registration +
 * the signal-noise/near-duplicate-scan ability.
 *
 * Fixture design notes (per the repo's stub-drift + echo-testing rules):
 *   - get_posts() FILTERS the registry by post_type + post_status and
 *     applies posts_per_page (the transport's transform, not just the call).
 *   - The cosine pin is HAND-DERIVED, never recomputed from the kernel in
 *     the fixture (that would be echo-testing): posts 3 and 4 are the SAME
 *     token multiset in a different byte order, so their TF-IDF vectors are
 *     term-for-term identical whatever the idf values are ⇒ cosine ≡ 1.0
 *     exactly (up to fp noise the 4dp round absorbs). Different bytes ⇒
 *     different hash ⇒ NOT excluded as exact. The moderate pair (8, 9) is
 *     pinned by ordering + threshold-boundary counts, not by value.
 *   - The exact-duplicate pair (1, 2) ALSO has cosine 1.0 — asserting it
 *     appears in NO pair proves the hash exclusion, not a low similarity.
 *
 * Run: php tests/ml-cousins.php
 * @since plugin v10.16.0 (ML pipeline #2)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

error_reporting( E_ALL );
// Any notice/warning/deprecation is a FAILURE (asserted at the end).
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

// ─── WP stubs (BEFORE the SUT loads) ─────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}
// Pass-through apply_filters (registry pattern from tests/ml-kernel.php).
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) {
		foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); }
		return $v;
	}
}

// Fixture post factory — WP_Post field names, real shapes.
function tf_post( $id, $status, $content, $extra = array() ) {
	$p = new stdClass();
	$p->ID           = $id;
	$p->post_title   = $extra['title'] ?? "Post $id";
	$p->post_name    = $extra['slug'] ?? "post-$id";
	$p->post_status  = $status;
	$p->post_type    = $extra['post_type'] ?? 'post';
	$p->post_content = $content;
	return $p;
}

// Vocabulary is PARTITIONED between the pair groups: cross-group cosine is
// exactly 0, so every pair the scan reports is one this fixture designed.
$X = "<!-- wp:paragraph -->\n<p>Manifiesto quantum archive telescope harvest.</p>\n<!-- /wp:paragraph -->";

$GLOBALS['__posts'] = array(
	// Exact-duplicate pair: identical BYTES → same hash → must be EXCLUDED
	// even though its cosine is 1.0.
	1  => tf_post( 1, 'publish', $X, array( 'title' => 'Original', 'slug' => 'original' ) ),
	2  => tf_post( 2, 'draft', $X, array( 'title' => 'Draft twin', 'slug' => 'draft-twin' ) ),
	// Cousin pair (the origin use case: scheduled vs published): same token
	// multiset, different byte order → different hash, cosine exactly 1.0.
	3  => tf_post( 3, 'publish', '<p>Espresso ritual crema tamp grind.</p>', array( 'title' => 'Live note', 'slug' => 'live-note' ) ),
	4  => tf_post( 4, 'future', '<p>Grind tamp crema ritual espresso.</p>', array( 'title' => 'Scheduled cousin', 'slug' => 'scheduled-cousin' ) ),
	// Unrelated post: disjoint vocabulary → appears in NO pair.
	5  => tf_post( 5, 'publish', '<p>Zeppelin cartography whalesong meridian.</p>' ),
	// Empty + whitespace-only bodies: never pair.
	6  => tf_post( 6, 'draft', '' ),
	7  => tf_post( 7, 'pending', "  \n\t " ),
	// Moderate cousin pair: 6 tokens each, 5 shared, 1 unique per side —
	// similar but not identical, lands strictly between 0.6 and 0.95.
	8  => tf_post( 8, 'pending', '<p>Kettle pour bloom filter scale timer.</p>' ),
	9  => tf_post( 9, 'private', '<p>Kettle pour bloom filter scale thermometer.</p>' ),
	// Outside the walk entirely: trash status, non-'post' type.
	10 => tf_post( 10, 'trash', $X ),
	11 => tf_post( 11, 'publish', $X, array( 'post_type' => 'page' ) ),
	// Non-empty hash but ZERO tokens after stripping: no lexical signal.
	12 => tf_post( 12, 'draft', '<!-- wp:spacer --><hr/><!-- /wp:spacer -->' ),
);

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$out = array();
		foreach ( $GLOBALS['__posts'] as $p ) {
			if ( $p->post_type !== ( $args['post_type'] ?? 'post' ) ) { continue; }
			if ( ! in_array( $p->post_status, (array) ( $args['post_status'] ?? array( 'publish' ) ), true ) ) { continue; }
			$out[] = $p;
		}
		$cap = (int) ( $args['posts_per_page'] ?? -1 );
		return $cap > 0 ? array_slice( $out, 0, $cap ) : $out;
	}
}

// ─── Load the SUT (kernel is PURE — required directly, no stubs) ─────
require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-pipelines.php';
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/ml-cousins.php';
require __DIR__ . '/../inc/abilities-corpus.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function pair_ids( $pair ) { return array( $pair['a']['post_id'] ?? 0, $pair['b']['post_id'] ?? 0 ); }
function all_pair_ids( $scan ) {
	$ids = array();
	foreach ( $scan['pairs'] as $p ) { $ids = array_merge( $ids, pair_ids( $p ) ); }
	return $ids;
}

echo "Near-duplicate cousin detection — plugin v10.16.0\n\n";

// ─── The scan at the default threshold ───────────────────────────────
$scan = snt_ml_cousin_pairs();
ok( is_array( $scan ) && true === $scan['ok'], 'scan returns ok envelope' );
ok( 2 === $scan['pair_count'] && 2 === count( $scan['pairs'] ), 'exactly two cousin pairs at the default 0.6 (perm pair 3+4, moderate pair 8+9)' );
ok( 0.6 === $scan['threshold'], 'envelope echoes the applied threshold (default 0.6)' );
ok( 10 === $scan['posts_scanned'], 'walk covered the 10 non-trash post-type posts (trash + page excluded by the fetch)' );
ok( false === $scan['truncated'], 'untruncated below SNT_CORPUS_MAX_LIST' );
ok( is_int( $scan['scanned_at'] ) && $scan['scanned_at'] > 0, 'scanned_at is a positive timestamp' );

// Top pair: hand-derived cosine. Same token multiset, different byte order
// ⇒ identical TF-IDF vectors whatever the corpus idf ⇒ cosine ≡ 1.0.
$top = $scan['pairs'][0];
ok( array( 'a', 'b', 'cosine' ) === array_keys( $top ), 'pair carries exactly a / b / cosine' );
ok( array( 3, 4 ) === pair_ids( $top ), 'top pair is the permuted-body cousin pair, a = lower post_id' );
ok( is_float( $top['cosine'] ) && abs( $top['cosine'] - 1.0 ) < 1e-9, 'permuted-multiset cosine === 1.0 at 4dp (hand-derived, not kernel-echoed)' );
ok( array( 'post_id', 'title', 'slug', 'status' ) === array_keys( $top['a'] ), 'pair member carries exactly post_id/title/slug/status' );
ok( is_int( $top['a']['post_id'] ) && 'Live note' === $top['a']['title'] && 'live-note' === $top['a']['slug'], 'member metadata resolves from the post' );
$statuses = array( $top['a']['status'], $top['b']['status'] );
sort( $statuses );
ok( array( 'future', 'publish' ) === $statuses, 'the origin use case: a SCHEDULED cousin of a PUBLISHED post is found pre-publish' );

// Second pair: the moderate cousins, strictly below the perm pair.
$second = $scan['pairs'][1];
ok( array( 8, 9 ) === pair_ids( $second ), 'second pair is the 5-of-6-shared moderate pair (cross pending/private)' );
ok( $second['cosine'] >= 0.6 && $second['cosine'] < 0.95, 'moderate pair lands in [0.6, 0.95) — above default, below the max clamp' );
ok( $second['cosine'] < $top['cosine'], 'sorted cosine-descending: identical-multiset pair outranks the partial one' );

// Exclusions — every one is a pair the naive all-pairs cosine WOULD report.
$ids_in_pairs = all_pair_ids( $scan );
ok( ! in_array( 1, $ids_in_pairs, true ) && ! in_array( 2, $ids_in_pairs, true ),
	'byte-exact duplicates (cosine 1.0!) are EXCLUDED — they are the exact scan\'s finding, not cousins' );
ok( ! in_array( 5, $ids_in_pairs, true ), 'the unrelated post appears in no pair' );
ok( ! in_array( 6, $ids_in_pairs, true ) && ! in_array( 7, $ids_in_pairs, true ), 'empty and whitespace-only bodies never pair' );
ok( ! in_array( 12, $ids_in_pairs, true ), 'a markup-only body (non-empty hash, zero tokens) never pairs' );
ok( ! in_array( 10, $ids_in_pairs, true ) && ! in_array( 11, $ids_in_pairs, true ), 'trash and non-post types never enter the walk' );

// ─── Threshold boundaries + clamping ─────────────────────────────────
$high = snt_ml_cousin_pairs( 0.95 );
ok( 0.95 === $high['threshold'] && 1 === $high['pair_count'], 'threshold 0.95 keeps only the cosine-1.0 pair' );
ok( array( 3, 4 ) === pair_ids( $high['pairs'][0] ), 'the surviving pair at 0.95 is the permuted pair' );
$lo_clamp = snt_ml_cousin_pairs( 0.1 );
ok( 0.3 === $lo_clamp['threshold'], 'threshold 0.1 clamps UP to the 0.3 floor' );
ok( 2 === $lo_clamp['pair_count'], 'clamped floor still finds exactly the two designed pairs (partitioned vocab: cross-group cosine is 0)' );
$hi_clamp = snt_ml_cousin_pairs( 5 );
ok( 0.95 === $hi_clamp['threshold'] && 1 === $hi_clamp['pair_count'], 'threshold 5 clamps DOWN to the 0.95 ceiling' );

// ─── Pipeline registry: 'near-duplicates' ships next to 'related' ────
$map = snt_ml_pipelines();
ok( isset( $map['near-duplicates'] ) && 'snt_ml_pipeline_near_duplicates' === $map['near-duplicates'],
	"'near-duplicates' ships in the registry, bound to snt_ml_pipeline_near_duplicates" );
ok( isset( $map['related'] ), "'related' survives — the map is extended, not replaced" );
$piped = snt_ml_run( 'near-duplicates', array() );
ok( is_array( $piped ) && 2 === $piped['pair_count'] && 0.6 === $piped['threshold'], 'snt_ml_run with no args applies the 0.6 default' );
$piped_high = snt_ml_run( 'near-duplicates', array( 'threshold' => 0.95 ) );
ok( 1 === $piped_high['pair_count'], 'snt_ml_run threads the threshold through the wrapper' );
$piped_str = snt_ml_run( 'near-duplicates', array( 'threshold' => '0.95' ) );
ok( 1 === $piped_str['pair_count'] && 0.95 === $piped_str['threshold'], 'a numeric STRING threshold is accepted (is_numeric, then cast)' );
$piped_junk = snt_ml_run( 'near-duplicates', array( 'threshold' => 'wide-open' ) );
ok( 0.6 === $piped_junk['threshold'] && 2 === $piped_junk['pair_count'],
	'a non-numeric threshold falls back to the default — never (float)-cast to 0.0 and clamped into a surprise widening' );

// ─── Ability registration + wrapper delegation ───────────────────────
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$a = $GLOBALS['__abilities']['signal-noise/near-duplicate-scan'] ?? null;
ok( is_array( $a ), 'signal-noise/near-duplicate-scan is registered' );
ok( 'snt_ability_perm_read_corpus' === ( $a['permission_callback'] ?? '' ), 'ability gates on edit_others_posts (corpus READ tier)' );
ok( 'tools' === ( $a['category'] ?? '' ), 'ability sits in the tools category' );
ok( true === ( $a['meta']['annotations']['readonly'] ?? false )
	&& false === ( $a['meta']['annotations']['destructive'] ?? true )
	&& true === ( $a['meta']['annotations']['idempotent'] ?? false ), 'annotated readonly + non-destructive + idempotent' );
ok( array( 'object', 'null' ) === ( $a['input_schema']['type'] ?? null ), 'input type is [object, null] — the bodyless-GET contract' );
ok( array( 'threshold' ) === array_keys( $a['input_schema']['properties'] ?? array() ),
	'threshold is the ONLY input — no post_type param this release' );
$t = $a['input_schema']['properties']['threshold'] ?? array();
ok( 'number' === ( $t['type'] ?? '' ) && 0.3 === ( $t['minimum'] ?? 0 ) && 0.95 === ( $t['maximum'] ?? 0 ) && 0.6 === ( $t['default'] ?? 0 ),
	'threshold schema pins number 0.3..0.95 default 0.6' );
ok( false === ( $a['input_schema']['additionalProperties'] ?? true ), 'input schema rejects unknown properties' );
$out_keys = array_keys( $a['output_schema']['properties'] ?? array() );
ok( array( 'ok', 'pairs', 'pair_count', 'threshold', 'posts_scanned', 'truncated', 'scanned_at' ) === $out_keys,
	'output schema documents the exact envelope keys' );

// Wrapper delegation: null input (bodyless GET) and an explicit threshold,
// routed through the pipeline registry.
$w = snt_ability_corpus_near_duplicate_scan( null );
ok( is_array( $w ) && 2 === $w['pair_count'] && 0.6 === $w['threshold'], 'wrapper on null input (bodyless GET) runs the default scan' );
$w = snt_ability_corpus_near_duplicate_scan( array( 'threshold' => 0.95 ) );
ok( is_array( $w ) && 1 === $w['pair_count'] && 0.95 === $w['threshold'], 'wrapper threads an explicit threshold through snt_ml_run' );
ok( array( 3, 4 ) === pair_ids( $w['pairs'][0] ), 'ability output carries the same pair shape as the impl' );

echo "\nGroup: no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings/deprecations raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
