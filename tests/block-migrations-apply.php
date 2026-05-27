<?php
/**
 * Standalone fixture tests for v4.5.0's block-migrations apply impl.
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
$GLOBALS['__test_posts']        = array();
$GLOBALS['__test_capabilities'] = true;
$GLOBALS['__test_update_fail']  = false;

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['__test_posts'][ $id ] ?? null; }
}
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
if ( ! function_exists( 'serialize_block' ) ) {
	function serialize_block( $block ) { return json_encode( $block ); }
}
if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $tree ) { return json_encode( $tree ); }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $post_id = null ) { return $GLOBALS['__test_capabilities']; }
}
if ( ! function_exists( 'wp_update_post' ) ) {
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
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = '' ) { return $s; }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route() { return true; }
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

function _bma_post( $id, $blocks_array ) {
	$post = new stdClass();
	$post->ID           = $id;
	$post->post_status  = 'publish';
	$post->post_type    = 'post';
	$post->post_title   = "Fixture $id";
	$post->post_content = json_encode( $blocks_array );
	$GLOBALS['__test_posts'][ $id ] = $post;
}

require_once __DIR__ . '/../inc/block-migrations-apply.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function bma_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function bma_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Block-migrations apply suite — plugin v4.5.0\n";

// ─── Test 1: capability denied → WP_Error 403 ────────────────────────
echo "\nTest 1: capability denied\n";
$GLOBALS['__test_capabilities'] = false;
$result = snt_block_migrations_apply_impl( 401, 'fp', '<h2>x</h2>', 'heading-hierarchy-skip' );
bma_true( is_wp_error( $result ), 'Test 1.1: returns WP_Error' );
bma_eq( 'snt_block_migration_capability', $result->get_error_code(), 'Test 1.2: error = capability' );
$GLOBALS['__test_capabilities'] = true;

// ─── Test 2: post not found → WP_Error 404 ──────────────────────────
echo "\nTest 2: post not found\n";
$GLOBALS['__test_posts'] = array();
$result = snt_block_migrations_apply_impl( 999, 'fp', '<!-- wp:heading --><h2>x</h2><!-- /wp:heading -->', 'heading-hierarchy-skip' );
bma_true( is_wp_error( $result ), 'Test 2.1: returns WP_Error' );
bma_eq( 'snt_block_migration_post_not_found', $result->get_error_code(), 'Test 2.2: error = post_not_found' );

// ─── Test 3: fingerprint not found → WP_Error 409 (conflict) ────────
echo "\nTest 3: fingerprint conflict\n";
$GLOBALS['__test_posts'] = array();
_bma_post( 402, array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>p</p>' ),
) );
$result = snt_block_migrations_apply_impl( 402, 'wrongfingerprint000000000000000', json_encode( array( array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<h2>x</h2>' ) ) ), 'heading-hierarchy-skip' );
bma_true( is_wp_error( $result ), 'Test 3.1: returns WP_Error' );
bma_eq( 'snt_block_migration_conflict', $result->get_error_code(), 'Test 3.2: error = conflict (409)' );

// ─── Test 4: successful apply mutates post ──────────────────────────
echo "\nTest 4: successful apply\n";
$GLOBALS['__test_posts'] = array();
$h3_block  = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Section</h3>', 'innerContent' => array( '<h3>Section</h3>' ) );
$fp        = md5( serialize_block( $h3_block ) );
_bma_post( 403, array( $h3_block ) );

$replacement_markup = json_encode( array( array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<h2>Section</h2>', 'innerContent' => array( '<h2>Section</h2>' ) ) ) );

$result = snt_block_migrations_apply_impl( 403, $fp, $replacement_markup, 'heading-hierarchy-skip' );
bma_true( is_array( $result ), 'Test 4.1: returns array (success)' );
bma_true( ! empty( $result['ok'] ), 'Test 4.2: ok=true' );
bma_eq( 403, $result['post_id'], 'Test 4.3: post_id echoed' );
bma_true( strpos( $GLOBALS['__test_posts'][ 403 ]->post_content, '<h2>' ) !== false, 'Test 4.4: post_content now contains <h2>' );

// ─── Test 5: wp_update_post failure surfaces as WP_Error ─────────────
echo "\nTest 5: write failed\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_update_fail'] = true;
$h3_block = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Section</h3>', 'innerContent' => array( '<h3>Section</h3>' ) );
$fp       = md5( serialize_block( $h3_block ) );
_bma_post( 404, array( $h3_block ) );
$replacement_markup = json_encode( array( array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<h2>Section</h2>', 'innerContent' => array( '<h2>Section</h2>' ) ) ) );
$result = snt_block_migrations_apply_impl( 404, $fp, $replacement_markup, 'heading-hierarchy-skip' );
bma_true( is_wp_error( $result ), 'Test 5.1: returns WP_Error' );
bma_eq( 'snt_block_migration_write_failed', $result->get_error_code(), 'Test 5.2: error = write_failed' );
$GLOBALS['__test_update_fail'] = false;

// ─── Test 6: invalid replacement markup → WP_Error 422 ──────────────
echo "\nTest 6: invalid replacement markup\n";
$GLOBALS['__test_posts'] = array();
_bma_post( 405, array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>p</p>' ),
) );
$result = snt_block_migrations_apply_impl( 405, 'fp', 'not valid block markup', 'heading-hierarchy-skip' );
bma_true( is_wp_error( $result ), 'Test 6.1: returns WP_Error' );
bma_eq( 'snt_block_migration_invalid_markup', $result->get_error_code(), 'Test 6.2: error = invalid_markup (422)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
