<?php
/**
 * Suggest impl tests — verifies deterministic template substitution
 * produces the expected pull-quote and steps-enumerated markup from
 * the source core/quote and ordered core/list blocks.
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
if ( ! function_exists( 'wp_kses' ) ) {
	// Test stub: minimal HTML allowlist enforcement — preserves the tags
	// named in $allowed_html's keys and strips everything else. Adequate
	// for these tests, which only exercise structural tag preservation.
	//
	// DOES NOT replicate real WP wp_kses behavior in two security-relevant
	// ways:
	//   1. URL scheme validation — real WP strips javascript: / vbscript:
	//      / data: URLs via wp_kses_bad_protocol; this stub lets them
	//      through unchanged. <a href="javascript:alert(1)">x</a> passes
	//      verbatim here but would be sanitized in production.
	//   2. Attribute allowlist enforcement — real WP rejects attributes
	//      not in the per-tag allowlist (onclick, onerror, style, etc.);
	//      this stub keeps any attributes the source tag had.
	//
	// DO NOT add tests against this stub that assert URL-scheme or
	// attribute-injection sanitization — they would pass against the stub
	// and FALSELY validate behavior that production would handle
	// differently. Such tests belong in a WP-loaded integration suite.
	function wp_kses( $content, $allowed_html ) {
		$keep = array_keys( (array) $allowed_html );
		$allow_str = '';
		foreach ( $keep as $tag ) {
			$allow_str .= '<' . $tag . '>';
		}
		return strip_tags( (string) $content, $allow_str );
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
ps_contains( $result['suggestion_markup'], 'sn-pattern-pull-quote', 'Test 1.3: suggestion uses sn-pattern-pull-quote className' );
ps_contains( $result['suggestion_markup'], 'sn-pull-quote__body', 'Test 1.4: body paragraph className present' );
ps_contains( $result['suggestion_markup'], 'always loses on cost', 'Test 1.5: original quote text preserved in suggestion' );
ps_contains( $result['suggestion_markup'], 'sn-pull-quote__attribution', 'Test 1.6: attribution paragraph present (fixture has cite)' );
ps_contains( $result['suggestion_markup'], 'Juan', 'Test 1.7: cite text preserved' );
ps_eq( 'pull-quote', $result['pattern_type'], 'Test 1.8: pattern_type echoed back' );
ps_eq( $fp, $result['fingerprint'], 'Test 1.9: fingerprint echoed back' );

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
ps_contains( $result['suggestion_markup'], 'sn-pattern-steps-enumerated', 'Test 2.2: suggestion uses sn-pattern-steps-enumerated className' );
ps_contains( $result['suggestion_markup'], 'sn-steps__list', 'Test 2.3: list className present' );
ps_contains( $result['suggestion_markup'], 'First step.', 'Test 2.4: first list item preserved' );
ps_contains( $result['suggestion_markup'], 'Second step.', 'Test 2.5: second list item preserved' );
ps_true( false === strpos( $result['suggestion_markup'], 'sn-steps__label' ), 'Test 2.6: label paragraph omitted per spec §5.4' );

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

// ─── Test 6: pull-quote WITHOUT <cite> — attribution paragraph omitted ───
echo "\nTest 6: pull-quote with no cite — attribution omitted\n";
$quote_no_cite = array(
	'blockName'   => 'core/quote',
	'attrs'       => array(),
	'innerBlocks' => array(
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>A quote without attribution.</p>' ),
	),
	'innerHTML'   => '<blockquote class="wp-block-quote"></blockquote>',
);
_tas_post( 206, array( $quote_no_cite ) );
$fp = md5( serialize_block( $quote_no_cite ) );
$result = snt_ai_pattern_adoption_suggest_impl( 206, $fp, 'pull-quote' );
ps_true( is_array( $result ), 'Test 6.1: result is array' );
ps_true( false === strpos( $result['suggestion_markup'], 'sn-pull-quote__attribution' ), 'Test 6.2: attribution paragraph absent when no cite' );

// ─── Test 7: pull-quote with inline formatting preserved ─────────────
echo "\nTest 7: pull-quote preserves inline <strong>, <em>, <a>\n";
$quote_inline = array(
	'blockName'   => 'core/quote',
	'attrs'       => array(),
	'innerBlocks' => array(
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>The <strong>classifier</strong> loses on <a href="https://example.com">cost</a>.</p>' ),
	),
	'innerHTML'   => '<blockquote class="wp-block-quote"><cite>Juan</cite></blockquote>',
);
_tas_post( 207, array( $quote_inline ) );
$fp = md5( serialize_block( $quote_inline ) );
$result = snt_ai_pattern_adoption_suggest_impl( 207, $fp, 'pull-quote' );
ps_true( is_array( $result ), 'Test 7.1: result is array' );
ps_contains( $result['suggestion_markup'], '<strong>classifier</strong>', 'Test 7.2: <strong> preserved' );
ps_contains( $result['suggestion_markup'], 'href="https://example.com"', 'Test 7.3: <a href> preserved' );

// ─── Test 8: steps-enumerated with inline formatting in list items ────
echo "\nTest 8: steps-enumerated preserves inline formatting in items\n";
$list_inline = array(
	'blockName'   => 'core/list',
	'attrs'       => array( 'ordered' => true ),
	'innerBlocks' => array(
		array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<li><strong>Capture</strong> at session start.</li>' ),
		array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<li>Embed <em>C2PA manifest</em>.</li>' ),
	),
	'innerHTML'   => '<ol class="wp-block-list"></ol>',
);
_tas_post( 208, array( $list_inline ) );
$fp = md5( serialize_block( $list_inline ) );
$result = snt_ai_pattern_adoption_suggest_impl( 208, $fp, 'steps-enumerated' );
ps_true( is_array( $result ), 'Test 8.1: result is array' );
ps_contains( $result['suggestion_markup'], '<strong>Capture</strong>', 'Test 8.2: <strong> preserved in item' );
ps_contains( $result['suggestion_markup'], '<em>C2PA manifest</em>', 'Test 8.3: <em> preserved in item' );

// ─── Test 9: pull-quote with empty innerBlocks (degenerate) ──────────
echo "\nTest 9: pull-quote with no paragraphs — empty body, no fatal\n";
$quote_empty = array(
	'blockName'   => 'core/quote',
	'attrs'       => array(),
	'innerBlocks' => array(),
	'innerHTML'   => '<blockquote class="wp-block-quote"><cite>Author</cite></blockquote>',
);
_tas_post( 209, array( $quote_empty ) );
$fp = md5( serialize_block( $quote_empty ) );
$result = snt_ai_pattern_adoption_suggest_impl( 209, $fp, 'pull-quote' );
ps_true( is_array( $result ), 'Test 9.1: degenerate quote produces result (no fatal)' );
ps_contains( $result['suggestion_markup'], 'sn-pull-quote__body', 'Test 9.2: body paragraph still present (empty text)' );
ps_contains( $result['suggestion_markup'], 'Author', 'Test 9.3: cite still extracted' );

// ─── Test 10: steps-enumerated with empty list (zero items) ──────────
echo "\nTest 10: steps-enumerated with empty list — valid wrapper, no items\n";
$list_empty = array(
	'blockName'   => 'core/list',
	'attrs'       => array( 'ordered' => true ),
	'innerBlocks' => array(),
	'innerHTML'   => '<ol class="wp-block-list"></ol>',
);
_tas_post( 210, array( $list_empty ) );
$fp = md5( serialize_block( $list_empty ) );
$result = snt_ai_pattern_adoption_suggest_impl( 210, $fp, 'steps-enumerated' );
ps_true( is_array( $result ), 'Test 10.1: degenerate list produces result' );
ps_contains( $result['suggestion_markup'], 'sn-steps__list', 'Test 10.2: list wrapper still present' );
ps_true( false === strpos( $result['suggestion_markup'], 'wp:list-item' ), 'Test 10.3: no list-item blocks for empty source' );

// ─── Test 11: cite with only empty inline tags → attribution omitted ──
echo "\nTest 11: cite with only empty inline tags — attribution omitted\n";
$quote_empty_cite = array(
	'blockName'   => 'core/quote',
	'attrs'       => array(),
	'innerBlocks' => array(
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Body.</p>' ),
	),
	'innerHTML'   => '<blockquote class="wp-block-quote"><cite><em></em></cite></blockquote>',
);
_tas_post( 211, array( $quote_empty_cite ) );
$fp = md5( serialize_block( $quote_empty_cite ) );
$result = snt_ai_pattern_adoption_suggest_impl( 211, $fp, 'pull-quote' );
ps_true( is_array( $result ), 'Test 11.1: result is array (not WP_Error)' );
ps_true( false === strpos( $result['suggestion_markup'], 'sn-pull-quote__attribution' ), 'Test 11.2: attribution paragraph omitted when cite has only empty inline tags' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
