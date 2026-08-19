<?php
/**
 * Draft-time echoes (v10.77.0): inc/ml-draft-echoes.php + the 'draft-echoes'
 * pipeline registration + the signal-noise/draft-echoes ability.
 *
 * Fixture design follows tests/ml-cousins.php's rules, which matter more here
 * than usual:
 *
 *   - NO ECHO-TESTING. Cosine expectations are hand-derived from the fixture's
 *     construction, never recomputed by calling the kernel and asserting the
 *     answer equals itself. The strong pin is the permuted-body trick: two
 *     bodies with the SAME token multiset in a different byte order produce
 *     term-for-term identical TF-IDF vectors whatever the idf values are, so
 *     their cosine is exactly 1.0. Everything else is pinned by ORDERING and
 *     threshold-boundary COUNTS, which are properties of the algorithm rather
 *     than of a particular arithmetic result.
 *   - Vocabulary is PARTITIONED between groups, so cross-group cosine is 0 and
 *     every echo reported is one this fixture deliberately built.
 *
 * The three behaviours the row is judged on:
 *   1. a draft similar to an existing note surfaces it;
 *   2. a novel draft surfaces NOTHING, not the least-bad match in the corpus;
 *   3. the computation never runs for a non-editing request.
 *
 * @since plugin v10.77.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

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

// Group A vocabulary (espresso) and Group B vocabulary (cartography) share no
// terms, so an A-draft can only ever echo A-posts.
$A_BODY = '<p>Espresso ritual crema tamp grind.</p>';
$A_PERM = '<p>Grind tamp crema ritual espresso.</p>'; // same multiset, different bytes.

$GLOBALS['__posts'] = array(
	1 => tf_post( 1, 'publish', $A_BODY, array( 'title' => 'On espresso', 'slug' => 'on-espresso' ) ),
	2 => tf_post( 2, 'publish', '<p>Kettle pour bloom filter scale timer.</p>', array( 'title' => 'On pour-over', 'slug' => 'on-pour-over' ) ),
	3 => tf_post( 3, 'publish', '<p>Zeppelin cartography whalesong meridian.</p>', array( 'title' => 'On maps', 'slug' => 'on-maps' ) ),
	// The draft being written. It IS in the corpus already — a saved draft is
	// walked by snt_corpus_fetch_posts( 'any', ... ) — which is exactly why the
	// impl must exclude it from its own comparison set.
	4 => tf_post( 4, 'draft', $A_PERM, array( 'title' => 'Espresso, again', 'slug' => 'espresso-again' ) ),
	5 => tf_post( 5, 'draft', '', array( 'title' => 'Blank' ) ),
	// PARTIAL overlap with the draft: 4 of 5 tokens shared, one unique each
	// side. Deliberately similar-but-not-identical, so that at a low floor
	// there are TWO qualifying echoes and a limit of 1 genuinely truncates —
	// without it the truncation assertion would pass vacuously (nothing to cut).
	8 => tf_post( 8, 'publish', '<p>Espresso crema tamp grind machine.</p>', array( 'title' => 'Machine notes', 'slug' => 'machine-notes' ) ),
	6 => tf_post( 6, 'trash', $A_BODY ),
	7 => tf_post( 7, 'publish', $A_BODY, array( 'post_type' => 'page' ) ),
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

require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-pipelines.php';
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/ml-cousins.php';
require __DIR__ . '/../inc/ml-draft-echoes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function echo_ids( $r ) { return array_map( static function ( $e ) { return (int) $e['post_id']; }, $r['echoes'] ); }

echo "Draft-time echoes — plugin v10.77.0\n\n";

/* ═══════════════════════════════════════════════════════════════════
 * 1. A DRAFT SIMILAR TO AN EXISTING NOTE SURFACES IT
 * ═══════════════════════════════════════════════════════════════════ */

$r = snt_ml_draft_echoes( 4 );
ok( is_array( $r ) && true === $r['ok'], 'returns an ok envelope' );
ok( in_array( 1, echo_ids( $r ), true ), 'the draft surfaces the note it echoes (post 1)' );

