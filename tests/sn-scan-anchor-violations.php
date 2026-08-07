<?php
/**
 * Standalone tests for scan_type "anchor_violations" (v10.58.0):
 * inc/sn-scan-anchor-violations.php — detector + sn_scan adapter.
 *
 * Fixture shapes are distilled from the REAL corpus rows the owner measured
 * by hand (2026-08-08 audit item 2): every known-violation shape and both
 * known should-pass shapes appear below. The full-corpus validation ran
 * against live bodies at build time (12/12 violations, 0 false positives
 * across 40 posts); these synthetic fixtures pin the same shapes without
 * shipping post bodies in the repo.
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
		public function get_error_data( $key = '' ) { return $this->data; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$GLOBALS['__posts'] = array();
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function snt_corpus_fetch_posts( $status = 'any', $post_type = 'post' ) { return array_values( $GLOBALS['__posts'] ); }

require __DIR__ . '/../inc/sn-scan-anchor-violations.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "FAIL: $label\n"; }
}

$scan = 'snt_anchor_violations_scan_content';

/* ════════════════════════════════════════════════════════════════════════
 * Rule 1 — anchor_equals_sentence
 * ════════════════════════════════════════════════════════════════════════ */

// The commonest real shape: the anchor IS the sentence, period outside the tag.
$v = $scan( '<!-- wp:paragraph -->' . "\n" . '<p><a href="/notes/x/">The DAW signs the assembly</a>. A signature at export binds only the act of putting things together.</p>' . "\n" . '<!-- /wp:paragraph -->' );
ok( 1 === count( $v ) && 'anchor_equals_sentence' === $v[0]['rule'], 'rule 1: anchor == full sentence (terminal period outside the tag) -> one violation' );
ok( 'The DAW signs the assembly' === $v[0]['anchor_text'], 'rule 1: anchor_text is the normalized anchor' );

// Period INSIDE the anchor ("Origination is not leverage." / "This is a CFO problem.").
$v = $scan( '<p>Leading sentence here. <a href="/notes/x/">Origination is not leverage.</a> Which is why the tier that makes the work loses.</p>' );
ok( 1 === count( $v ) && 'anchor_equals_sentence' === $v[0]['rule'], 'rule 1: anchor carrying its own terminal period is still caught' );

// Mid-paragraph sentence, anchor mid-sentence -> NOT flagged.
$v = $scan( '<p>Detection <a href="/notes/x/">fails on cost structure</a>. Every item has to be evaluated.</p>' );
ok( array() === $v, 'rule 1 pass case (real corpus): four words inside a five-word sentence -> no violation' );

// Long anchor inside a longer sentence -> NOT flagged (the ratio trap the
// owner explicitly rejected: length must never matter, only equality).
$v = $scan( '<p>The pattern is old and well documented. In an unrelated dispute over songwriter royalties, <a href="/notes/x/">a court declined to call a split unfair and found the fairness question unanswerable instead</a>, because the data needed never existed.</p>' );
ok( array() === $v, 'rule 1 pass case (real corpus): a 15-word anchor inside a longer sentence -> no violation, length is irrelevant' );

// First sentence of a paragraph (no preceding terminator, boundary = block start).
$v = $scan( '<p><a href="/notes/x/">We sign tracks at upload</a>. That was never the goal.</p>' );
ok( 1 === count( $v ), 'rule 1: anchor as the FIRST sentence of a block is caught (block boundary = sentence start)' );

// Last sentence of a paragraph, no trailing period at all.
$v = $scan( '<p>A closing thought follows. <a href="/notes/x/">Every party in that chain is a verification surface</a></p>' );
ok( 1 === count( $v ), 'rule 1: anchor as the LAST sentence of a block without terminal punctuation is caught' );

// Inline formatting inside the anchor does not defeat equality.
$v = $scan( '<p>Before this. <a href="/notes/x/">The <em>institutional</em> shortcut is gone</a>. After this.</p>' );
ok( 1 === count( $v ), 'rule 1: markup inside the anchor is normalized away before comparison' );

// Entities normalize identically on both sides.
$v = $scan( '<p>Setup sentence. <a href="/notes/x/">Detection&#8217;s costs scale with volume</a>. Coda.</p>' );
ok( 1 === count( $v ) && false !== strpos( $v[0]['anchor_text'], "\u{2019}" ), 'rule 1: HTML entities are decoded once on both sides before comparison' );

// Two violations in one post are both reported.
$v = $scan( '<p><a href="/a/">First full sentence anchor</a>. Filler here.</p><p>Filler again. <a href="/b/">Second full sentence anchor</a>.</p>' );
ok( 2 === count( $v ), 'rule 1: multiple violations in one post each report' );

// An anchor equal to a QUESTION-terminated sentence.
$v = $scan( '<p>Context first. <a href="/notes/x/">Is any of this reliable?</a> More prose follows.</p>' );
ok( 1 === count( $v ), 'rule 1: question-mark terminators count as sentence boundaries' );

// Colon does NOT terminate a sentence: an anchor equal to the clause after a
// colon is still part of the longer sentence -> no violation (real corpus
// shape: "…the new question…: is any of this AI?").
$v = $scan( '<p>Each needs one more check, the new question added last year: <a href="/notes/x/">is any of this AI?</a> Some have splits that do not match.</p>' );
ok( array() === $v, 'rule 1: a colon is not a sentence boundary — the anchored clause is part of the longer sentence' );

