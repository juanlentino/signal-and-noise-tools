<?php
/**
 * Standalone fixture tests for v4.5.0's block-migrations suggest impl.
 *
 * @since plugin v4.5.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__test_posts'] = array();

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return $GLOBALS['__test_posts'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
if ( ! function_exists( 'serialize_block' ) ) {
	function serialize_block( $block ) {
		return json_encode( $block );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = '' ) { return $s; }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route() { return true; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $v ) { return $v; }
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code, $message, $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code()    { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}

// Fixture helper.
function _bms_post( $id, $blocks_array ) {
	$post = new stdClass();
	$post->ID           = $id;
	$post->post_status  = 'publish';
	$post->post_type    = 'post';
	$post->post_title   = "Fixture $id";
	$post->post_content = json_encode( $blocks_array );
	$GLOBALS['__test_posts'][ $id ] = $post;
}

require_once __DIR__ . '/../inc/block-fingerprint-engine.php'; // v7.7.1 shared engine
require_once __DIR__ . '/../inc/block-migrations-suggest.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function bms_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function bms_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Block-migrations suggest suite — plugin v4.5.0\n";

// ─── Test 1: invalid migration_type → WP_Error 422 ──────────────────
echo "\nTest 1: invalid migration_type\n";
$result = snt_block_migrations_suggest_impl( 301, 'abc123', 'not-a-real-type' );
bms_true( is_wp_error( $result ), 'Test 1.1: returns WP_Error' );
bms_eq( 'snt_block_migration_invalid_type', $result->get_error_code(), 'Test 1.2: error code = snt_block_migration_invalid_type' );
bms_eq( 422, $result->data['status'], 'Test 1.3: HTTP status 422' );

// ─── Test 2: post not found → WP_Error 404 ──────────────────────────
echo "\nTest 2: post not found\n";
$GLOBALS['__test_posts'] = array();
$result = snt_block_migrations_suggest_impl( 999, 'abc123', 'heading-hierarchy-skip' );
bms_true( is_wp_error( $result ), 'Test 2.1: returns WP_Error' );
bms_eq( 'snt_block_migration_post_not_found', $result->get_error_code(), 'Test 2.2: error code = post_not_found' );

// ─── Test 3: fingerprint not in post → WP_Error 404 (candidate_not_found) ─
echo "\nTest 3: fingerprint not in post\n";
$GLOBALS['__test_posts'] = array();
_bms_post( 302, array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>p</p>' ),
) );
$result = snt_block_migrations_suggest_impl( 302, 'deadbeefdeadbeefdeadbeefdeadbeef', 'heading-hierarchy-skip' );
bms_true( is_wp_error( $result ), 'Test 3.1: returns WP_Error' );
bms_eq( 'snt_block_migration_candidate_not_found', $result->get_error_code(), 'Test 3.2: error code = candidate_not_found' );

// ─── Test 4: successful heading-skip suggestion ─────────────────────
echo "\nTest 4: successful suggestion (h3 → h2)\n";
$GLOBALS['__test_posts'] = array();
$h3_block = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Skip me</h3>', 'innerContent' => array( '<h3>Skip me</h3>' ) );
$fp       = md5( serialize_block( $h3_block ) );
_bms_post( 303, array( $h3_block ) );

$result = snt_block_migrations_suggest_impl( 303, $fp, 'heading-hierarchy-skip' );
bms_true( is_array( $result ), 'Test 4.1: returns array (success)' );
bms_true( ! empty( $result['ok'] ), 'Test 4.2: ok=true' );
bms_eq( 'heading-hierarchy-skip', $result['migration_type'], 'Test 4.3: migration_type echoed' );
bms_true( strpos( $result['suggestion_markup'], '<h2>' ) !== false, 'Test 4.4: suggestion has <h2>' );
bms_true( strpos( $result['suggestion_markup'], '<h3>' ) === false, 'Test 4.5: suggestion has NO <h3>' );
bms_true( strpos( $result['suggestion_markup'], '"level":3' ) === false, 'Test 4.6: level attr stripped from suggestion JSON' );

// ─── Test 5: suggestion preserves inner text ────────────────────────
echo "\nTest 5: inner text preserved\n";
$GLOBALS['__test_posts'] = array();
$h3_block = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Configuration steps</h3>', 'innerContent' => array( '<h3>Configuration steps</h3>' ) );
$fp       = md5( serialize_block( $h3_block ) );
_bms_post( 304, array( $h3_block ) );

$result = snt_block_migrations_suggest_impl( 304, $fp, 'heading-hierarchy-skip' );
bms_true( strpos( $result['suggestion_markup'], 'Configuration steps' ) !== false, 'Test 5.1: inner text preserved' );


// ─── Test 6: regex boundary cases (regression for \b → lookahead fix) ─
echo "\nTest 6: regex boundary cases\n";

// 6.1: hyphenated tag name must NOT be rewritten
$h3_with_hyphen_neighbor = array(
	'blockName'    => 'core/heading',
	'attrs'        => array( 'level' => 3 ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<h3>Heading <h3-custom>nested-fake</h3-custom> text</h3>',
	'innerContent' => array( '<h3>Heading <h3-custom>nested-fake</h3-custom> text</h3>' ),
);
$result_h3_hyphen = snt_block_migrations_build_heading_promotion( $h3_with_hyphen_neighbor );
bms_true( strpos( $result_h3_hyphen, '<h2-custom>' ) === false, 'Test 6.1: <h3-custom> is NOT rewritten to <h2-custom>' );
bms_true( strpos( $result_h3_hyphen, '<h2>' ) !== false, 'Test 6.2: outer <h3> IS rewritten to <h2>' );

// 6.3: non-heading block passed in returns unchanged
$paragraph_block = array(
	'blockName'    => 'core/paragraph',
	'attrs'        => array(),
	'innerBlocks'  => array(),
	'innerHTML'    => '<p>Not a heading.</p>',
	'innerContent' => array( '<p>Not a heading.</p>' ),
);
$result_paragraph = snt_block_migrations_build_heading_promotion( $paragraph_block );
bms_true( strpos( $result_paragraph, '<p>' ) !== false, 'Test 6.3: non-heading block is passed through unchanged' );
bms_true( strpos( $result_paragraph, '<h2>' ) === false, 'Test 6.4: non-heading block does NOT get h2 injected' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
