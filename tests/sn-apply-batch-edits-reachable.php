<?php
/**
 * Reachability: the batch path must be reachable THROUGH THE DISPATCHER, and
 * must perform exactly ONE write.
 *
 * WHY THIS IS SEPARATE FROM tests/sn-apply-batch-edits.php. That suite proves
 * the planner computes the right string. It would pass in full even if
 * snt_sn_apply_execute_write() never called the planner at all — which is
 * exactly what happened in v10.51.0, where an adapter was registered in the map
 * but its type was never added to the enum the ability validates against BEFORE
 * dispatch. The scanner half was dead on arrival and every unit test was green.
 *
 * So this asserts the two things a pure-function test structurally cannot:
 *   1. change.payload.edits actually routes to the batch impl from the real
 *      dispatcher, for every batch-capable type.
 *   2. The whole batch produces ONE wp_update_post() call — the entire point.
 *      Counting writes is the only assertion that would have caught the
 *      ledger-version inflation, because two writes produce correct CONTENT and
 *      an inflated RECORD.
 *
 * @since plugin v10.66.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok  - $m\n"; } else { ++$fail; echo "  FAIL - $m\n"; } }

$GLOBALS['__posts']       = array();
$GLOBALS['__write_count'] = 0;
$GLOBALS['__revisions']   = 0;

function current_user_can( $cap, $id = null ) { return true; }
function get_post( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['__posts'][ $id ] ) ? (object) array( 'ID' => $id, 'post_content' => $GLOBALS['__posts'][ $id ] ) : null;
}
function wp_update_post( $arr, $wp_error = false ) {
	++$GLOBALS['__write_count'];
	$GLOBALS['__posts'][ (int) $arr['ID'] ] = $arr['post_content'];
	return (int) $arr['ID'];
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function __( $s, $d = null ) { return $s; }
function esc_url( $s ) { return $s; }
function wp_strip_all_tags( $t, $rb = false ) { return trim( strip_tags( (string) $t ) ); }
function strip_shortcodes( $t ) { return $t; }
function snt_corpus_content_hash( $c ) { return md5( trim( (string) $c ) ); }
// Revision staging seam: mode:"revision" must route through the SAME batch impl
// and still write exactly once (one revision, not N).
function snt_sn_apply_stage_revision( $post_id, $new_content ) {
	++$GLOBALS['__revisions'];
	return 9000 + (int) $post_id;
}

require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php';
require_once __DIR__ . '/../inc/sn-apply/sentence-replace.php';
require_once __DIR__ . '/../inc/sn-apply/batch-edits.php';
require_once __DIR__ . '/../inc/emdash-scan.php';
require_once __DIR__ . '/../inc/sn-apply/executors.php';

function fp_edit( $content, $phrase, $replacement ) {
	$pos = snt_ai_drift_locate_in_raw( $content, $phrase, '' );
	return array( 'phrase' => $phrase, 'replacement' => $replacement, 'fingerprint' => snt_ai_drift_fingerprint( $content, $phrase, $pos ), 'context_snippet' => '' );
}

echo "Group 1: emdash_replace — two candidates, ONE write\n";
$body = 'Techniques for the supply chain — code signing, SLSA provenance — were designed to verify.';
$GLOBALS['__posts'][10] = $body;
$GLOBALS['__write_count'] = 0;

$res = snt_sn_apply_execute_write(
	'emdash_replace',
	array( 'post_id' => 10 ),
	array(
		'type'    => 'emdash_replace',
		'payload' => array(
			'edits' => array(
				fp_edit( $body, 'chain — code', 'chain (code' ),
				fp_edit( $body, 'provenance — were', 'provenance) were' ),
			),
		),
	),
	'publish'
);

ok( ! is_wp_error( $res ), 'the dispatcher routes payload.edits to the batch impl' );
ok( 1 === $GLOBALS['__write_count'], 'exactly ONE wp_update_post() for the whole batch (was 2 — one anchored ledger version each)' );
ok( false !== strpos( $GLOBALS['__posts'][10], 'supply chain (code signing' ), 'the opening paren is live' );
ok( false !== strpos( $GLOBALS['__posts'][10], 'SLSA provenance) were designed' ), 'the closing paren is live' );
ok( false === strpos( $GLOBALS['__posts'][10], "\xE2\x80\x94" ), 'no em-dash left in the stored content' );
ok( ! is_wp_error( $res ) && 2 === ( $res['diff']['edits_applied'] ?? 0 ), 'diff.edits_applied reports 2' );
ok( ! is_wp_error( $res ) && $res['diff']['before'] === $body, 'diff.before is the pre-batch content' );

echo "\nGroup 2: mode:\"revision\" stages ONE revision, not one per edit\n";
$GLOBALS['__posts'][11]   = $body;
$GLOBALS['__write_count'] = 0;
$GLOBALS['__revisions']   = 0;
$rev = snt_sn_apply_execute_write(
	'emdash_replace',
	array( 'post_id' => 11 ),
	array( 'type' => 'emdash_replace', 'payload' => array( 'edits' => array( fp_edit( $body, 'chain — code', 'chain (code' ), fp_edit( $body, 'provenance — were', 'provenance) were' ) ) ) ),
	'revision'
);
ok( ! is_wp_error( $rev ), 'revision mode accepts a batch' );
ok( 1 === $GLOBALS['__revisions'], 'exactly ONE revision staged for the batch' );
ok( 0 === $GLOBALS['__write_count'], 'revision mode performed no live write' );
ok( $GLOBALS['__posts'][11] === $body, 'the live post is untouched in revision mode' );

echo "\nGroup 3: sentence_replace batches through the dispatcher too\n";
$sbody = 'The first sentence runs long enough to clear the floor. The second sentence also runs long enough here.';
$GLOBALS['__posts'][12]   = $sbody;
$GLOBALS['__write_count'] = 0;
$sres = snt_sn_apply_execute_write(
	'sentence_replace',
	array( 'post_id' => 12 ),
	array(
		'type'        => 'sentence_replace',
		'fingerprint' => snt_corpus_content_hash( $sbody ),
		'payload'     => array(
			'edits' => array(
				array( 'phrase' => 'The first sentence runs long enough', 'replacement' => 'Sentence one runs long enough' ),
				array( 'phrase' => 'The second sentence also runs long enough', 'replacement' => 'Sentence two also runs long enough' ),
			),
		),
	),
	'publish'
);
ok( ! is_wp_error( $sres ), 'sentence_replace routes a batch' );
ok( 1 === $GLOBALS['__write_count'], 'sentence_replace batch is ONE write' );
ok( false !== strpos( $GLOBALS['__posts'][12], 'Sentence one runs' ) && false !== strpos( $GLOBALS['__posts'][12], 'Sentence two also runs' ), 'both sentence edits are live' );

$stale = snt_sn_apply_execute_write(
	'sentence_replace',
	array( 'post_id' => 12 ),
	array( 'type' => 'sentence_replace', 'fingerprint' => str_repeat( 'a', 32 ), 'payload' => array( 'edits' => array( array( 'phrase' => 'Sentence one runs long enough', 'replacement' => 'Sentence uno runs long enough' ) ) ) ),
	'publish'
);
ok( is_wp_error( $stale ), 'the write path re-checks the whole-post fingerprint on its own (defense in depth)' );

echo "\nGroup 4: a failed batch writes NOTHING\n";
$GLOBALS['__posts'][13]   = $body;
$GLOBALS['__write_count'] = 0;
$badedits = array( fp_edit( $body, 'chain — code', 'chain (code' ), fp_edit( $body, 'provenance — were', 'provenance) were' ) );
$badedits[1]['fingerprint'] = str_repeat( 'e', 32 );
$bad = snt_sn_apply_execute_write( 'emdash_replace', array( 'post_id' => 13 ), array( 'type' => 'emdash_replace', 'payload' => array( 'edits' => $badedits ) ), 'publish' );
ok( is_wp_error( $bad ), 'a stale edit refuses at the dispatcher' );
ok( 0 === $GLOBALS['__write_count'], 'ZERO writes — no half-applied edit reached the post' );
ok( $GLOBALS['__posts'][13] === $body, 'content is byte-identical to before the failed batch' );

echo "\nGroup 5: markup types are refused, and the single form still works\n";
$GLOBALS['__posts'][14]   = '<p>Some <a href="/x">anchor</a> here and a phrase — dash.</p>';
$GLOBALS['__write_count'] = 0;
$li = snt_sn_apply_execute_write( 'link_insert', array( 'post_id' => 14 ), array( 'type' => 'link_insert', 'payload' => array( 'edits' => array( array( 'phrase' => 'x', 'replacement' => 'y' ) ) ) ), 'publish' );
ok( is_wp_error( $li ), 'link_insert refuses payload.edits (markup, not prose)' );
ok( 0 === $GLOBALS['__write_count'], 'the refused markup batch wrote nothing' );

// The single (non-batch) form must be completely unaffected by all of this.
$single_body = 'A phrase — dash here.';
$GLOBALS['__posts'][15]   = $single_body;
$GLOBALS['__write_count'] = 0;
$spos = snt_ai_drift_locate_in_raw( $single_body, 'phrase — dash', '' );
$sing = snt_sn_apply_execute_write(
	'emdash_replace',
	array( 'post_id' => 15 ),
	array( 'type' => 'emdash_replace', 'fingerprint' => snt_ai_drift_fingerprint( $single_body, 'phrase — dash', $spos ), 'payload' => array( 'phrase' => 'phrase — dash', 'replacement' => 'phrase: dash', 'position' => $spos ) ),
	'publish'
);
ok( ! is_wp_error( $sing ), 'the single-edit form still dispatches' );
ok( 1 === $GLOBALS['__write_count'], 'the single form is still exactly one write' );
ok( false !== strpos( $GLOBALS['__posts'][15], 'A phrase: dash here.' ), 'the single form still preserves edge whitespace (v10.65.2 guard)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