/* ════════════════════════════════════════════════════════════════════════
 * Rule 2 — heading_contains_link
 * ════════════════════════════════════════════════════════════════════════ */

// H3 wrapped entirely in a link (two real corpus rows have exactly this).
$v = $scan( '<!-- wp:heading {"level":3} -->' . "\n" . '<h3 class="wp-block-heading"><a href="/notes/x/">Assertion and observation</a></h3>' . "\n" . '<!-- /wp:heading -->' );
ok( 1 === count( $v ) && 'heading_contains_link' === $v[0]['rule'], 'rule 2: heading wrapped entirely in a link -> ONE violation, owned by the heading rule (never double-reported under rule 1)' );
ok( 3 === $v[0]['heading_level'], 'rule 2: heading level is reported in evidence' );

// Partial link inside a heading is still a violation.
$v = $scan( '<h4 class="wp-block-heading">The <a href="/notes/x/">institutional</a> shortcut</h4>' );
ok( 1 === count( $v ) && 'heading_contains_link' === $v[0]['rule'] && 4 === $v[0]['heading_level'], 'rule 2: ANY <a> inside an h1-h6 is a violation, not only fully-wrapped headings' );

// Clean heading -> nothing.
$v = $scan( '<h4 class="wp-block-heading">What unfalsifiable means</h4><p>Body text with a normal <a href="/x/">short anchor</a> inside a longer sentence here.</p>' );
ok( array() === $v, 'rule 2: link-free heading + healthy body link -> no violations' );

// Heading text never bleeds into the following paragraph's first sentence.
$v = $scan( '<h4>No terminal punctuation here</h4><p><a href="/x/">A complete sentence anchor</a>. Rest.</p>' );
ok( 1 === count( $v ) && 'anchor_equals_sentence' === $v[0]['rule'], 'flattening: block boundaries separate heading text from body sentences (no heading+sentence concatenation false negative)' );

/* ════════════════════════════════════════════════════════════════════════
 * Adapter envelope
 * ════════════════════════════════════════════════════════════════════════ */

function av_post( $id, $content, $slug ) {
	$p = new stdClass();
	$p->ID = $id; $p->post_title = "Post $id"; $p->post_name = $slug;
	$p->post_status = 'publish'; $p->post_type = 'post'; $p->post_content = $content;
	return $p;
}

$GLOBALS['__posts'] = array(
	10 => av_post( 10, '<p><a href="/a/">A full sentence anchor here</a>. Trailing prose.</p>', 'violator' ),
	11 => av_post( 11, '<p>Nothing wrong <a href="/b/">in this one</a> at all, just a normal link.</p>', 'clean' ),
	12 => av_post( 12, '<h3><a href="/c/">Linked heading</a></h3>', 'heading-violator' ),
);

$r = snt_sn_scan_adapter_anchor_violations( null );
ok( ! is_wp_error( $r ) && 3 === $r['posts_examined'], 'adapter: walks the corpus (posts_examined = 3)' );
ok( 2 === count( $r['candidates'] ), 'adapter: one candidate per violation, clean post contributes none' );
$c = $r['candidates'][0];
ok( isset( $c['target_identity'], $c['content_fingerprint'], $c['targets'], $c['confidence'], $c['evidence'] ), 'adapter: every envelope key inc/abilities-sn-scan.php reads is present (the emdash-adapter trap)' );
ok( SNT_SN_SCAN_CONF_ANCHOR_VIOLATIONS === $c['confidence'], 'adapter: documented constant confidence (binary rule, no native score)' );
ok( null === $c['apply_hint'], 'adapter: apply_hint is null — no reshape path exists yet (link_reshape is owner-gated)' );
ok( 10 === $c['targets'][0]['post_id'] && 'violator' === $c['targets'][0]['slug'], 'adapter: targets carry post_id + slug' );

// Determinism: two runs -> identical rows; distinct violations -> distinct fingerprints.
$r2 = snt_sn_scan_adapter_anchor_violations( null );
ok( json_encode( $r ) === json_encode( $r2 ), 'adapter: two runs against unchanged content are byte-identical (acceptance test 1 upstream)' );
$fps = array_column( $r['candidates'], 'content_fingerprint' );
ok( count( $fps ) === count( array_unique( $fps ) ), 'adapter: distinct violations get distinct content fingerprints' );

// Scope restriction.
$r = snt_sn_scan_adapter_anchor_violations( array( 12 ) );
ok( 1 === $r['posts_examined'] && 1 === count( $r['candidates'] ) && 'heading_contains_link' === $r['candidates'][0]['evidence']['rule'], 'adapter: allowed_ids scope restricts the walk' );

// Two byte-identical violations in one post stay distinct via the ordinal.
$GLOBALS['__posts'] = array(
	20 => av_post( 20, '<p><a href="/a/">Repeat me now please</a>. Filler.</p><p><a href="/b/">Repeat me now please</a>. Filler.</p>', 'twins' ),
);
$r = snt_sn_scan_adapter_anchor_violations( null );
$fps = array_column( $r['candidates'], 'content_fingerprint' );
ok( 2 === count( $fps ) && $fps[0] !== $fps[1], 'adapter: byte-identical violations in one post are disambiguated by ordinal, never collapsed' );

echo "\nGroup: no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
