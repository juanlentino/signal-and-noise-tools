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
// #1177: the writer now hands core wp_slash()ed data; the stub keeps the value as-is so the assertions below read what was sent.
if ( ! function_exists( 'wp_slash' ) ) { function wp_slash( $v ) { return $v; } }
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
// v7.7.1: the shared block-fingerprint engine sanitizes the replacement node
// through wp_kses_post on BOTH surfaces (block-migrations previously skipped
// the v6.39.2 fix pattern-adoption got). Input-aware model mirroring
// tests/pattern-adoption-apply.php: strips <script> + inline on* handlers.
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
		$html = preg_replace( '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', (string) $html );
		return $html;
	}
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

// 2026-10-12: a live apply drops its row from the user's cached scan.
$GLOBALS['__transients'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 7; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $transient ) { return $GLOBALS['__transients'][ $transient ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $transient, $value, $expiration = 0 ) { $GLOBALS['__transients'][ $transient ] = $value; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $transient ) { unset( $GLOBALS['__transients'][ $transient ] ); return true; } }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

require_once __DIR__ . '/../inc/block-fingerprint-engine.php'; // v7.7.1 shared engine
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
$fp        = snt_block_fp_fingerprint( $h3_block, 403, '0/0' );
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
$fp       = snt_block_fp_fingerprint( $h3_block, 404, '0/0' );
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

// ─── Test 7: replacement markup sanitized before write (v7.7.1 parity) ─
// pattern-adoption got this in v6.39.2; the shared engine now applies it to
// block-migrations too. The replacement is user-editable in the modal, so a
// <script>/onerror payload must never be spliced into post_content.
echo "\nTest 7: replacement sanitized (stored-XSS parity with pattern-adoption)\n";
$GLOBALS['__test_posts'] = array();
$h3_block = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Section</h3>', 'innerContent' => array( '<h3>Section</h3>' ) );
$fp       = snt_block_fp_fingerprint( $h3_block, 406, '0/0' );
_bma_post( 406, array( $h3_block ) );
$evil_markup = json_encode( array( array(
	'blockName'    => 'core/heading',
	'attrs'        => array(),
	'innerBlocks'  => array(),
	'innerHTML'    => '<h2 onclick="evil()">Section<script>alert(1)</script></h2>',
	'innerContent' => array( '<h2 onclick="evil()">Section<script>alert(1)</script></h2>' ),
) ) );
$result = snt_block_migrations_apply_impl( 406, $fp, $evil_markup, 'heading-hierarchy-skip' );
bma_true( is_array( $result ) && ! empty( $result['ok'] ), 'Test 7.1: apply succeeds' );
bma_true( strpos( $GLOBALS['__test_posts'][ 406 ]->post_content, '<script' ) === false, 'Test 7.2: <script> stripped before write' );
bma_true( strpos( $GLOBALS['__test_posts'][ 406 ]->post_content, 'onclick' ) === false, 'Test 7.3: inline handler stripped before write' );
bma_true( strpos( $GLOBALS['__test_posts'][ 406 ]->post_content, 'Section' ) !== false, 'Test 7.4: legitimate content survives' );

// ─── Test 8: a live apply drops its row from the cached scan ─────────
// 2026-10-12 audit: the applied row stayed listed (with a Suggest and a
// Dismiss that then failed on a block no longer there) until the hour ran out.
echo "\nTest 8: the applied candidate leaves the cached scan; a staged revision does not\n";
$GLOBALS['__test_posts'] = array();
$h3_block = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Section</h3>', 'innerContent' => array( '<h3>Section</h3>' ) );
$fp       = snt_block_fp_fingerprint( $h3_block, 407, '0/0' );
_bma_post( 407, array( $h3_block ) );
$seed = array(
	'candidates' => array(
		array( 'post_id' => 407, 'migration_type' => 'heading-hierarchy-skip', 'block_fingerprint' => $fp ),
		array( 'post_id' => 408, 'migration_type' => 'heading-hierarchy-skip', 'block_fingerprint' => 'other' ),
	),
	'counts'     => array( 'heading_hierarchy_skip' => 2, 'posts_affected' => 2 ),
	'scanned_at' => time() - 60,
);
$key = 'snt_block_migrations_candidates_7';
$GLOBALS['__transients'][ $key ] = $seed;
// 2026-10-12 review: the cache is edited only after a write that took. A
// stale fingerprint (409) must leave it byte-identical.
$stale = snt_block_migrations_apply_impl( 407, str_repeat( 'f', 32 ), $replacement_markup, 'heading-hierarchy-skip' );
bma_true( is_wp_error( $stale ) && 'snt_block_migration_conflict' === $stale->get_error_code() && serialize( $GLOBALS['__transients'][ $key ] ) === serialize( $seed ), 'Test 8.0: a refused apply (409, stale fingerprint) leaves the cached scan byte-identical' );
$stale_key = array_merge( $seed, array( 'candidates' => array( array( 'post_id' => 407, 'migration_type' => 'heading-hierarchy-skip', 'block_fingerprint' => str_repeat( 'f', 32 ) ) ) ) );
$GLOBALS['__transients'][ $key ] = $stale_key;
snt_block_migrations_apply_impl( 407, str_repeat( 'f', 32 ), $replacement_markup, 'heading-hierarchy-skip' );
bma_true( serialize( $GLOBALS['__transients'][ $key ] ) === serialize( $stale_key ), 'Test 8.0b: even when the cache lists that very fingerprint, a refused apply keeps its row (it is still there to fix)' );
$GLOBALS['__transients'][ $key ] = $seed;
$staged = snt_block_migrations_apply_impl( 407, $fp, $replacement_markup, 'heading-hierarchy-skip', static function () { return 1; } );
bma_true( is_array( $staged ) && $GLOBALS['__transients'][ $key ] === $seed, 'Test 8.1: a staged revision (write callback) leaves the cached scan as it was' );
$result = snt_block_migrations_apply_impl( 407, $fp, $replacement_markup, 'heading-hierarchy-skip' );
$left   = $GLOBALS['__transients'][ $key ] ?? null;
bma_true( is_array( $result ) && ! empty( $result['ok'] ), 'Test 8.2: the live apply succeeds' );
bma_eq( array( 'other' ), array_column( (array) ( $left['candidates'] ?? array() ), 'block_fingerprint' ), 'Test 8.3: only the applied row left the cache' );
bma_eq( array( 'heading_hierarchy_skip' => 1, 'posts_affected' => 1 ), $left['counts'] ?? null, 'Test 8.4: its count and the posts count follow' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