// HAND-DERIVED, not recomputed: post 4 is post 1's token multiset in a
// different byte order, so their TF-IDF vectors are term-for-term identical
// whatever the corpus idf turns out to be ⇒ cosine is exactly 1.0.
ok( 1.0 === $r['echoes'][0]['cosine'] && 1 === $r['echoes'][0]['post_id'],
	'top echo is the permuted-body note at cosine exactly 1.0 (identical token multiset)' );

ok( ! in_array( 4, echo_ids( $r ), true ),
	'THE SELF-MATCH TRAP: a SAVED draft is in the corpus, and it must not echo itself at 1.0' );

ok( ! in_array( 3, echo_ids( $r ), true ),
	'a disjoint-vocabulary note (post 3) is not surfaced' );

ok( array( 'post_id', 'title', 'slug', 'status', 'cosine' ) === array_keys( $r['echoes'][0] ),
	'an echo carries post_id / title / slug / status / cosine' );
ok( 'On espresso' === $r['echoes'][0]['title'] && 'on-espresso' === $r['echoes'][0]['slug'],
	'the echo names the note so the writer can go read it' );

/* ═══════════════════════════════════════════════════════════════════
 * 2. A NOVEL DRAFT SURFACES NOTHING — NOT THE LEAST-BAD MATCH
 * ═══════════════════════════════════════════════════════════════════ */

$novel = snt_ml_draft_echoes( 0, '<p>Tectonic subduction basalt rifting magnetometer.</p>' );
ok( true === $novel['ok'] && array() === $novel['echoes'] && 0 === $novel['echo_count'],
	'A NOVEL DRAFT SURFACES NOTHING — the corpus is non-empty and every candidate was rejected' );
ok( $novel['posts_compared'] > 0,
	'...and it really did compare against a populated corpus (a zero here would make the assertion above vacuous)' );

// The same draft with the floor dropped to the minimum still finds nothing,
// because the vocabularies are disjoint: cosine 0, not merely "below 0.45".
$novel_low = snt_ml_draft_echoes( 0, '<p>Tectonic subduction basalt rifting magnetometer.</p>', 0.3 );
ok( array() === $novel_low['echoes'],
	'even at the 0.3 floor a genuinely unrelated draft surfaces nothing' );

/* ═══════════════════════════════════════════════════════════════════
 * 3. THRESHOLD, LIMIT AND ENVELOPE HONESTY
 * ═══════════════════════════════════════════════════════════════════ */

ok( 0.45 === snt_ml_draft_echoes( 4 )['threshold'],
	'the default echo threshold (0.45) sits BELOW the 0.6 cousin bar and is echoed in the envelope' );
ok( 0.3 === snt_ml_draft_echoes( 4, null, 0.01 )['threshold']
	&& 0.95 === snt_ml_draft_echoes( 4, null, 9.9 )['threshold'],
	'the threshold is clamped to the shared cousin bounds, so the two surfaces cannot drift apart' );

$one = snt_ml_draft_echoes( 4, null, 0.3, 1 );
ok( 1 === $one['echo_count'] && 'truncated_to_limit' === $one['reason'],
	'a limit that HIDES rows says so in reason — a silent cap reads as "that is all there was"' );
ok( '' === snt_ml_draft_echoes( 4, null, 0.3, 5 )['reason'],
	'no truncation → an empty reason, not a stale flag' );

$sorted = snt_ml_draft_echoes( 4, null, 0.3, 5 );
$cosines = array_map( static function ( $e ) { return $e['cosine']; }, $sorted['echoes'] );
$desc = $cosines;
rsort( $desc );
ok( $cosines === $desc, 'echoes are ordered most-similar first' );

/* ═══════════════════════════════════════════════════════════════════
 * 4. NULL vs EMPTY STRING — different questions, different answers
 * ═══════════════════════════════════════════════════════════════════ */

$saved = snt_ml_draft_echoes( 4, null );
ok( in_array( 1, echo_ids( $saved ), true ),
	'content=null reads the SAVED body (the panel works before the first autosave)' );

