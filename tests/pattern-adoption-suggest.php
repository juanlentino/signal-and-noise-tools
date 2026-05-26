<?php
/**
 * Suggest impl tests — verifies deterministic template substitution
 * produces the expected pull-quote and steps-enumerated markup from
 * the source core/quote and ordered core/list blocks.
 *
 * @since plugin v4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$GLOBALS['__test_posts'] = array();

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
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() { return true; }
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

function _tas_post( $id, $blocks_array ) {
	$post = new stdClass();
	$post->ID           = $id;
	$post->post_content = json_encode( $blocks_array );
	$GLOBALS['__test_posts'][ $id ] = $post;
}

require_once __DIR__ . '/../inc/pattern-adoption-suggest.php';

$pass = 0; $fail = 0;
function ps_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ps_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}
function ps_contains( $haystack, $needle, $msg ) {
	global $pass, $fail;
	if ( false !== strpos( (string) $haystack, (string) $needle ) ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg (searching for '$needle' in haystack)\n"; }
}

echo "Pattern-adoption suggest suite — plugin v4.3.0\n";

// ─── Test 1: pull-quote suggest happy path ───────────────────────────
echo "\nTest 1: pull-quote suggestion from core/quote\n";
$quote_block = array(
	'blockName'   => 'core/quote',
	'attrs'       => array(),
	'innerBlocks' => array(
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>The classifier always loses on cost before it loses on accuracy.</p>' ),
	),
	'innerHTML'   => '<blockquote class="wp-block-quote"><cite>Juan</cite></blockquote>',
);
_tas_post( 201, array( $quote_block ) );
$fp = md5( serialize_block( $quote_block ) );
$result = snt_ai_pattern_adoption_suggest_impl( 201, $fp, 'pull-quote' );
ps_true( is_array( $result ), 'Test 1.1: result is array (not WP_Error)' );
ps_true( ! empty( $result['suggestion_markup'] ), 'Test 1.2: suggestion_markup non-empty' );
ps_contains( $result['suggestion_markup'], 'wp:signal-noise/pull-quote', 'Test 1.3: suggestion uses pull-quote pattern block markup' );
ps_contains( $result['suggestion_markup'], 'always loses on cost', 'Test 1.4: original quote text preserved in suggestion' );
ps_eq( 'pull-quote', $result['pattern_type'], 'Test 1.5: pattern_type echoed back' );
ps_eq( $fp, $result['fingerprint'], 'Test 1.6: fingerprint echoed back' );

// ─── Test 2: steps-enumerated suggest happy path ─────────────────────
echo "\nTest 2: steps-enumerated suggestion from ordered core/list\n";
$list_block = array(
	'blockName'   => 'core/list',
	'attrs'       => array( 'ordered' => true ),
	'innerBlocks' => array(
		array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<li>First step.</li>' ),
		array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<li>Second step.</li>' ),
	),
	'innerHTML'   => '<ol class="wp-block-list"></ol>',
);
_tas_post( 202, array( $list_block ) );
$fp = md5( serialize_block( $list_block ) );
$result = snt_ai_pattern_adoption_suggest_impl( 202, $fp, 'steps-enumerated' );
ps_true( is_array( $result ), 'Test 2.1: result is array (not WP_Error)' );
ps_contains( $result['suggestion_markup'], 'wp:signal-noise/steps-enumerated', 'Test 2.2: suggestion uses steps-enumerated pattern markup' );
ps_contains( $result['suggestion_markup'], 'First step.', 'Test 2.3: first list item preserved' );
ps_contains( $result['suggestion_markup'], 'Second step.', 'Test 2.4: second list item preserved' );

// ─── Test 3: candidate not found (fingerprint mismatch) ──────────────
echo "\nTest 3: candidate not found returns WP_Error 404\n";
_tas_post( 203, array( $quote_block ) );
$result = snt_ai_pattern_adoption_suggest_impl( 203, 'deadbeef' . str_repeat( '0', 24 ), 'pull-quote' );
ps_true( is_wp_error( $result ), 'Test 3.1: result is WP_Error' );
ps_eq( 'snt_pattern_adoption_candidate_not_found', $result->get_error_code(), 'Test 3.2: error code correct' );

// ─── Test 4: invalid pattern_type returns WP_Error 422 ───────────────
echo "\nTest 4: invalid pattern_type rejected\n";
_tas_post( 204, array( $quote_block ) );
$fp = md5( serialize_block( $quote_block ) );
$result = snt_ai_pattern_adoption_suggest_impl( 204, $fp, 'compare-columns' );
ps_true( is_wp_error( $result ), 'Test 4.1: result is WP_Error' );
ps_eq( 'snt_pattern_adoption_invalid_pattern_type', $result->get_error_code(), 'Test 4.2: error code correct' );

// ─── Test 5: post not found ──────────────────────────────────────────
echo "\nTest 5: post not found returns WP_Error 404\n";
$result = snt_ai_pattern_adoption_suggest_impl( 999999, 'anything', 'pull-quote' );
ps_true( is_wp_error( $result ), 'Test 5.1: result is WP_Error' );
ps_eq( 'snt_pattern_adoption_post_not_found', $result->get_error_code(), 'Test 5.2: error code correct' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
