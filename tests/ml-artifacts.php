<?php
/**
 * Tests for the ML artifact layer (inc/ml-artifacts.php) and the reader-facing
 * Related notes section (inc/ml-related-render.php), driven through the REAL
 * kernel (inc/ml-kernel.php) and the REAL corpus walk (inc/corpus-inspect.php)
 * — no stubbed arithmetic, so the ranked orders asserted here are the shipped
 * orders.
 *
 * Stub-fidelity notes (per the repo's stub-drift rules):
 *   - get_posts() FILTERS the fixture registry by post_type + post_status and
 *     applies posts_per_page — the transport's transform, not just the call.
 *   - get_post_meta( $id, $key, true ) returns '' when the key is ABSENT —
 *     core's failure shape; the reader must read '' as "not indexed", never
 *     as "not built".
 *   - get_option() returns the $default (false) when unset — the not-built
 *     state is asserted against that real shape, BEFORE any build runs.
 *   - Failure shapes modeled: unbuilt artifacts (null), post unpublished
 *     since build (read-time gate), empty corpus (an ANSWER: ok, posts:0),
 *     malformed meta rows (skipped, never fabricated).
 *
 * Run: php tests/ml-artifacts.php
 * @since plugin v10.15.0 (SN ML kernel, stage 2: artifacts + render)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SNT_PATH', __DIR__ . '/../' );
define( 'SNT_VERSION', 'test' );

error_reporting( E_ALL );
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
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '' ) { return 'https://example.test' . $path; } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $rel, $file ) { return 'https://example.test/plugin/' . $rel; } }

// Registry-aware filter harness (pattern: tests/ml-kernel.php).
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) {
		foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); }
		return $v;
	}
}
function add_test_filter( $h, $cb ) { $GLOBALS['__filters'][ $h ][] = $cb; }
function clear_test_filter( $h ) { unset( $GLOBALS['__filters'][ $h ] ); }

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}

// Fixture registry — WP_Post field names, real shapes.
function tf_post( $id, $status, $content, $extra = array() ) {
	$p = new stdClass();
	$p->ID            = $id;
	$p->post_title    = $extra['title'] ?? "Post $id";
	$p->post_name     = $extra['slug'] ?? "post-$id";
	$p->post_status   = $status;
	$p->post_type     = $extra['post_type'] ?? 'post';
	$p->post_date     = '2026-07-0' . min( 9, $id ) . ' 10:00:00';
	$p->post_modified = $extra['modified'] ?? '2026-07-2' . min( 9, $id ) . ' 10:00:00';
	$p->post_content  = $content;
	$p->post_excerpt  = '';
	return $p;
}

// Two topic clusters. 1↔2 share vocabulary + a tag + a direct link;
// 3↔4 share vocabulary + a direct link; 6 and 7 are isolated (unique
// vocabulary, no tags, no links) so their built answer is a REAL [].
$GLOBALS['__posts'] = array(
	1 => tf_post( 1, 'publish',
		'<!-- wp:paragraph --><p>Signal versus noise: attention filters ranking signal quality over noise volume. See <a href="https://example.test/notes/noise-note/">the noise note</a>.</p><!-- /wp:paragraph -->',
		array( 'title' => 'Signal', 'slug' => 'signal-note' ) ),
	2 => tf_post( 2, 'publish',
		'<!-- wp:paragraph --><p>Noise drowns signal when attention filters fail; ranking noise volume against signal quality.</p><!-- /wp:paragraph -->',
		array( 'title' => 'Noise & <em>attention</em>', 'slug' => 'noise-note' ) ),
	3 => tf_post( 3, 'publish',
		'<!-- wp:paragraph --><p>Mixing music: compression, reverb tails, and stereo width in the mix bus.</p><!-- /wp:paragraph -->',
		array( 'title' => 'Mixing', 'slug' => 'mixing-note' ) ),
	4 => tf_post( 4, 'publish',
		'<!-- wp:paragraph --><p>Mastering music after mixing: compression again, loudness, and <a href="/notes/mixing-note">the mixing note</a>.</p><!-- /wp:paragraph -->',
		array( 'title' => 'Mastering', 'slug' => 'mastering-note' ) ),
	5 => tf_post( 5, 'draft',
		'<!-- wp:paragraph --><p>Unfinished draft about signal noise attention — must never enter the corpus.</p><!-- /wp:paragraph -->',
		array( 'title' => 'Draft', 'slug' => 'draft-note' ) ),
	6 => tf_post( 6, 'publish',
		'<!-- wp:paragraph --><p>Sourdough hydration percentages and proofing schedules.</p><!-- /wp:paragraph -->',
		array( 'title' => 'Bread', 'slug' => 'bread-note' ) ),
	7 => tf_post( 7, 'publish',
		'<!-- wp:paragraph --><p>Bicycle drivetrain maintenance: chain wear, cassette replacement.</p><!-- /wp:paragraph -->',
		array( 'title' => 'Bikes', 'slug' => 'bike-note' ) ),
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
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) {
		$p = $GLOBALS['__posts'][ (int) $id ] ?? null;
		return $p ? $p->post_status : false; // Core: false for an unknown ID.
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $id, $tax, $args = array() ) {
		if ( 'post_tag' !== $tax ) { return array(); }
		$tags = array( 1 => array( 'attention' ), 2 => array( 'attention' ), 3 => array( 'music' ), 4 => array( 'music' ) );
		return $tags[ (int) $id ] ?? array();
	}
}

// Meta/options stores — core failure shapes: '' for absent meta (single),
// $default for absent options.
$GLOBALS['__meta']    = array();
$GLOBALS['__options'] = array();
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['__meta'][ (int) $id ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		if ( ! isset( $GLOBALS['__meta'][ (int) $id ][ $key ] ) ) { return $single ? '' : array(); }
		return $GLOBALS['__meta'][ (int) $id ][ $key ];
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }
}

// Cron recorders — single events and recurring events tracked separately so
// the two-hook design (never share a hook) is assertable.
$GLOBALS['__cron'] = array( 'single' => array(), 'recurring' => array() );
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		foreach ( array_merge( $GLOBALS['__cron']['single'], $GLOBALS['__cron']['recurring'] ) as $e ) {
			if ( $e['hook'] === $hook ) { return $e['ts']; }
		}
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['__cron']['single'][] = array( 'ts' => $ts, 'hook' => $hook ); return true; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $ts, $recurrence, $hook ) { $GLOBALS['__cron']['recurring'][] = array( 'ts' => $ts, 'recurrence' => $recurrence, 'hook' => $hook ); return true; }
}
$GLOBALS['__revision_ids'] = array();
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $id ) { return in_array( (int) $id, $GLOBALS['__revision_ids'], true ) ? 999 : false; }
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) { function wp_is_post_autosave( $id ) { return false; } }

// Front-end context flags + render collaborators.
$GLOBALS['__ctx'] = array( 'singular' => false, 'loop' => false, 'main' => false, 'the_id' => 0 );
if ( ! function_exists( 'is_singular' ) ) { function is_singular( $t = '' ) { return $GLOBALS['__ctx']['singular']; } }
if ( ! function_exists( 'in_the_loop' ) ) { function in_the_loop() { return $GLOBALS['__ctx']['loop']; } }
if ( ! function_exists( 'is_main_query' ) ) { function is_main_query() { return $GLOBALS['__ctx']['main']; } }
if ( ! function_exists( 'get_the_ID' ) ) { function get_the_ID() { return $GLOBALS['__ctx']['the_id']; } }
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) { $p = $GLOBALS['__posts'][ (int) $id ] ?? null; return $p ? $p->post_title : ''; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { $p = $GLOBALS['__posts'][ (int) $id ] ?? null; return $p ? 'https://example.test/notes/' . $p->post_name . '/' : ''; }
}
$GLOBALS['__enqueued'] = array();
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { $GLOBALS['__enqueued'][] = $handle; }
}

// ─── Load the SUTs (real kernel, real corpus walk) ───────────────────
require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/ml-pipelines.php';
require __DIR__ . '/../inc/ml-artifacts.php';
require __DIR__ . '/../inc/ml-related-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "ML artifacts + related render — plugin v10.15.0\n\n";

// ─── (a) Not built: the null answer, asserted BEFORE any build ───────
echo "Group (a): unbuilt artifacts\n";
ok( null === snt_ml_related_for_post( 1, 4 ), '(a) no corpus option → null (not built), never []' );
$unbuilt = snt_ml_run( 'related', array( 'post_id' => 1 ) );
ok( is_wp_error( $unbuilt ) && 'snt_ml_not_built' === $unbuilt->get_error_code(),
	'(a) pipeline maps the null to snt_ml_not_built (503-shaped)' );
$GLOBALS['__ctx'] = array( 'singular' => true, 'loop' => true, 'main' => true, 'the_id' => 1 );
ok( 'BODY' === snt_ml_related_render( 'BODY' ), '(a) render is SILENT on unbuilt artifacts (reader surface, not an error surface)' );
$GLOBALS['__ctx']['singular'] = false;

// ─── (b) Internal-link extraction ────────────────────────────────────
echo "\nGroup (b): /notes/ href extraction\n";
$links = snt_ml_extract_note_links(
	'<a href="https://example.test/notes/alpha/">a</a> <a href="/notes/beta">b</a> '
	. '<a href="/notes/gamma/?utm=x#frag">c</a> <a href="https://other.host/notes/delta/">d</a> '
	. '<a href="/about/">e</a> <a href="/notes/alpha/">dupe</a>'
);
ok( array( 'alpha', 'beta', 'gamma' ) === $links,
	'(b) absolute + relative + query/fragment extracted as slugs; external host, non-notes path, and dupes dropped' );
ok( array() === snt_ml_extract_note_links( '' ), '(b) empty content → no links, no notices' );

// ─── (c) Full build ──────────────────────────────────────────────────
echo "\nGroup (c): snt_ml_build_corpus\n";
$env = snt_ml_build_corpus();
ok( is_array( $env ) && true === $env['ok'], '(c) envelope ok:true' );
ok( 6 === $env['posts'], '(c) 6 published posts walked (the draft never enters the corpus)' );
ok( 15 === $env['pairs'], '(c) all 15 pairs scored (6*5/2)' );
ok( is_int( $env['built_at'] ) && $env['built_at'] > 0, '(c) built_at stamped' );

$opt = get_option( SNT_ML_CORPUS_META_OPT );
ok( is_array( $opt ) && 32 === strlen( (string) $opt['fingerprint'] ), '(c) option carries a 32-char corpus fingerprint' );
ok( 6 === $opt['posts'] && is_int( $opt['built_at'] ), '(c) option carries posts + built_at' );

$m1 = get_post_meta( 1, SNT_ML_RELATED_META, true );
ok( is_array( $m1 ) && count( $m1 ) >= 1, '(c) post 1 got a related-rows meta artifact' );
ok( 2 === ( $m1[0]['post_id'] ?? 0 ), '(c) post 1\'s top match is post 2 (shared vocabulary + tag + direct link)' );
$self_free = true; $desc = true; $rounded = true;
$prev = PHP_FLOAT_MAX;
foreach ( $m1 as $row ) {
	if ( 1 === $row['post_id'] ) { $self_free = false; }
	if ( $row['score'] > $prev ) { $desc = false; }
	if ( $row['score'] !== round( $row['score'], 4 ) ) { $rounded = false; }
	$prev = $row['score'];
}
ok( $self_free, '(c) rows never contain the post itself' );
ok( $desc, '(c) rows are score-descending' );
ok( $rounded, '(c) scores are rounded to 4dp' );
$all_positive = true;
foreach ( $GLOBALS['__meta'] as $rows ) {
	foreach ( $rows[ SNT_ML_RELATED_META ] as $row ) {
		if ( $row['score'] <= 0 ) { $all_positive = false; }
	}
}
ok( $all_positive, '(c) zero-signal pairs are never stored — "related" always means a positive score' );
ok( array() === get_post_meta( 6, SNT_ML_RELATED_META, true ),
	'(c) the isolated post stores [] — a REAL "nothing related" answer' );

// ─── (d) The reader ──────────────────────────────────────────────────
echo "\nGroup (d): snt_ml_related_for_post\n";
$r1 = snt_ml_related_for_post( 1, 4 );
ok( is_array( $r1 ) && 2 === $r1[0]['post_id'] && is_float( $r1[0]['score'] ), '(d) reads the built rows, {post_id:int, score:float}' );
ok( 1 === count( snt_ml_related_for_post( 1, 0 ) ), '(d) limit 0 clamps to the floor of 1' );
ok( count( snt_ml_related_for_post( 1, 99 ) ) <= 10, '(d) limit 99 clamps to the cap of 10' );
ok( array() === snt_ml_related_for_post( 999, 4 ), '(d) unknown post under a stamped corpus → [] (not indexed ≠ not built)' );
ok( array() === snt_ml_related_for_post( 6, 4 ), '(d) isolated post → [] passed through as the real answer' );

update_post_meta( 3, SNT_ML_RELATED_META, array( 'garbage', array( 'post_id' => 4 ), array( 'post_id' => 4, 'score' => 0.5 ) ) );
$r3 = snt_ml_related_for_post( 3, 4 );
ok( 1 === count( $r3 ) && 4 === $r3[0]['post_id'], '(d) malformed rows are skipped, never fabricated' );

// Read-time publish gate: unpublish post 2 AFTER the build.
$GLOBALS['__posts'][2]->post_status = 'draft';
$gated = snt_ml_related_for_post( 1, 4 );
$gated_ids = array_column( $gated, 'post_id' );
ok( ! in_array( 2, $gated_ids, true ), '(d) a post unpublished since the build vanishes at READ time, before any rebuild' );
$GLOBALS['__posts'][2]->post_status = 'publish';

$piped = snt_ml_run( 'related', array( 'post_id' => 1, 'limit' => 2 ) );
ok( is_array( $piped ) && true === $piped['ok'] && 2 === $piped['related'][0]['post_id'],
	'(d) the registry pipeline serves the same rows end-to-end' );

// ─── (e) The weights filter is applied at BUILD time ─────────────────
echo "\nGroup (e): snt_ml_related_weights filter\n";
add_test_filter( 'snt_ml_related_weights', function ( $w ) {
	ok( 0.55 === $w['lexical'] && 0.25 === $w['tags'], '(e) filter receives the kernel defaults' );
	return array( 'lexical' => 0.0, 'tags' => 0.0, 'direct_link' => 1.0, 'co_link' => 0.0 );
} );
snt_ml_build_corpus();
$m4 = get_post_meta( 4, SNT_ML_RELATED_META, true );
ok( 1 === count( $m4 ) && 3 === $m4[0]['post_id'] && 1.0 === $m4[0]['score'],
	'(e) direct-link-only weights: post 4 relates ONLY to post 3 (its one link), score 1.0' );
$m3 = get_post_meta( 3, SNT_ML_RELATED_META, true );
ok( 1 === count( $m3 ) && 4 === $m3[0]['post_id'] && 1.0 === $m3[0]['score'],
	'(e) direct_link is direction-agnostic: post 3 carries the single edge back to post 4' );
clear_test_filter( 'snt_ml_related_weights' );
snt_ml_build_corpus(); // Restore default-weight artifacts for the render group.

// ─── (f) The render filter ───────────────────────────────────────────
echo "\nGroup (f): snt_ml_related_render\n";
$GLOBALS['__ctx'] = array( 'singular' => false, 'loop' => true, 'main' => true, 'the_id' => 1 );
ok( 'BODY' === snt_ml_related_render( 'BODY' ), '(f) not singular → untouched' );
$GLOBALS['__ctx']['singular'] = true;
$GLOBALS['__ctx']['main']     = false;
ok( 'BODY' === snt_ml_related_render( 'BODY' ), '(f) not the main query → untouched' );
$GLOBALS['__ctx']['main'] = true;

$out = snt_ml_related_render( 'BODY' );
ok( 0 === strpos( $out, 'BODY<aside class="snt-ml-related"' ), '(f) aside is APPENDED after the content' );
ok( false !== strpos( $out, '<h2 class="snt-ml-related-title" id="snt-ml-related-title">Related notes</h2>' ),
	'(f) mono-kicker heading present and labelledby-linked' );
ok( false !== strpos( $out, 'href="https://example.test/notes/noise-note/"' ), '(f) top match links its permalink' );
ok( false !== strpos( $out, 'Noise &amp; &lt;em&gt;attention&lt;/em&gt;' ), '(f) titles are escaped at build — markup in a title never renders' );
ok( in_array( 'snt-ml-related-front', $GLOBALS['__enqueued'], true ), '(f) stylesheet enqueued at render time only' );

$GLOBALS['__enqueued'] = array();
$GLOBALS['__ctx']['the_id'] = 6;
ok( 'BODY' === snt_ml_related_render( 'BODY' ), '(f) [] (nothing related) renders NOTHING — silent absence' );
ok( array() === $GLOBALS['__enqueued'], '(f) …and the stylesheet is NOT enqueued when nothing renders' );

// Cap: hand a 6-row artifact to post 1 — at most 4 links render.
update_post_meta( 1, SNT_ML_RELATED_META, array(
	array( 'post_id' => 2, 'score' => 0.9 ),
	array( 'post_id' => 3, 'score' => 0.8 ),
	array( 'post_id' => 4, 'score' => 0.7 ),
	array( 'post_id' => 6, 'score' => 0.6 ),
	array( 'post_id' => 7, 'score' => 0.5 ),
	array( 'post_id' => 5, 'score' => 0.4 ),
) );
$GLOBALS['__ctx']['the_id'] = 1;
$capped = snt_ml_related_render( 'BODY' );
ok( 4 === substr_count( $capped, '<li>' ), '(f) at most 4 links render' );
ok( false === strpos( $capped, 'draft-note' ), '(f) the unpublished row never renders (read-time gate holds on the surface)' );

// All rows unpublished → the section disappears entirely.
update_post_meta( 1, SNT_ML_RELATED_META, array( array( 'post_id' => 5, 'score' => 0.9 ) ) );
ok( 'BODY' === snt_ml_related_render( 'BODY' ), '(f) every row gated out → no empty shell, just the content' );

// v10.20.0 THEME-OWNERSHIP GATE — MUST BE THE LAST (f) TEST: defining the
// theme's renderer is one-way (function_exists never un-sees it). With a
// fully renderable state (valid artifact, singular main-loop context), the
// presence of the theme's native [sn_related_notes] renderer silences the
// plugin's content-filter aside — one Related section, theme-placed.
update_post_meta( 1, SNT_ML_RELATED_META, array( array( 'post_id' => 2, 'score' => 0.9 ) ) );
ok( 0 === strpos( snt_ml_related_render( 'BODY' ), 'BODY<aside' ), '(f) control: this state DOES render before the theme fn exists' );
if ( ! function_exists( 'sn_related_notes_shortcode' ) ) { // Block-wrapped: a bare top-level declaration is HOISTED at compile time and would trip the gate for every earlier (f) test.
	function sn_related_notes_shortcode() { return ''; }
}
$GLOBALS['__enqueued'] = array();
ok( 'BODY' === snt_ml_related_render( 'BODY' ), '(f) theme renderer present → the plugin aside stands down (no duplicate Related section)' );
ok( array() === $GLOBALS['__enqueued'], '(f) …and no stylesheet ships for a surface the theme owns' );
$GLOBALS['__ctx'] = array( 'singular' => false, 'loop' => false, 'main' => false, 'the_id' => 0 );

// ─── (g) Rebuild triggers ────────────────────────────────────────────
echo "\nGroup (g): cron + transition triggers\n";
$GLOBALS['__cron'] = array( 'single' => array(), 'recurring' => array() );
snt_ml_schedule_daily();
snt_ml_schedule_daily();
$daily = array_filter( $GLOBALS['__cron']['recurring'], fn( $e ) => SNT_ML_REBUILD_HOOK === $e['hook'] );
ok( 1 === count( $daily ) && 'daily' === reset( $daily )['recurrence'], '(g) daily backstop registers once, idempotent' );

snt_ml_on_transition( 'publish', 'draft', $GLOBALS['__posts'][1] );
snt_ml_on_transition( 'publish', 'publish', $GLOBALS['__posts'][2] ); // Edit burst: coalesces.
$singles = array_filter( $GLOBALS['__cron']['single'], fn( $e ) => SNT_ML_REBUILD_ASYNC_HOOK === $e['hook'] );
ok( 1 === count( $singles ), '(g) a publish burst coalesces into ONE single event (deduped, separate hook from the daily)' );

$GLOBALS['__cron']['single'] = array();
snt_ml_on_transition( 'draft', 'pending', $GLOBALS['__posts'][5] );
ok( array() === $GLOBALS['__cron']['single'], '(g) draft→pending churn (no publish side) never schedules' );
$page = tf_post( 90, 'publish', 'x', array( 'post_type' => 'page' ) );
snt_ml_on_transition( 'publish', 'draft', $page );
ok( array() === $GLOBALS['__cron']['single'], '(g) pages never trigger (posts-only corpus)' );
$GLOBALS['__revision_ids'] = array( 1 );
snt_ml_on_transition( 'publish', 'publish', $GLOBALS['__posts'][1] );
ok( array() === $GLOBALS['__cron']['single'], '(g) revisions are guarded out' );
$GLOBALS['__revision_ids'] = array();
snt_ml_on_transition( 'draft', 'publish', $GLOBALS['__posts'][2] );
ok( 1 === count( $GLOBALS['__cron']['single'] ), '(g) UNpublishing schedules too (membership left the corpus)' );

ok( isset( $GLOBALS['__test_actions']['transition_post_status'] )
	&& isset( $GLOBALS['__test_actions'][ SNT_ML_REBUILD_HOOK ] )
	&& isset( $GLOBALS['__test_actions'][ SNT_ML_REBUILD_ASYNC_HOOK ] )
	&& isset( $GLOBALS['__test_actions']['the_content'] ),
	'(g) all four hooks registered (transition, both cron hooks, the_content)' );

// ─── (h) Empty corpus is an ANSWER ───────────────────────────────────
echo "\nGroup (h): empty corpus\n";
foreach ( $GLOBALS['__posts'] as $p ) { $p->post_status = 'draft'; }
$empty_env = snt_ml_build_corpus();
ok( true === $empty_env['ok'] && 0 === $empty_env['posts'] && 0 === $empty_env['pairs'],
	'(h) building over nothing returns ok:true, posts:0 — an ANSWER, never silent' );
ok( is_array( get_option( SNT_ML_CORPUS_META_OPT ) ) && 0 === get_option( SNT_ML_CORPUS_META_OPT )['posts'],
	'(h) the option is still stamped: "built over nothing" ≠ "never built"' );
ok( array() === snt_ml_related_for_post( 1, 4 ), '(h) reads under the empty corpus answer [] (stale rows all gated out), not null' );

echo "\nGroup (i): no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], '(i) zero notices/warnings/deprecations raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
