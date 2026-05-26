<?php
/**
 * Standalone fixture tests for v4.3.0's pattern-adoption detector.
 *
 * Stubs parse_blocks/get_post_meta so tests run without a WP load. The
 * detector logic under test is the block-tree walk that identifies
 * core/quote (pull-quote candidates) and core/list with ordered:true
 * (steps-enumerated candidates), and the dismiss-filter that excludes
 * fingerprints present in _snt_pattern_adoption_dismissed post meta.
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
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// ─── WP function stubs ───────────────────────────────────────────────
$GLOBALS['__test_posts']     = array();
$GLOBALS['__test_post_meta'] = array();

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		return array_values( $GLOBALS['__test_posts'] );
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		$val = $GLOBALS['__test_post_meta'][ $post_id ][ $key ] ?? array();
		return $single ? ( is_array( $val ) ? ( $val[0] ?? '' ) : $val ) : $val;
	}
}
if ( ! function_exists( 'parse_blocks' ) ) {
	// Stub: tests assign $post->post_content to an ALREADY-PARSED array
	// (as a JSON string in the field), so parse_blocks decodes it.
	function parse_blocks( $content ) {
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
if ( ! function_exists( 'serialize_block' ) ) {
	// Stub: deterministic serialization for fingerprint computation.
	function serialize_block( $block ) {
		return json_encode( $block );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return $GLOBALS['__test_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $val, $ttl ) {
		$GLOBALS['__test_transients'][ $key ] = $val;
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 1; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) { return 'https://example.test/?p=' . (int) $post_id; }
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

// Helper to register a fixture post.
function _ta_post( $id, $blocks_array ) {
	$post = new stdClass();
	$post->ID           = $id;
	$post->post_status  = 'publish';
	$post->post_type    = 'post';
	$post->post_title   = "Fixture $id";
	$post->post_content = json_encode( $blocks_array );
	$GLOBALS['__test_posts'][ $id ] = $post;
}

require_once __DIR__ . '/../inc/pattern-adoption-detect.php';

// ─── Harness ──────────────────────────────────────────────────────────
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

echo "Pattern-adoption detector suite — plugin v4.3.0\n";

// ─── Test 1: pull-quote candidate detection ──────────────────────────
echo "\nTest 1: pull-quote detection (core/quote)\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_ta_post( 101, array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Intro text.</p>' ),
	array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>The classifier always loses on cost.</p>' ),
	), 'innerHTML' => '<blockquote class="wp-block-quote"><cite>Author</cite></blockquote>' ),
) );

$candidates = snt_pattern_adoption_detect_candidates();
pa_eq( 1, count( $candidates ), 'Test 1.1: single pull-quote candidate found' );
pa_eq( 'pull-quote', $candidates[0]['pattern_type'], 'Test 1.2: pattern_type = pull-quote' );
pa_eq( 101, $candidates[0]['post_id'], 'Test 1.3: post_id matches fixture' );
pa_true( ! empty( $candidates[0]['block_fingerprint'] ), 'Test 1.4: fingerprint is non-empty' );

// ─── Test 2: steps-enumerated candidate detection ────────────────────
echo "\nTest 2: steps-enumerated detection (core/list, ordered: true)\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_ta_post( 102, array(
	array( 'blockName' => 'core/list', 'attrs' => array( 'ordered' => true ), 'innerBlocks' => array(
		array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<li>First step.</li>' ),
		array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<li>Second step.</li>' ),
	), 'innerHTML' => '<ol class="wp-block-list"></ol>' ),
) );
$candidates = snt_pattern_adoption_detect_candidates();
pa_eq( 1, count( $candidates ), 'Test 2.1: single steps-enumerated candidate found' );
pa_eq( 'steps-enumerated', $candidates[0]['pattern_type'], 'Test 2.2: pattern_type = steps-enumerated' );

// ─── Test 3: unordered list NOT a candidate ──────────────────────────
echo "\nTest 3: unordered list excluded (ordered defaults to false)\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_ta_post( 103, array(
	array( 'blockName' => 'core/list', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<ul></ul>' ),
) );
$candidates = snt_pattern_adoption_detect_candidates();
pa_eq( 0, count( $candidates ), 'Test 3.1: unordered list produces zero candidates' );

// ─── Test 4: dismiss filter excludes fingerprints ────────────────────
echo "\nTest 4: dismiss filter\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_ta_post( 104, array(
	array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<blockquote>Dismissed quote.</blockquote>' ),
) );
// Pre-compute the fingerprint the detector will produce and mark it dismissed.
$block_for_fp = array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<blockquote>Dismissed quote.</blockquote>' );
$fp_dismissed = md5( serialize_block( $block_for_fp ) );
$GLOBALS['__test_post_meta'][ 104 ]['_snt_pattern_adoption_dismissed'] = array( array( 'pull-quote:' . $fp_dismissed ) );
$candidates = snt_pattern_adoption_detect_candidates();
pa_eq( 0, count( $candidates ), 'Test 4.1: dismissed fingerprint excluded from candidates' );

// ─── Test 5: multiple candidates across one post ─────────────────────
echo "\nTest 5: multiple candidates in one post\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_ta_post( 105, array(
	array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<blockquote>One.</blockquote>' ),
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Middle.</p>' ),
	array( 'blockName' => 'core/list', 'attrs' => array( 'ordered' => true ), 'innerBlocks' => array(), 'innerHTML' => '<ol></ol>' ),
	array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<blockquote>Two.</blockquote>' ),
) );
$candidates = snt_pattern_adoption_detect_candidates();
pa_eq( 3, count( $candidates ), 'Test 5.1: three candidates in one post' );
// Count by type
$by_type = array( 'pull-quote' => 0, 'steps-enumerated' => 0 );
foreach ( $candidates as $c ) { $by_type[ $c['pattern_type'] ]++; }
pa_eq( 2, $by_type['pull-quote'], 'Test 5.2: two pull-quote candidates' );
pa_eq( 1, $by_type['steps-enumerated'], 'Test 5.3: one steps-enumerated candidate' );

// ─── Test 6: nested core/quote inside core/group still detected ──────
echo "\nTest 6: nested candidate inside group\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_ta_post( 106, array(
	array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(
		array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<blockquote>Nested.</blockquote>' ),
	), 'innerHTML' => '<div class="wp-block-group"></div>' ),
) );
$candidates = snt_pattern_adoption_detect_candidates();
pa_eq( 1, count( $candidates ), 'Test 6.1: nested core/quote found via innerBlocks walk' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
