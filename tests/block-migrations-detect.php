<?php
/**
 * Standalone fixture tests for v4.5.0's block-migrations detector.
 *
 * Stubs parse_blocks/get_post_meta so tests run without a WP load. The
 * detector under test walks parse_blocks() output for all published posts
 * and identifies core/heading blocks with attrs.level === 3 that have NO
 * preceding core/heading with attrs.level === 2 in the same post.
 *
 * @since plugin v4.5.0
 */

// SECURITY: CLI-only.
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

// ─── WP function stubs (mirrors tests/pattern-adoption-detect.php) ───
$GLOBALS['__test_posts']      = array();
$GLOBALS['__test_post_meta']  = array();
$GLOBALS['__test_transients'] = array();

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) { return array_values( $GLOBALS['__test_posts'] ); }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		$val = $GLOBALS['__test_post_meta'][ $post_id ][ $key ] ?? array();
		return $single ? ( is_array( $val ) ? ( $val[0] ?? '' ) : $val ) : $val;
	}
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

// Fixture helper.
function _bm_post( $id, $blocks_array ) {
	$post = new stdClass();
	$post->ID           = $id;
	$post->post_status  = 'publish';
	$post->post_type    = 'post';
	$post->post_title   = "Fixture $id";
	$post->post_content = json_encode( $blocks_array );
	$GLOBALS['__test_posts'][ $id ] = $post;
}

require_once __DIR__ . '/../inc/block-migrations-detect.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function bm_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function bm_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Block-migrations detector suite — plugin v4.5.0\n";

// ─── Test 1: h3 with no preceding h2 IS a candidate ─────────────────
echo "\nTest 1: h3 with no preceding h2 is a candidate\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_bm_post( 201, array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Intro.</p>' ),
	array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Subsection</h3>', 'innerContent' => array( '<h3>Subsection</h3>' ) ),
) );
$candidates = snt_block_migrations_detect_candidates();
bm_eq( 1, count( $candidates ), 'Test 1.1: single heading-skip candidate found' );
bm_eq( 'heading-hierarchy-skip', $candidates[0]['migration_type'], 'Test 1.2: migration_type set' );
bm_eq( 201, $candidates[0]['post_id'], 'Test 1.3: post_id matches' );
bm_true( ! empty( $candidates[0]['block_fingerprint'] ), 'Test 1.4: fingerprint is non-empty' );

// ─── Test 2: valid hierarchy h1→h2→h3 is NOT a candidate ────────────
echo "\nTest 2: valid hierarchy h2 → h3 is not a candidate\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_bm_post( 202, array(
	array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 2 ), 'innerBlocks' => array(), 'innerHTML' => '<h2>Section</h2>', 'innerContent' => array( '<h2>Section</h2>' ) ),
	array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Sub</h3>', 'innerContent' => array( '<h3>Sub</h3>' ) ),
) );
$candidates = snt_block_migrations_detect_candidates();
bm_eq( 0, count( $candidates ), 'Test 2.1: h3 after h2 produces zero candidates' );

// ─── Test 3: multiple h3-skips in one post ──────────────────────────
echo "\nTest 3: multiple h3-skips in one post\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_bm_post( 203, array(
	array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>One</h3>', 'innerContent' => array( '<h3>One</h3>' ) ),
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>p</p>' ),
	array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Two</h3>', 'innerContent' => array( '<h3>Two</h3>' ) ),
) );
$candidates = snt_block_migrations_detect_candidates();
bm_eq( 2, count( $candidates ), 'Test 3.1: two candidates' );
bm_true( $candidates[0]['block_fingerprint'] !== $candidates[1]['block_fingerprint'], 'Test 3.2: each has distinct fingerprint' );

// ─── Test 4: dismiss filter excludes ─────────────────────────────────
echo "\nTest 4: dismiss filter\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_bm_post( 204, array(
	array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Skip me</h3>', 'innerContent' => array( '<h3>Skip me</h3>' ) ),
) );
$block_for_fp = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Skip me</h3>', 'innerContent' => array( '<h3>Skip me</h3>' ) );
$fp_dismissed = md5( serialize_block( $block_for_fp ) );
$GLOBALS['__test_post_meta'][ 204 ]['_snt_block_migrations_dismissed'] = array( array( 'heading-hierarchy-skip:' . $fp_dismissed ) );
$candidates = snt_block_migrations_detect_candidates();
bm_eq( 0, count( $candidates ), 'Test 4.1: dismissed fingerprint excluded' );

// ─── Test 5: nested core/heading inside core/group still detected ────
echo "\nTest 5: nested h3-skip inside group\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_bm_post( 205, array(
	array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(
		array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Nested</h3>', 'innerContent' => array( '<h3>Nested</h3>' ) ),
	), 'innerHTML' => '<div class="wp-block-group"></div>' ),
) );
$candidates = snt_block_migrations_detect_candidates();
bm_eq( 1, count( $candidates ), 'Test 5.1: nested h3-skip found via innerBlocks walk' );

// ─── Test 6: unrelated blocks ignored ────────────────────────────────
echo "\nTest 6: unrelated blocks ignored\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_bm_post( 206, array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Just text.</p>' ),
	array( 'blockName' => 'core/image', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<figure></figure>' ),
	array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 2 ), 'innerBlocks' => array(), 'innerHTML' => '<h2>Section</h2>', 'innerContent' => array( '<h2>Section</h2>' ) ),
) );
$candidates = snt_block_migrations_detect_candidates();
bm_eq( 0, count( $candidates ), 'Test 6.1: no h3 = no candidates' );

// ─── Test 7: transient caching round-trip ────────────────────────────
echo "\nTest 7: transient caching\n";
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_transients'] = array();
_bm_post( 207, array(
	array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Cached</h3>', 'innerContent' => array( '<h3>Cached</h3>' ) ),
) );
$scan = snt_block_migrations_run_scan();
bm_eq( 1, $scan['counts']['heading_hierarchy_skip'], 'Test 7.1: scan counts heading-skip correctly' );
$cached = snt_block_migrations_last_scan();
bm_true( is_array( $cached ), 'Test 7.2: last_scan reads from transient' );
bm_eq( $scan['counts']['heading_hierarchy_skip'], $cached['counts']['heading_hierarchy_skip'], 'Test 7.3: cached value matches' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
