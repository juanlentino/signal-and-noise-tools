<?php
/**
 * A fingerprint replacement (block_migration, pattern_adoption) splices the one
 * matched block and leaves every other stored byte as it was.
 *
 * It used to swap the node in a parse_blocks() tree and store
 * serialize_blocks() of the whole post, which rewrites bytes the edit never
 * touched (attribute JSON spelling, a core/ name, delimiter spacing), and on a
 * published note every changed byte mints a signed provenance version.
 * Both the preview (snt_sn_apply_compute_block_replacement) and the write
 * (snt_block_fp_apply) are pinned here, on core's real parser.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/sn-apply-fingerprint-splice.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function apply_filters( $hook, $value ) { return $value; }
function wp_kses_post( $h ) { return $h; }
function current_user_can( $cap, $id = null ) { return true; }
$GLOBALS['snt_posts'] = array();
function get_post( $id ) { return $GLOBALS['snt_posts'][ $id ] ?? null; }

define( 'SNT_TEST_WITH_BLOCKS_PHP', true ); // The real parse_blocks() and serializers.
require_once __DIR__ . '/lib/wp-core-blocks.php';
require __DIR__ . '/../inc/block-fingerprint-engine.php';
require __DIR__ . '/../inc/sn-apply/block-delimiters.php';
require __DIR__ . '/../inc/sn-apply/block-edit.php';
require __DIR__ . '/../inc/sn-apply/validation-gate2-parts.php';

const POST = 7;

/** Fingerprint of the node at an index path, as the scanner mints it. */
function fp_at( $content, array $path ) {
	$tree = parse_blocks( $content );
	$fp_path = '0';
	foreach ( $path as $depth => $i ) {
		$node    = $tree[ $i ];
		$fp_path = $fp_path . ( $depth ? '/innerBlocks' : '' ) . '/' . $i;
		$tree    = $node['innerBlocks'];
	}
	return snt_block_fp_fingerprint( $node, POST, $fp_path );
}

/** Preview and write for one replacement; returns [preview, written]. */
function run_both( $content, $fp, $markup ) {
	$GLOBALS['snt_posts'][ POST ] = (object) array( 'post_content' => $content );
	$preview = snt_sn_apply_compute_block_replacement( POST, $fp, $markup );
	$written = null;
	$result  = snt_block_fp_apply(
		array(
			'post_id'            => POST,
			'block_fingerprint'  => $fp,
			'replacement_markup' => $markup,
			'type'               => 't',
			'valid_types'        => array( 't' ),
			'write_callback'     => static function ( $id, $c ) use ( &$written ) { $written = $c; return $id; },
		)
	);
	return array( $preview, is_wp_error( $result ) ? $result : $written );
}

$new_h = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">New</h2>\n<!-- /wp:heading -->";

// Top level: non-canonical bytes before and after the target.
$before = "<p>classic chunk</p>\r\n\r\n<!-- wp:core/paragraph {\"className\": \"a\"} -->\r\n<p class=\"a\">one</p>\r\n<!-- /wp:core/paragraph -->\n\n\n";
$target = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Old</h3>\n<!-- /wp:heading -->";
$after  = "\n\n<!--   wp:paragraph   {\"x\":\"\\u00e9\"}   -->\n<p>two</p>\n<!-- /wp:paragraph -->\n";
$doc    = $before . $target . $after;
ok( serialize_blocks( parse_blocks( $doc ) ) !== $doc, 'fixture: serialize_blocks() normalizes this post (the test can fail)' );
$top_idx = null;
foreach ( parse_blocks( $doc ) as $i => $n ) { if ( 'core/heading' === $n['blockName'] ) { $top_idx = $i; } }
list( $p, $w ) = run_both( $doc, fp_at( $doc, array( $top_idx ) ), $new_h );
$want = $before . $new_h . $after;
ok( is_array( $p ) && $want === $p['new_content'], 'top level: preview splices only the matched block' );
ok( $want === $w, 'top level: the write stores exactly that' );

// Nested: the target is an inner block; the parent opener and a sibling are non-canonical.
$inner  = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Inner</h3>\n<!-- /wp:heading -->";
$n_pre  = "<!-- wp:core/group {\"layout\": {\"type\":\"constrained\"}} -->\r\n<div class=\"wp-block-group\">\r\n<!-- wp:paragraph {\"a\" : 1} -->\n<p>sib</p>\n<!-- /wp:paragraph -->\n\n";
$n_post = "\n</div>\r\n<!-- /wp:core/group -->\n";
$ndoc   = $n_pre . $inner . $n_post;
ok( serialize_blocks( parse_blocks( $ndoc ) ) !== $ndoc, 'fixture: the nested post normalizes too' );
list( $p, $w ) = run_both( $ndoc, fp_at( $ndoc, array( 0, 1 ) ), $new_h );
$want = $n_pre . $new_h . $n_post;
ok( is_array( $p ) && $want === $p['new_content'], 'nested: preview splices the inner block\'s own delimiters' );
ok( $want === $w, 'nested: the write stores exactly that' );

// Unchanged contracts.
list( $p, $w ) = run_both( $doc, 'nope', $new_h );
ok( is_wp_error( $p ) && 409 === $p->data['status'], 'unknown fingerprint: 409' );
list( $p, $w ) = run_both( $doc, fp_at( $doc, array( $top_idx ) ), '<p>no block</p>' );
ok( is_wp_error( $p ) && 422 === $p->data['status'], 'invalid replacement markup: 422' );

// Parsers disagree: a delimiter inside a plain HTML comment. Refuse, never re-serialize.
// (tests/fixtures/block-delimiter-inputs.php, 'delimiter in a comment'.)
$bad  = "<!-- note: <!-- wp:paragraph --> was here -->\n<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->";
$bidx = null;
foreach ( parse_blocks( $bad ) as $i => $n ) { if ( null !== $n['blockName'] && null === $bidx ) { $bidx = $i; } }
ok( null !== $bidx, 'parser disagreement fixture: the parser sees a block to match' );
list( $p, $w ) = run_both( $bad, fp_at( $bad, array( (int) $bidx ) ), $new_h );
ok( is_wp_error( $p ) && 422 === $p->data['status'], 'parser disagreement: preview refuses 422, never re-serializes' );
ok( is_wp_error( $w ) && 422 === $w->data['status'], 'parser disagreement: the write refuses 422' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
