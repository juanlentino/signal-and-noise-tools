<?php
/**
 * Standalone tests for sn_apply change.type "link_reshape" (v10.58.0,
 * audit item 5, owner-confirmed after item 4). See
 * inc/sn-apply-link-reshape.php's docblock: move an <a>'s boundaries
 * within one text node — contiguous-unique-substring constraint, href
 * carried over, rendered prose byte-identity ASSERTED post-splice,
 * fingerprint = live content_hash.
 *
 * Pure-function suite: exercises the pair validator, the locator, the
 * identity-asserting compute, and the impl (with a write-callback spy) —
 * the four-gate orchestration around them is pinned by the delegation
 * sweep, the same division of labor as the sentence_replace suite.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg\n"; }
}
function eq( $expected, $actual, $msg ) {
	ok( $expected === $actual, $msg . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_data( $key = '' ) { return $this->data; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap, $id = null ) { return true; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $args, $wp_error = false ) { $GLOBALS['__update_calls'][] = $args; return (int) ( $args['ID'] ?? 0 ); } }

$GLOBALS['__posts'] = array();
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function tf_post( $id, $content ) {
	$p = new stdClass();
	$p->ID = $id; $p->post_content = $content; $p->post_status = 'publish'; $p->post_type = 'post';
	$GLOBALS['__posts'][ $id ] = $p;
}

require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/sn-apply-link-reshape.php';

$V = 'snt_sn_apply_link_reshape_pair_error';
$L = 'snt_sn_apply_link_reshape_locate';
$C = 'snt_sn_apply_link_reshape_compute';

echo "link_reshape — v10.58.0 (audit item 5)\n\n";

/* ════════════════════════════════════════════════════════════════════════
 * Pair validator — the hard constraints
 * ════════════════════════════════════════════════════════════════════════ */

ok( true === $V( 'The whole anchor text', 'whole anchor' ), 'pair: a unique contiguous substring passes' );
ok( is_wp_error( $V( '', 'x' ) ), 'pair: empty current_anchor refuses' );
$e = $V( 'The whole anchor text', '' );
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'unlinking' ), 'pair: new_anchor:"" refuses, naming unlink as its own change type' );
ok( is_wp_error( $V( 'The whole anchor text', 'The whole anchor text' ) ), 'pair: new_anchor == current_anchor refuses (no-op)' );
ok( is_wp_error( $V( 'The whole anchor text', 'missing words' ) ), 'pair: a non-substring refuses — the contiguity constraint is the whole design' );
ok( is_wp_error( $V( 'the record and the record again', 'the record' ) ), 'pair: a substring occurring twice refuses — which words stay linked would be ambiguous' );
ok( is_wp_error( $V( 'text with <em>markup</em>', 'markup' ) ), 'pair: tag-shaped content in an anchor value refuses (anchors are text nodes)' );
ok( true === $V( 'costs are <5 percent of revenue', '<5 percent' ), 'pair: "<5 percent" prose notation stays legal (the sentence_replace lesson)' );

/* ════════════════════════════════════════════════════════════════════════
 * Locator — byte-exact inner text, context disambiguation
 * ════════════════════════════════════════════════════════════════════════ */

$body = '<!-- wp:paragraph --><p>Setup. <a href="https://x.test/a/" rel="noopener">The DAW signs the assembly</a>, and that is not sufficient.</p><!-- /wp:paragraph -->';
$m = $L( $body, 'The DAW signs the assembly' );
ok( is_array( $m ) && '<a href="https://x.test/a/" rel="noopener">' === $m['open_tag'], 'locate: finds the tag and captures the FULL open tag (href + every attribute) for carry-over' );

$e = $L( $body, 'The DAW signs' );
ok( is_wp_error( $e ) && 'snt_sn_apply_anchor_not_found' === $e->get_error_code(), 'locate: a partial inner text is not a match — byte-exact on the whole anchor' );

$twins = '<p>First: <a href="/a/">same words</a>. ' . str_repeat( 'Filler prose keeps the two occurrences farther apart than the disambiguation window. ', 10 ) . '</p><p>Second paragraph opens differently: <a href="/b/">same words</a>.</p>';
$e = $L( $twins, 'same words' );
ok( is_wp_error( $e ) && 'snt_sn_apply_anchor_ambiguous' === $e->get_error_code(), 'locate: identical anchors with no context refuse 422 rather than guessing' );
$m = $L( $twins, 'same words', 'Second paragraph opens differently' );
ok( is_array( $m ) && '<a href="/b/">' === $m['open_tag'], 'locate: context_snippet selects the intended occurrence' );

/* ════════════════════════════════════════════════════════════════════════
 * Compute — the splice + the byte-identity assertion
 * ════════════════════════════════════════════════════════════════════════ */

$m   = $L( $body, 'The DAW signs the assembly' );
$new = $C( $body, $m, 'The DAW signs the assembly', 'signs the assembly' );
ok( is_string( $new ), 'compute: reshape succeeds' );
ok( false !== strpos( $new, 'The DAW <a href="https://x.test/a/" rel="noopener">signs the assembly</a>, and' ), 'compute: prefix moved OUTSIDE the tag, open tag carried over verbatim' );
eq( wp_strip_all_tags( $body ), wp_strip_all_tags( $new ), 'compute: rendered prose is byte-identical before and after' );

// Suffix movement (new_anchor at the start).
$new2 = $C( $body, $m, 'The DAW signs the assembly', 'The DAW' );
ok( false !== strpos( $new2, '<a href="https://x.test/a/" rel="noopener">The DAW</a> signs the assembly, and' ), 'compute: suffix moves outside when new_anchor is a prefix' );

// The assertion is real: a corrupted match (length short by one) would change
// prose — compute must refuse, never write garbage.
$bad_match = $m; $bad_match['length'] = $m['length'] - 1;
$e = $C( $body, $bad_match, 'The DAW signs the assembly', 'The DAW' );
ok( is_wp_error( $e ) && 'snt_sn_apply_identity_violation' === $e->get_error_code(), 'compute: the post-splice identity assertion actually fires on a prose-changing splice (hard 500, nothing written)' );

/* ════════════════════════════════════════════════════════════════════════
 * Impl — fingerprint binding + write-callback contract
 * ════════════════════════════════════════════════════════════════════════ */

tf_post( 500, $body );
$fp = snt_corpus_content_hash( $body );

$e = snt_sn_apply_link_reshape_impl( 500, 'The DAW signs the assembly', 'signs the assembly', 'stale-hash' );
ok( is_wp_error( $e ) && 'snt_sn_apply_fingerprint_stale' === $e->get_error_code(), 'impl: a stale fingerprint is the 409 conflict' );

$GLOBALS['__cb'] = array();
$r = snt_sn_apply_link_reshape_impl( 500, 'The DAW signs the assembly', 'signs the assembly', $fp, '', function ( $pid, $content ) {
	$GLOBALS['__cb'][] = array( $pid, $content );
	return 999;
} );
ok( is_array( $r ) && true === $r['ok'], 'impl: reshape applies through the write callback' );
eq( 1, count( $GLOBALS['__cb'] ), 'impl: exactly one write-callback invocation (revision staging contract)' );
eq( wp_strip_all_tags( $body ), wp_strip_all_tags( $r['new_content'] ), 'impl: prose identity holds end to end' );
ok( false !== strpos( $r['new_content'], 'href="https://x.test/a/"' ), 'impl: href survives — carried over, never a parameter' );

echo "\nGroup: no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