$blank = snt_ml_draft_echoes( 4, '' );
ok( array() === $blank['echoes'] && 'no_lexical_signal' === $blank['reason'],
	'content="" is a REAL value meaning the editor is empty — answered as no_lexical_signal, not by falling back to the saved body' );

$markup = snt_ml_draft_echoes( 4, '<!-- wp:spacer --><hr/><!-- /wp:spacer -->' );
ok( 'no_lexical_signal' === $markup['reason'],
	'a markup-only body is undefined-cosine, reported as such rather than as a confident "nothing echoes this"' );

/* ═══════════════════════════════════════════════════════════════════
 * 5. THE COMPUTATION NEVER RUNS FOR A NON-EDITING REQUEST
 * ═══════════════════════════════════════════════════════════════════ */

$module = file_get_contents( __DIR__ . '/../inc/ml-draft-echoes.php' );
ok( false === strpos( $module, 'add_action' ) && false === strpos( $module, 'add_filter' ),
	'the module registers NO hooks at all — it cannot fire on a front-end request because nothing invites it to' );

$render = file_get_contents( __DIR__ . '/../inc/ml-related-render.php' );
ok( false === strpos( $render, 'snt_ml_draft_echoes' ) && false === strpos( $render, 'draft-echoes' ),
	'the READER render path never references the draft-echo surface (the ML family standing never)' );

$abilities = file_get_contents( __DIR__ . '/../inc/abilities-corpus.php' );
ok( false !== strpos( $abilities, "'signal-noise/draft-echoes'" )
	&& preg_match( "/'signal-noise\/draft-echoes'.*?'permission_callback'\s*=>\s*'snt_ability_perm_read_corpus'/s", $abilities ),
	// v11.21.0: the level dropped, the CLAIM did not. Abilities are
	// REST-reachable and the permission_callback is the only gate, so this
	// assertion still says "gated" — now at edit_others_posts, which is Editor
	// and above and neither Author nor Contributor.
	'the only door to it is a gated ability (corpus READ tier) — REST-reachable, but gated' );
ok( preg_match( "/'signal-noise\/draft-echoes'.*?'readonly'\s*=>\s*true/s", $abilities )
	&& preg_match( "/'signal-noise\/draft-echoes'.*?'destructive'\s*=>\s*false/s", $abilities ),
	'the ability is annotated readonly and non-destructive' );

/* ═══════════════════════════════════════════════════════════════════
 * 6. PIPELINE REGISTRATION
 * ═══════════════════════════════════════════════════════════════════ */

$pipelines = snt_ml_pipelines();
ok( isset( $pipelines['draft-echoes'] ) && 'snt_ml_pipeline_draft_echoes' === $pipelines['draft-echoes'],
	"'draft-echoes' is registered in the ML pipeline registry (the single dispatch seam)" );

$via = snt_ml_run( 'draft-echoes', array( 'post_id' => 4 ) );
ok( is_array( $via ) && in_array( 1, echo_ids( $via ), true ),
	'the pipeline reaches the impl and returns the same envelope' );

$bad = snt_ml_run( 'draft-echoes', array() );
ok( is_wp_error( $bad ) && 400 === ( $bad->get_error_data()['status'] ?? 0 ),
	'neither post_id nor content → 400, not a silent whole-corpus scan against an empty draft' );

$garbage = snt_ml_run( 'draft-echoes', array( 'post_id' => 4, 'threshold' => 'abc' ) );
ok( 0.45 === $garbage['threshold'],
	'a non-numeric threshold falls back to the DEFAULT — (float) "abc" is 0.0, which would clamp to 0.3 and silently widen the scan' );

$empty_content = snt_ml_run( 'draft-echoes', array( 'content' => '' ) );
ok( is_array( $empty_content ) && 'no_lexical_signal' === $empty_content['reason'],
	'content="" alone is a valid request (array_key_exists, not isset — isset would have called it absent and 400d)' );

ok( array() === $GLOBALS['__php_errors'],
	'no PHP notices, warnings or deprecations were raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
