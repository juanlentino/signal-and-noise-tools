<?php
/**
 * Shared block-fingerprint engine (v7.7.1 unification).
 *
 * One engine backs both fingerprint-validated Suggest/Apply surfaces
 * (block-migrations + pattern-adoption), which had carried byte-identical
 * find/replace walkers and near-identical apply pipelines since 4.3.0/4.5.0:
 *
 *   snt_block_fp_fingerprint( $block )                  md5(serialize_block)
 *   snt_block_fp_find( $tree, $fp )                     node|null, depth-first
 *   snt_block_fp_replace_in_tree( &$tree, $fp, $n, &$f) first match, in place
 *   snt_block_fp_sanitize_node( $node )                 recursive wp_kses_post
 *   snt_block_fp_apply( $args )                         the shared pipeline
 *
 * The pipeline is parameterized by per-surface error codes/messages so the
 * public WP_Error contracts stay byte-identical. Two deliberate v7.7.1
 * behavior notes (changelogged):
 *   - capability is checked FIRST on both surfaces (pattern-adoption used to
 *     gate type before capability);
 *   - block-migrations gains the v6.39.2 wp_kses_post sanitization that only
 *     pattern-adoption had (stored-XSS parity).
 *
 * @since 7.7.1
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs (BM/PA fixture model: JSON-backed block trees) ──────────
$GLOBALS['__test_posts']        = array();
$GLOBALS['__test_capabilities'] = true;
$GLOBALS['__test_update_fail']  = false;

function get_post( $id ) { return $GLOBALS['__test_posts'][ $id ] ?? null; }
function parse_blocks( $content ) {
	$decoded = json_decode( $content, true );
	return is_array( $decoded ) ? $decoded : array();
}
function serialize_block( $block ) { return json_encode( $block ); }
function serialize_blocks( $tree ) { return json_encode( $tree ); }
function current_user_can( $cap, $post_id = null ) { return $GLOBALS['__test_capabilities']; }
function wp_update_post( $args, $wp_error = false ) {
	if ( $GLOBALS['__test_update_fail'] ) {
		return new WP_Error( 'mock_update_fail', 'mocked failure' );
	}
	$id = (int) $args['ID'];
	if ( isset( $GLOBALS['__test_posts'][ $id ] ) ) {
		$GLOBALS['__test_posts'][ $id ]->post_content = $args['post_content'];
	}
	return $id;
}
function __( $s, $domain = '' ) { return $s; }
// Input-aware wp_kses_post model (mirrors tests/pattern-adoption-apply.php):
// strips <script> blocks + inline on* handlers, passes the rest through.
function wp_kses_post( $html ) {
	$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
	$html = preg_replace( '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', (string) $html );
	return $html;
}

class WP_Error {
	public $code, $message, $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code()    { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data()    { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

function _bfe_post( $id, $blocks_array ) {
	$post = new stdClass();
	$post->ID           = $id;
	$post->post_content = json_encode( $blocks_array );
	$GLOBALS['__test_posts'][ $id ] = $post;
}

// ─── Load the SUT ──────────────────────────────────────────────────────
$engine = __DIR__ . '/../inc/block-fingerprint-engine.php';
$engine_exists = file_exists( $engine );
if ( $engine_exists ) {
	require_once $engine;
}

// ─── Harness ───────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function t( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}
function t_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}

// Standard per-surface arg template (block-migrations codes).
function _bfe_args( $overrides = array() ) {
	return array_merge( array(
		'post_id'            => 0,
		'block_fingerprint'  => '',
		'replacement_markup' => '',
		'type'               => 'heading-hierarchy-skip',
		'valid_types'        => array( 'heading-hierarchy-skip' ),
		'error_codes'        => array(
			'capability'     => 'snt_block_migration_capability',
			'invalid_type'   => 'snt_block_migration_invalid_type',
			'post_not_found' => 'snt_block_migration_post_not_found',
			'invalid_markup' => 'snt_block_migration_invalid_markup',
			'conflict'       => 'snt_block_migration_conflict',
			'write_failed'   => 'snt_block_migration_write_failed',
		),
	), $overrides );
}

$H2  = array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<h2>x</h2>', 'innerContent' => array( '<h2>x</h2>' ) );
$H3  = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>x</h3>', 'innerContent' => array( '<h3>x</h3>' ) );
$GRP = array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array( $H3 ), 'innerHTML' => '', 'innerContent' => array( null ) );

echo "Block-fingerprint engine suite — plugin v7.7.1\n";

echo "\nGroup A: primitives\n";
t( $engine_exists, 'A.1 inc/block-fingerprint-engine.php exists' );
if ( function_exists( 'snt_block_fp_fingerprint' ) ) {
	t_eq( md5( serialize_block( $H3 ) ), snt_block_fp_fingerprint( $H3 ), 'A.2 fingerprint = md5(serialize_block)' );

	$fp = snt_block_fp_fingerprint( $H3 );
	t_eq( $H3, snt_block_fp_find( array( $H2, $H3 ), $fp ), 'A.3 find: top-level match' );
	t_eq( $H3, snt_block_fp_find( array( $H2, $GRP ), $fp ), 'A.4 find: nested match (innerBlocks recursion)' );
	t( null === snt_block_fp_find( array( $H2 ), $fp ), 'A.5 find: null on miss' );

	$tree  = array( $H2, $H3, $H3 );
	$found = false;
	snt_block_fp_replace_in_tree( $tree, $fp, $H2, $found );
	t( $found && $tree[1] === $H2 && $tree[2] === $H3, 'A.6 replace: first match only, in place, found=true' );

	$tree  = array( $GRP );
	$found = false;
	snt_block_fp_replace_in_tree( $tree, $fp, $H2, $found );
	t( $found && $tree[0]['innerBlocks'][0] === $H2, 'A.7 replace: nested match mutates through references' );

	$tree  = array( $H2 );
	$found = false;
	snt_block_fp_replace_in_tree( $tree, $fp, $H3, $found );
	t( ! $found && $tree[0] === $H2, 'A.8 replace: no match → untouched, found=false' );
} else {
	for ( $i = 2; $i <= 8; $i++ ) { t( false, "A.$i engine primitive available" ); }
}

echo "\nGroup B: sanitize_node\n";
if ( function_exists( 'snt_block_fp_sanitize_node' ) ) {
	$dirty = array(
		'blockName'    => 'core/paragraph',
		'attrs'        => array( 'className' => 'x' ),
		'innerHTML'    => '<p onclick="evil()">hi<script>alert(1)</script></p>',
		'innerContent' => array( '<p>hi<script>alert(1)</script></p>', null ),
		'innerBlocks'  => array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => '<p><script>x</script>y</p>', 'innerContent' => array( '<p><script>x</script>y</p>' ), 'innerBlocks' => array() ) ),
	);
	$clean = snt_block_fp_sanitize_node( $dirty );
	t( false === strpos( $clean['innerHTML'], '<script' ) && false === strpos( $clean['innerHTML'], 'onclick' ), 'B.1 innerHTML sanitized' );
	t( false === strpos( (string) $clean['innerContent'][0], '<script' ), 'B.2 innerContent string chunks sanitized' );
	t( null === $clean['innerContent'][1], 'B.3 non-string innerContent chunks untouched' );
	t( false === strpos( $clean['innerBlocks'][0]['innerHTML'], '<script' ), 'B.4 nested innerBlocks sanitized recursively' );
	t_eq( array( 'className' => 'x' ), $clean['attrs'], 'B.5 attrs untouched' );
	t_eq( 'nope', snt_block_fp_sanitize_node( 'nope' ), 'B.6 non-array passthrough' );
	t( false !== strpos( $dirty['innerHTML'], '<script' ), 'B.7 pure: input node not mutated' );
} else {
	for ( $i = 1; $i <= 7; $i++ ) { t( false, "B.$i sanitize_node available" ); }
}

echo "\nGroup C: apply pipeline (parameterized codes)\n";
if ( function_exists( 'snt_block_fp_apply' ) ) {
	$fp = md5( serialize_block( $H3 ) );

	// C.1 capability FIRST — even with an invalid type, an incapable caller
	// gets the capability code (the normalized v7.7.1 order).
	$GLOBALS['__test_capabilities'] = false;
	$r = snt_block_fp_apply( _bfe_args( array( 'post_id' => 1, 'type' => 'bogus' ) ) );
	t( is_wp_error( $r ) && 'snt_block_migration_capability' === $r->get_error_code(), 'C.1 capability gates before type (403 wins)' );
	$GLOBALS['__test_capabilities'] = true;

	$r = snt_block_fp_apply( _bfe_args( array( 'post_id' => 1, 'type' => 'bogus' ) ) );
	t( is_wp_error( $r ) && 'snt_block_migration_invalid_type' === $r->get_error_code(), 'C.2 invalid type → surface code' );

	$GLOBALS['__test_posts'] = array();
	$r = snt_block_fp_apply( _bfe_args( array( 'post_id' => 999, 'block_fingerprint' => $fp, 'replacement_markup' => json_encode( array( $H2 ) ) ) ) );
	t( is_wp_error( $r ) && 'snt_block_migration_post_not_found' === $r->get_error_code(), 'C.3 missing post → surface code' );

	_bfe_post( 10, array( $H3 ) );
	$r = snt_block_fp_apply( _bfe_args( array( 'post_id' => 10, 'block_fingerprint' => $fp, 'replacement_markup' => 'not block markup' ) ) );
	t( is_wp_error( $r ) && 'snt_block_migration_invalid_markup' === $r->get_error_code(), 'C.4 unnamed/unparseable replacement → surface code' );

	$r = snt_block_fp_apply( _bfe_args( array( 'post_id' => 10, 'block_fingerprint' => 'wrong', 'replacement_markup' => json_encode( array( $H2 ) ) ) ) );
	t( is_wp_error( $r ) && 'snt_block_migration_conflict' === $r->get_error_code(), 'C.5 fingerprint drift → conflict code (409)' );

	$r = snt_block_fp_apply( _bfe_args( array( 'post_id' => 10, 'block_fingerprint' => $fp, 'replacement_markup' => json_encode( array( $H2 ) ) ) ) );
	t( is_array( $r ) && true === $r['ok'] && 10 === $r['post_id'], 'C.6 success → { ok, post_id }' );
	t( false !== strpos( $GLOBALS['__test_posts'][10]->post_content, '<h2>' ), 'C.7 success wrote the mutated tree' );

	// C.8 sanitize-before-splice: script payload in the replacement never lands.
	_bfe_post( 11, array( $H3 ) );
	$evil = array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<h2>x<script>alert(1)</script></h2>', 'innerContent' => array( '<h2>x<script>alert(1)</script></h2>' ) );
	$r = snt_block_fp_apply( _bfe_args( array( 'post_id' => 11, 'block_fingerprint' => $fp, 'replacement_markup' => json_encode( array( $evil ) ) ) ) );
	t( is_array( $r ) && false === strpos( $GLOBALS['__test_posts'][11]->post_content, '<script' ), 'C.8 replacement sanitized before write' );

	$GLOBALS['__test_update_fail'] = true;
	_bfe_post( 12, array( $H3 ) );
	$r = snt_block_fp_apply( _bfe_args( array( 'post_id' => 12, 'block_fingerprint' => $fp, 'replacement_markup' => json_encode( array( $H2 ) ) ) ) );
	t( is_wp_error( $r ) && 'snt_block_migration_write_failed' === $r->get_error_code(), 'C.9 wp_update_post failure → surface code' );
	t( false !== strpos( $r->get_error_message(), 'mocked failure' ), 'C.10 write-failed message carries the underlying error' );
	$GLOBALS['__test_update_fail'] = false;

	// C.11 custom message override (the per-surface invalid-type strings).
	$r = snt_block_fp_apply( _bfe_args( array(
		'post_id'        => 10,
		'type'           => 'bogus',
		'error_messages' => array( 'invalid_type' => 'pattern_type must be one of: pull-quote, steps-enumerated.' ),
	) ) );
	t( is_wp_error( $r ) && 'pattern_type must be one of: pull-quote, steps-enumerated.' === $r->get_error_message(), 'C.11 per-surface message override honored' );
} else {
	for ( $i = 1; $i <= 11; $i++ ) { t( false, "C.$i snt_block_fp_apply available" ); }
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
