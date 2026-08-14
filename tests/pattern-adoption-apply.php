<?php
/**
 * Apply impl tests — verifies fingerprint-validated block replacement
 * through parse_blocks ↔ serialize_blocks round-trip.
 *
 * @since plugin v4.3.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$GLOBALS['__test_posts']      = array();
$GLOBALS['__test_wp_updates'] = array();

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
	function serialize_blocks( $blocks ) { return json_encode( $blocks ); }
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) {
		// Switch: tests that need to exercise the write-failure path set
		// $GLOBALS['__test_force_wp_error'] = true. Default behavior is
		// to record the call + apply to the in-memory fixture.
		if ( ! empty( $GLOBALS['__test_force_wp_error'] ) ) {
			return new WP_Error( 'forced_for_test', 'Forced WP_Error for apply error-path test.' );
		}
		$GLOBALS['__test_wp_updates'][] = $args;
		// Apply the update to the in-memory fixture so subsequent reads see it.
		$id = (int) ( $args['ID'] ?? 0 );
		if ( isset( $GLOBALS['__test_posts'][ $id ] ) ) {
			$GLOBALS['__test_posts'][ $id ]->post_content = (string) ( $args['post_content'] ?? '' );
		}
		return $id;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() {
		// Switch: tests that need to exercise the capability-denied path
		// set $GLOBALS['__test_caps'] = false. Unset/null defaults to true.
		return array_key_exists( '__test_caps', $GLOBALS ) ? (bool) $GLOBALS['__test_caps'] : true;
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
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = '' ) { return $s; }
}
// Input-aware wp_kses_post model: actually strips <script> + inline event
// handlers (a no-op stub would make the XSS wiring test a false-green). The
// real wp_kses_post allows post-content tags; for this fixture only the
// stripping behavior matters.
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
		$html = preg_replace( '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html );
		return (string) $html;
	}
}

// Minimal stub so the REST callback's type-hint resolves at file load.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public $params = array();
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	}
}

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = null ) {
		$this->code = $c; $this->message = $m; $this->data = $d;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}

function _taa_post( $id, $blocks_array ) {
	$post = new stdClass();
	$post->ID           = $id;
	$post->post_content = json_encode( $blocks_array );
	$GLOBALS['__test_posts'][ $id ] = $post;
}

require_once __DIR__ . '/../inc/block-fingerprint-engine.php'; // v7.7.1 shared engine
require_once __DIR__ . '/../inc/pattern-adoption-apply.php';

$pass = 0; $fail = 0;
function pa_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function pa_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Pattern-adoption apply suite — plugin v4.3.0\n";

// ─── Test 1: happy path replaces the block ───────────────────────────
echo "\nTest 1: apply replaces matching block via fingerprint\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_wp_updates'] = array();

$source_block = array(
	'blockName'   => 'core/quote',
	'attrs'       => array(),
	'innerBlocks' => array(),
	'innerHTML'   => '<blockquote>Original.</blockquote>',
);
_taa_post( 301, array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Before.</p>' ),
	$source_block,
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>After.</p>' ),
) );

$fp = snt_block_fp_fingerprint( $source_block, 301, '0/1' );
$replacement = json_encode( array( array(
	'blockName'   => 'core/group',
	'attrs'       => array( 'className' => 'sn-pattern-pull-quote' ),
	'innerBlocks' => array(),
	'innerHTML'   => '<aside class="wp-block-group sn-pattern-pull-quote">Replaced.</aside>',
) ) );

$result = snt_ai_pattern_adoption_apply_impl( 301, $fp, $replacement, 'pull-quote' );
pa_true( is_array( $result ), 'Test 1.1: result is array (not WP_Error)' );
pa_eq( true, $result['ok'], 'Test 1.2: result.ok = true' );
pa_eq( 301, $result['post_id'], 'Test 1.3: post_id echoed' );
pa_eq( 'pull-quote', $result['replaced_pattern_type'], 'Test 1.4: pattern_type echoed' );
pa_eq( 1, count( $GLOBALS['__test_wp_updates'] ), 'Test 1.5: exactly one wp_update_post call' );

// The new post_content (the wp_update_post args) should contain the replacement.
$new_content = $GLOBALS['__test_wp_updates'][0]['post_content'];
pa_true( false !== strpos( $new_content, 'sn-pattern-pull-quote' ), 'Test 1.6: new content contains pull-quote pattern className' );
pa_true( false === strpos( $new_content, 'Original.' ), 'Test 1.7: original quote text removed' );
pa_true( false !== strpos( $new_content, 'Before.' ), 'Test 1.8: sibling content preserved' );
pa_true( false !== strpos( $new_content, 'After.' ), 'Test 1.9: sibling content preserved' );

// ─── Test 2: block no longer present (fingerprint mismatch) ──────────
echo "\nTest 2: conflict when block changed since scan\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_wp_updates'] = array();
_taa_post( 302, array(
	array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<blockquote>Different.</blockquote>' ),
) );
$result = snt_ai_pattern_adoption_apply_impl( 302, 'deadbeef' . str_repeat( '0', 24 ), $replacement, 'pull-quote' );
pa_true( is_wp_error( $result ), 'Test 2.1: result is WP_Error' );
pa_eq( 'snt_pattern_adoption_conflict', $result->get_error_code(), 'Test 2.2: error code = conflict' );
pa_eq( 0, count( $GLOBALS['__test_wp_updates'] ), 'Test 2.3: no wp_update_post call' );

// ─── Test 3: post not found ──────────────────────────────────────────
echo "\nTest 3: post not found\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_wp_updates'] = array();
$result = snt_ai_pattern_adoption_apply_impl( 999999, 'anything', $replacement, 'pull-quote' );
pa_true( is_wp_error( $result ), 'Test 3.1: result is WP_Error' );
pa_eq( 'snt_pattern_adoption_post_not_found', $result->get_error_code(), 'Test 3.2: error code correct' );

// ─── Test 4: invalid pattern_type ────────────────────────────────────
echo "\nTest 4: invalid pattern_type rejected\n";
$GLOBALS['__test_posts'] = array();
_taa_post( 304, array( $source_block ) );
$fp = snt_block_fp_fingerprint( $source_block, 304, '0/0' );
$result = snt_ai_pattern_adoption_apply_impl( 304, $fp, $replacement, 'compare-columns' );
pa_true( is_wp_error( $result ), 'Test 4.1: result is WP_Error' );
pa_eq( 'snt_pattern_adoption_invalid_pattern_type', $result->get_error_code(), 'Test 4.2: error code correct' );

// ─── Test 5: nested block (inside core/group) replaced correctly ─────
echo "\nTest 5: nested block replacement preserves group structure\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_wp_updates'] = array();
$nested_quote = array(
	'blockName'   => 'core/quote',
	'attrs'       => array(),
	'innerBlocks' => array(),
	'innerHTML'   => '<blockquote>Nested.</blockquote>',
);
_taa_post( 305, array(
	array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(
		$nested_quote,
	), 'innerHTML' => '<div class="wp-block-group"></div>' ),
) );
$fp = snt_block_fp_fingerprint( $nested_quote, 305, '0/0/innerBlocks/0' );
$result = snt_ai_pattern_adoption_apply_impl( 305, $fp, $replacement, 'pull-quote' );
pa_true( is_array( $result ), 'Test 5.1: nested replacement succeeds' );
pa_eq( 1, count( $GLOBALS['__test_wp_updates'] ), 'Test 5.2: one wp_update_post call' );
$new_content = $GLOBALS['__test_wp_updates'][0]['post_content'];
pa_true( false !== strpos( $new_content, 'wp-block-group' ), 'Test 5.3: outer group preserved (innerHTML survived)' );
pa_true( false !== strpos( $new_content, 'sn-pattern-pull-quote' ), 'Test 5.4: nested block replaced' );
pa_true( false === strpos( $new_content, 'Nested.' ), 'Test 5.5: original nested content removed' );

// ─── Test 6: capability denial returns 403 ──────────────────────────
echo "\nTest 6: current_user_can returns false → 403 capability error\n";
$GLOBALS['__test_posts']       = array();
$GLOBALS['__test_wp_updates']  = array();
$GLOBALS['__test_caps']        = false;
_taa_post( 306, array( $source_block ) );
$fp     = snt_block_fp_fingerprint( $source_block, 306, '0/0' );
$result = snt_ai_pattern_adoption_apply_impl( 306, $fp, $replacement, 'pull-quote' );
pa_true( is_wp_error( $result ), 'Test 6.1: result is WP_Error' );
pa_eq( 'snt_pattern_adoption_capability', $result->get_error_code(), 'Test 6.2: error code = capability' );
pa_eq( 0, count( $GLOBALS['__test_wp_updates'] ), 'Test 6.3: no wp_update_post call' );
unset( $GLOBALS['__test_caps'] );

// ─── Test 7: malformed replacement_markup rejected ───────────────────
// Note: apply.php currently reuses snt_pattern_adoption_invalid_pattern_type
// for both "type not in enum" and "replacement_markup didn't parse". A future
// release could introduce a distinct snt_pattern_adoption_invalid_replacement_markup
// code; this test asserts the existing semantic to lock the current contract.
echo "\nTest 7: empty/garbage replacement_markup → 422 invalid_pattern_type\n";
$GLOBALS['__test_posts']      = array();
$GLOBALS['__test_wp_updates'] = array();
_taa_post( 307, array( $source_block ) );
$fp     = snt_block_fp_fingerprint( $source_block, 307, '0/0' );
$result = snt_ai_pattern_adoption_apply_impl( 307, $fp, '', 'pull-quote' );
pa_true( is_wp_error( $result ), 'Test 7.1: empty replacement is WP_Error' );
pa_eq( 'snt_pattern_adoption_invalid_pattern_type', $result->get_error_code(), 'Test 7.2: error code = invalid_pattern_type (existing conflation)' );
pa_eq( 0, count( $GLOBALS['__test_wp_updates'] ), 'Test 7.3: no wp_update_post call for empty markup' );

// ─── Test 8: wp_update_post returns WP_Error → 500 write_failed ──────
echo "\nTest 8: wp_update_post WP_Error → 500 write_failed\n";
$GLOBALS['__test_posts']            = array();
$GLOBALS['__test_wp_updates']       = array();
$GLOBALS['__test_force_wp_error']   = true;
_taa_post( 308, array( $source_block ) );
$fp     = snt_block_fp_fingerprint( $source_block, 308, '0/0' );
$result = snt_ai_pattern_adoption_apply_impl( 308, $fp, $replacement, 'pull-quote' );
pa_true( is_wp_error( $result ), 'Test 8.1: result is WP_Error' );
pa_eq( 'snt_pattern_adoption_write_failed', $result->get_error_code(), 'Test 8.2: error code = write_failed' );
unset( $GLOBALS['__test_force_wp_error'] );

// ─── Test 9: replacement markup is sanitized before write (v6.39.2) ──
//
// replacement_markup can be user-edited in the modal, so it is untrusted. The
// apply path must run the replacement block's inner HTML through wp_kses_post
// (block-aware: sanitize the parsed node's HTML, NOT the raw serialized string,
// which would strip the <!-- wp --> delimiters) before persisting. A <script>
// in the replacement must never reach post_content.
echo "\nTest 9: replacement_markup <script> is stripped before wp_update_post\n";
$GLOBALS['__test_posts']      = array();
$GLOBALS['__test_wp_updates'] = array();
_taa_post( 309, array( $source_block ) );
$fp = snt_block_fp_fingerprint( $source_block, 309, '0/0' );
$xss_replacement = json_encode( array( array(
	'blockName'   => 'core/pullquote',
	'attrs'       => array(),
	'innerBlocks' => array(),
	'innerHTML'   => '<figure class="wp-block-pullquote"><blockquote>Pull.<script>alert(document.cookie)</script></blockquote></figure>',
) ) );
$result = snt_ai_pattern_adoption_apply_impl( 309, $fp, $xss_replacement, 'pull-quote' );
pa_true( is_array( $result ), 'Test 9.1: apply still succeeds with sanitizable markup' );
$new_content = $GLOBALS['__test_wp_updates'][0]['post_content'];
pa_true( false === strpos( $new_content, '<script' ), 'Test 9.2: <script> stripped from persisted content' );
pa_true( false === strpos( $new_content, 'alert(document.cookie)' ), 'Test 9.3: script payload gone' );
pa_true( false !== strpos( $new_content, 'Pull.' ), 'Test 9.4: legitimate block text preserved' );
pa_true( false !== strpos( $new_content, 'wp-block-pullquote' ), 'Test 9.5: legitimate block markup preserved' );

// Nested innerBlocks are sanitized recursively.
echo "\nTest 10: nested innerBlocks markup is sanitized recursively\n";
$GLOBALS['__test_posts']      = array();
$GLOBALS['__test_wp_updates'] = array();
_taa_post( 310, array( $source_block ) );
$fp = snt_block_fp_fingerprint( $source_block, 310, '0/0' );
$nested_xss = json_encode( array( array(
	'blockName'   => 'core/group',
	'attrs'       => array(),
	'innerBlocks' => array(
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>ok<script>steal()</script></p>' ),
	),
	'innerHTML'   => '<div class="wp-block-group"></div>',
) ) );
$result = snt_ai_pattern_adoption_apply_impl( 310, $fp, $nested_xss, 'pull-quote' );
pa_true( is_array( $result ), 'Test 10.1: nested apply succeeds' );
$new_content = $GLOBALS['__test_wp_updates'][0]['post_content'];
pa_true( false === strpos( $new_content, 'steal()' ), 'Test 10.2: nested <script> payload stripped' );
pa_true( false !== strpos( $new_content, 'ok' ), 'Test 10.3: nested legitimate text preserved' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
