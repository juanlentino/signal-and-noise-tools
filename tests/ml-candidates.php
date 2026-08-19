<?php
/**
 * Standalone tests for the candidate generators (v10.17.0):
 * inc/ml-candidates.php + the 'extract-keywords' / 'link-candidates'
 * pipeline registrations + the two corpus abilities.
 *
 * Fixture design notes (per the repo's stub-drift + echo-testing rules):
 *   - Every keyword weight below is HAND-DERIVED from the formula
 *     (idf = ln((N+1)/(df+1)) + 1; weight = tf*idf / L2-norm) with the
 *     arithmetic worked out INDEPENDENTLY of the kernel — the fixture never
 *     recomputes a weight from the SUT. Full derivation at the constants.
 *   - The keyword corpus and the link corpus are SEPARATE registries (the
 *     registry is swapped between phases): keyword weights depend on N and
 *     df across the whole walk, so extra fixture posts would silently move
 *     every pinned constant.
 *   - get_posts() FILTERS by post_type + post_status + posts_per_page;
 *     get_post_meta() returns '' for absent single meta; get_option()
 *     returns the $default — core's real failure shapes.
 *   - The not-built 503 is asserted BEFORE the corpus-meta option is
 *     stamped, against get_option's real absent shape.
 *
 * Run: php tests/ml-candidates.php
 * @since plugin v10.17.0 (ML pipeline #3)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'HOUR_IN_SECONDS', 3600 );

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
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '' ) { return 'https://example.test' . $path; } }

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}
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
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; } // Core: null for unknown.
}
if ( ! function_exists( 'get_permalink' ) ) {
	// Core resolves the REAL permalink from rewrite rules; the fixture models
	// only its contract surface used here: string URL for a known post, false
	// for an unknown one (core's shape). NOTE: the impl's call site only
	// reaches posts already validated by get_post(), so the false branch is
	// structurally unreachable there — the impl's (string) cast is defensive
	// PHP semantics, asserted nowhere because no input can drive it.
	function get_permalink( $id ) {
		$p = $GLOBALS['__posts'][ (int) $id ] ?? null;
		return $p ? 'https://example.test/notes/' . $p->post_name . '/' : false;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) {
		$p = $GLOBALS['__posts'][ (int) $id ] ?? null;
		return $p ? $p->post_status : false; // Core: false for an unknown ID.
	}
}
// Meta/options stores — core failure shapes: '' for absent single meta,
// $default for absent options (the not-built state reads THIS shape).
$GLOBALS['__meta']    = array();
$GLOBALS['__options'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		if ( ! isset( $GLOBALS['__meta'][ (int) $id ][ $key ] ) ) { return $single ? '' : array(); }
		return $GLOBALS['__meta'][ (int) $id ][ $key ];
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['__meta'][ (int) $id ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }
}
// Cron/transition collaborators ml-artifacts.php wires at load time.
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook ) { return false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $ts, $hook ) { return true; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $ts, $r, $hook ) { return true; } }
if ( ! function_exists( 'wp_is_post_revision' ) ) { function wp_is_post_revision( $id ) { return false; } }
if ( ! function_exists( 'wp_is_post_autosave' ) ) { function wp_is_post_autosave( $id ) { return false; } }
if ( ! function_exists( 'wp_get_post_terms' ) ) { function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); } }

// ─── Phase 1 registry: the KEYWORD corpus ────────────────────────────
//
// Stats walk = the three token-bearing posts {101, 102, 103} (104 is empty,
// 105 is markup-only tokenless, 106 is trash, 107 is a page — none enter).
// N = 3. Document frequencies:
//   music 1, provenance 1 (only 101) — df=1 ⇒ idf = ln(4/2)+1 = ln2+1
//   ledger 2 (101, 102), archive 2 (102, 103) — df=2 ⇒ idf = ln(4/3)+1
//   telescope 1 (103)
$GLOBALS['__posts'] = array(
	// Target. Raw word stream: music, provenance, ledger, of, music.
	// Surviving tokens (tf): music=2, provenance=1, ledger=1.
	// Qualifying bigrams (adjacent raw pairs, both members survive):
	//   (music,provenance) ✓  (provenance,ledger) ✓
	//   (ledger,of) ✗  (of,music) ✗  — 'of' is a stopword.
	101 => tf_post( 101, 'publish', "<!-- wp:paragraph -->\n<p>Music provenance ledger of music.</p>\n<!-- /wp:paragraph -->", array( 'title' => 'Target', 'slug' => 'target-note' ) ),
	102 => tf_post( 102, 'draft', '<p>Ledger archive.</p>' ),
	103 => tf_post( 103, 'future', '<p>Archive archive telescope.</p>' ),
	// Empty body: excluded from stats; as a TARGET → ok + [] (an ANSWER).
	104 => tf_post( 104, 'pending', '' ),
	// Markup-only tokenless body: same — zero lexical signal is an answer.
	105 => tf_post( 105, 'draft', '<!-- wp:spacer --><hr/><!-- /wp:spacer -->' ),
	// Outside the corpus entirely: trash status, non-'post' type.
	106 => tf_post( 106, 'trash', '<p>Music music music.</p>' ),
	107 => tf_post( 107, 'publish', '<p>Music page.</p>', array( 'post_type' => 'page' ) ),
);

// ─── Load the SUT (kernel is PURE — required directly, no stubs) ─────
require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-pipelines.php';
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/ml-artifacts.php';
require __DIR__ . '/../inc/ml-candidates.php';
require __DIR__ . '/../inc/abilities-corpus.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function terms_of( $res ) { return array_map( static function ( $c ) { return $c['term']; }, $res['candidates'] ); }

echo "Candidate generators (keyword + link) — plugin v10.17.0\n\n";

// ─── HAND-DERIVED keyword constants (worked from the formula, NOT the SUT) ──
//
//   idf(df=1) = ln(4/2)+1 = 1.6931471805599453   (a)
//   idf(df=2) = ln(4/3)+1 = 1.2876820724517808   (b)
//   Unnormalized target vector: music = 2a, provenance = a, ledger = b.
//   norm = sqrt(4a² + a² + b²) = sqrt(5a² + b²) = 3.9989826199802585
//   w_music     = 2a/norm = 0.84678896682…  → 0.8468 at 4dp
//   w_provenance =  a/norm = 0.42339448341…  → 0.4234
//   w_ledger     =  b/norm = 0.32200241781…  → 0.3220
//   bigram 'music provenance'  = (w_music + w_provenance) × 1.25
//                              = 1.58772931279…            → 1.5877
//   bigram 'provenance ledger' = (w_provenance + w_ledger) × 1.25
//                              = 0.93174612653…            → 0.9317
const T_W_MUSIC      = 0.8468;
const T_W_PROVENANCE = 0.4234;
const T_W_LEDGER     = 0.3220;
const T_W_BG_MUSPROV = 1.5877;
const T_W_BG_PROVLED = 0.9317;

// ─── Keyword ranking on the tiny corpus ──────────────────────────────
$kw = snt_ml_keyword_candidates( 101 );
ok( is_array( $kw ) && true === $kw['ok'], 'keyword scan returns ok envelope' );
ok( 101 === $kw['post_id'] && 8 === $kw['limit'], 'envelope echoes post_id and the default limit 8' );
ok( 5 === $kw['count'] && 5 === count( $kw['candidates'] ), 'exactly 5 candidates: 3 unigrams + 2 qualifying bigrams' );
ok( array( 'music provenance', 'provenance ledger', 'music', 'provenance', 'ledger' ) === terms_of( $kw ),
	'ranked weight-descending: both bigrams outrank every unigram, then music (tf 2), provenance, ledger' );
ok( array( 'term', 'weight' ) === array_keys( $kw['candidates'][0] ), 'candidate carries exactly term + weight' );

// Exact 4dp pins — the hand-derived constants above, never kernel-echoed.
ok( T_W_BG_MUSPROV === $kw['candidates'][0]['weight'], "bigram 'music provenance' weight === 1.5877 ((0.8468+0.4234-precise) x 1.25 boost)" );
ok( T_W_BG_PROVLED === $kw['candidates'][1]['weight'], "bigram 'provenance ledger' weight === 0.9317 (summed member tf-idf x 1.25)" );
ok( T_W_MUSIC === $kw['candidates'][2]['weight'], 'music weight === 0.8468 (tf 2 x idf ln2+1, L2-normalized)' );
ok( T_W_PROVENANCE === $kw['candidates'][3]['weight'], 'provenance weight === 0.4234 (tf 1, df 1)' );
ok( T_W_LEDGER === $kw['candidates'][4]['weight'], 'ledger weight === 0.3220 (tf 1, df 2 — corpus-rarer provenance outranks it at EQUAL tf: the corpus-aware point)' );
ok( $kw['candidates'][3]['weight'] > $kw['candidates'][4]['weight'],
	'idf separation: provenance (this post only) > ledger (also in the draft) despite identical tf' );

// Bigram members-must-survive rule: 'of' is a stopword, so neither raw pair
// it appears in qualifies, and NO bridged pair is fabricated across it.
$kw_terms = terms_of( $kw );
ok( ! in_array( 'ledger of', $kw_terms, true ) && ! in_array( 'of music', $kw_terms, true ),
	'a bigram with a non-surviving member never appears' );
ok( ! in_array( 'ledger music', $kw_terms, true ),
	'no bridging: dropping the stopword does not fabricate a "ledger music" phrase that never appears in the text' );

// PR #412 review (MEDIUM, fixed): sentence/tag/comma boundaries must break
// adjacency too — the reviewer's exact probe. Before the fix this body
// yielded the fabricated bigrams 'here provenance', 'begins ledger', and
// 'ledger music'; the real phrase 'music follows' must still qualify.
$GLOBALS['__posts'][107] = tf_post( 107, 'publish', '<p>It ends here. Provenance begins.</p><h2>Ledger</h2><p>Music follows music follows.</p>' );
$probe   = snt_ml_keyword_candidates( 107, 20 );
$p_terms = terms_of( $probe );
ok( ! in_array( 'here provenance', $p_terms, true )
	&& ! in_array( 'begins ledger', $p_terms, true )
	&& ! in_array( 'ledger music', $p_terms, true ),
	'boundary probe: no bigram is fabricated across sentence-final punctuation or a tag boundary' );
ok( in_array( 'music follows', $p_terms, true ),
	'boundary probe: a genuinely adjacent in-sentence pair still forms its bigram' );
unset( $GLOBALS['__posts'][107] );

// ─── Limit clamps (observable via the envelope echo + count) ─────────
$kw2 = snt_ml_keyword_candidates( 101, 2 );
ok( 2 === $kw2['limit'] && 2 === $kw2['count'] && array( 'music provenance', 'provenance ledger' ) === terms_of( $kw2 ),
	'limit 2 keeps the top two candidates' );
$kw0 = snt_ml_keyword_candidates( 101, 0 );
ok( 1 === $kw0['limit'] && 1 === $kw0['count'], 'limit 0 clamps UP to the floor 1' );
$kw100 = snt_ml_keyword_candidates( 101, 100 );
ok( 20 === $kw100['limit'] && 5 === $kw100['count'], 'limit 100 clamps DOWN to the 20 ceiling (all 5 candidates fit)' );

// ─── No-post 404 vs empty-body [] — different answers ────────────────
$e = snt_ml_keyword_candidates( 999 );
ok( is_wp_error( $e ) && 'snt_ml_no_post' === $e->get_error_code() && 404 === ( $e->get_error_data()['status'] ?? 0 ),
	'unknown post ID is snt_ml_no_post, 404-shaped' );
$e = snt_ml_keyword_candidates( 106 );
ok( is_wp_error( $e ) && 'snt_ml_no_post' === $e->get_error_code(), 'a TRASH post is outside the corpus: 404, never scanned' );
$e = snt_ml_keyword_candidates( 107 );
ok( is_wp_error( $e ) && 'snt_ml_no_post' === $e->get_error_code(), 'a non-post type (page) is outside the corpus: 404' );
$empty = snt_ml_keyword_candidates( 104 );
ok( is_array( $empty ) && true === $empty['ok'] && array() === $empty['candidates'] && 0 === $empty['count'],
	'an EMPTY body is ok + zero candidates — an empty body is an ANSWER, not an error' );
$markup = snt_ml_keyword_candidates( 105 );
ok( is_array( $markup ) && true === $markup['ok'] && 0 === $markup['count'],
	'a markup-only tokenless body is the same empty ANSWER' );

// ─── Pipeline registry: both new slugs ship next to the existing two ─
$map = snt_ml_pipelines();
ok( isset( $map['extract-keywords'] ) && 'snt_ml_pipeline_extract_keywords' === $map['extract-keywords'],
	"'extract-keywords' ships in the registry, bound to snt_ml_pipeline_extract_keywords" );
ok( isset( $map['link-candidates'] ) && 'snt_ml_pipeline_link_candidates' === $map['link-candidates'],
	"'link-candidates' ships in the registry, bound to snt_ml_pipeline_link_candidates" );
ok( isset( $map['related'] ) && isset( $map['near-duplicates'] ), 'related + near-duplicates survive — the map is extended, not replaced' );

$piped = snt_ml_run( 'extract-keywords', array( 'post_id' => 101 ) );
ok( is_array( $piped ) && 5 === $piped['count'] && 'music provenance' === $piped['candidates'][0]['term'],
	'snt_ml_run routes extract-keywords to the impl (default limit 8)' );
$piped2 = snt_ml_run( 'extract-keywords', array( 'post_id' => 101, 'limit' => 2 ) );
ok( 2 === $piped2['count'] && 2 === $piped2['limit'], 'snt_ml_run threads the limit through the wrapper' );
$bad = snt_ml_run( 'extract-keywords', array() );
ok( is_wp_error( $bad ) && 'snt_ml_invalid_args' === $bad->get_error_code() && 400 === ( $bad->get_error_data()['status'] ?? 0 ),
	'missing post_id is snt_ml_invalid_args, 400-shaped' );
$bad = snt_ml_run( 'link-candidates', array( 'post_id' => 0 ) );
ok( is_wp_error( $bad ) && 'snt_ml_invalid_args' === $bad->get_error_code(), 'link-candidates pipeline rejects a non-positive post_id' );

// ─── Ability registration (capture wp_abilities_api_init) ────────────
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

foreach ( array( 'signal-noise/keyword-candidates', 'signal-noise/link-candidates' ) as $slug ) {
	$a = $GLOBALS['__abilities'][ $slug ] ?? null;
	ok( is_array( $a ), "$slug is registered" );
	ok( 'snt_ability_perm_read_corpus' === ( $a['permission_callback'] ?? '' ), "$slug gates on edit_others_posts (corpus READ tier)" );
	ok( 'tools' === ( $a['category'] ?? '' ), "$slug sits in the tools category" );
	ok( true === ( $a['meta']['annotations']['readonly'] ?? false )
		&& false === ( $a['meta']['annotations']['destructive'] ?? true )
		&& true === ( $a['meta']['annotations']['idempotent'] ?? false ), "$slug annotated readonly + non-destructive + idempotent" );
	// post_id is REQUIRED ⇒ the input type is plain 'object': the
	// [object,null] union is reserved for no-required-fields abilities
	// (tests/abilities-categories.php Group E — a required list makes a
	// bodyless call invalid anyway).
	ok( 'object' === ( $a['input_schema']['type'] ?? null ), "$slug input type is plain 'object' — post_id is required, so no null union" );
	ok( array( 'post_id' ) === ( $a['input_schema']['required'] ?? array() ), "$slug requires exactly post_id" );
	ok( false === ( $a['input_schema']['additionalProperties'] ?? true ), "$slug input schema rejects unknown properties" );
	ok( array( 'ok', 'post_id', 'candidates', 'count', 'limit' ) === array_keys( $a['output_schema']['properties'] ?? array() ),
		"$slug output schema documents the exact envelope keys" );
}
$kw_lim = $GLOBALS['__abilities']['signal-noise/keyword-candidates']['input_schema']['properties']['limit'] ?? array();
ok( 1 === ( $kw_lim['minimum'] ?? 0 ) && 20 === ( $kw_lim['maximum'] ?? 0 ) && 8 === ( $kw_lim['default'] ?? 0 ),
	'keyword-candidates limit schema pins 1..20 default 8' );
$ln_lim = $GLOBALS['__abilities']['signal-noise/link-candidates']['input_schema']['properties']['limit'] ?? array();
ok( 1 === ( $ln_lim['minimum'] ?? 0 ) && 10 === ( $ln_lim['maximum'] ?? 0 ) && 5 === ( $ln_lim['default'] ?? 0 ),
	'link-candidates limit schema pins 1..10 default 5' );

// Keyword ability wrapper delegates through snt_ml_run.
$w = snt_ability_corpus_keyword_candidates( array( 'post_id' => 101, 'limit' => 2 ) );
ok( is_array( $w ) && 2 === $w['count'] && 'music provenance' === $w['candidates'][0]['term'],
	'keyword ability wrapper threads post_id + limit through snt_ml_run' );
$w = snt_ability_corpus_keyword_candidates( array( 'post_id' => 999 ) );
ok( is_wp_error( $w ) && 'snt_ml_no_post' === $w->get_error_code(), 'keyword ability wrapper surfaces the 404 verbatim' );

// ─── Phase 2 registry: the LINK corpus (swapped wholesale — keyword ─
// weights above depend on N/df, so the two fixtures must never mix). ─
$GLOBALS['__posts'] = array(
	// Target body already links /notes/already-linked/ (absolute, home_url
	// form — the same shapes snt_ml_extract_note_links handles for the build).
	201 => tf_post( 201, 'publish', '<!-- wp:paragraph --><p>See <a href="https://example.test/notes/already-linked/">that note</a>.</p><!-- /wp:paragraph -->', array( 'title' => 'Link target', 'slug' => 'link-target' ) ),
	202 => tf_post( 202, 'publish', '<p>A</p>', array( 'title' => 'Already linked', 'slug' => 'already-linked' ) ),
	203 => tf_post( 203, 'draft', '<p>B</p>', array( 'title' => 'Unpublished', 'slug' => 'unpub-target' ) ),
	204 => tf_post( 204, 'publish', '<p>C</p>', array( 'title' => 'Fresh', 'slug' => 'fresh-target' ) ),
	205 => tf_post( 205, 'publish', '<p>D</p>', array( 'title' => 'Second fresh', 'slug' => 'second-fresh' ) ),
);

// ─── Not built FIRST: option still absent (get_option default shape) ─
$nb = snt_ml_link_candidates( 201 );
ok( is_wp_error( $nb ) && 'snt_ml_not_built' === $nb->get_error_code() && 503 === ( $nb->get_error_data()['status'] ?? 0 ),
	'unbuilt artifacts are snt_ml_not_built, 503-shaped — the related pipeline contract' );

// Build state: stamp the corpus option + the target's related meta. The
// related rows deliberately outrank the survivors with EXCLUDED targets, so
// a naive top-5 slice would return the wrong set.
$GLOBALS['__options'][ SNT_ML_CORPUS_META_OPT ] = array( 'fingerprint' => 'f', 'built_at' => 1753800000, 'posts' => 5 );
$GLOBALS['__meta'][201][ SNT_ML_RELATED_META ]  = array(
	array( 'post_id' => 202, 'score' => 0.9 ),  // already linked → excluded
	array( 'post_id' => 203, 'score' => 0.8 ),  // draft → excluded
	array( 'post_id' => 204, 'score' => 0.7 ),
	array( 'post_id' => 205, 'score' => 0.6 ),
);

$lc = snt_ml_link_candidates( 201 );
ok( is_array( $lc ) && true === $lc['ok'] && 201 === $lc['post_id'] && 5 === $lc['limit'], 'link scan returns ok envelope with the default limit 5' );
ok( 2 === $lc['count'] && 2 === count( $lc['candidates'] ), 'exactly the two publishable, not-yet-linked targets survive' );
ok( array( 'post_id', 'title', 'slug', 'url', 'score' ) === array_keys( $lc['candidates'][0] ), 'candidate carries exactly post_id/title/slug/url/score (v10.19.0: + url — the UI must never derive a path from the slug)' );
ok( 'https://example.test/notes/fresh-target/' === $lc['candidates'][0]['url'], 'url is the resolved permalink, not a hand-built path' );
ok( 204 === $lc['candidates'][0]['post_id'] && 'Fresh' === $lc['candidates'][0]['title'] && 'fresh-target' === $lc['candidates'][0]['slug'] && 0.7 === $lc['candidates'][0]['score'],
	'top candidate is the 0.9-outranked fresh target — exclusions open slots, never shorten the answer' );
ok( 205 === $lc['candidates'][1]['post_id'] && 0.6 === $lc['candidates'][1]['score'], 'second candidate follows score-descending' );
$lc_ids = array_map( static function ( $c ) { return $c['post_id']; }, $lc['candidates'] );
ok( ! in_array( 202, $lc_ids, true ), 'the ALREADY-LINKED target (top related score 0.9!) is excluded — its absence proves the href subtraction' );
ok( ! in_array( 203, $lc_ids, true ), 'the UNPUBLISHED target (score 0.8) is excluded — never propose a link a reader cannot reach' );

$lc1 = snt_ml_link_candidates( 201, 1 );
ok( 1 === $lc1['limit'] && 1 === $lc1['count'] && 204 === $lc1['candidates'][0]['post_id'], 'limit 1 keeps only the top survivor' );
$lc0 = snt_ml_link_candidates( 201, 0 );
ok( 1 === $lc0['limit'], 'limit 0 clamps UP to the floor 1' );
$lc99 = snt_ml_link_candidates( 201, 99 );
ok( 10 === $lc99['limit'], 'limit 99 clamps DOWN to the artifact depth 10' );

$e = snt_ml_link_candidates( 999 );
ok( is_wp_error( $e ) && 'snt_ml_no_post' === $e->get_error_code() && 404 === ( $e->get_error_data()['status'] ?? 0 ),
	'unknown post ID is snt_ml_no_post 404 (checked before the artifact read)' );

// A post with no meta row under a STAMPED corpus is a real empty answer.
$fresh = snt_ml_link_candidates( 204 );
ok( is_array( $fresh ) && true === $fresh['ok'] && 0 === $fresh['count'] && array() === $fresh['candidates'],
	'no meta row under a stamped corpus reads as ok + [] — an empty ANSWER, never re-conflated with not-built' );

// Pipeline + ability wrapper delegation for the link surface.
$piped = snt_ml_run( 'link-candidates', array( 'post_id' => 201 ) );
ok( is_array( $piped ) && 2 === $piped['count'] && 204 === $piped['candidates'][0]['post_id'], 'snt_ml_run routes link-candidates to the impl' );
$w = snt_ability_corpus_link_candidates( array( 'post_id' => 201, 'limit' => 1 ) );
ok( is_array( $w ) && 1 === $w['count'] && 204 === $w['candidates'][0]['post_id'], 'link ability wrapper threads post_id + limit through snt_ml_run' );
$w = snt_ability_corpus_link_candidates( null );
ok( is_wp_error( $w ) && 'snt_ml_invalid_args' === $w->get_error_code(), 'link ability wrapper on null input surfaces the 400 (post_id is required upstream anyway)' );

echo "\nGroup: no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings/deprecations raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
