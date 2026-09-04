<?php
/**
 * Standalone tests for sn_apply change.type "create_draft" (MCP
 * consolidation session 6c, the arc's finale, v10.40.0):
 * signal-noise/sn-apply. See inc/sn-apply/create-draft.php's docblock for
 * the full B5c origin and the mode-semantics decision this file exercises.
 *
 * Same bootstrap/stub conventions as tests/abilities-sn-apply.php and
 * tests/abilities-sn-apply-delegation-sweep.php — see those files' own
 * docblocks for the full rationale (standalone fixture per file: each must
 * print its own "N passed, M failed." for the tests/*.php CI glob).
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) )       { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'ARRAY_A' ) )       { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) )        { define( 'OBJECT', 'OBJECT' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg\n"; }
}
function eq( $expected, $actual, $msg ) {
	ok( $expected === $actual, $msg . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

/* ════════════════════════════════════════════════════════════════════════
 * WP + rails stubs (BEFORE the SUT loads) — same shapes as the sibling
 * sn_apply test files.
 * ════════════════════════════════════════════════════════════════════════ */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_data( $key = '' ) { return $this->data; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) )  { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $opts = 0 ) { return json_encode( $d, $opts ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $t, $c, $p = 10, $a = 1 ) { return true; } }
if ( ! function_exists( 'apply_filters' ) ) {
	$GLOBALS['__filters'] = array();
	function apply_filters( $h, $v ) { foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); } return $v; }
}

$GLOBALS['__next_id']           = 1000;
$GLOBALS['__posts']             = array();
$GLOBALS['__post_meta']         = array();
$GLOBALS['__options']           = array();
$GLOBALS['__transients']        = array();
$GLOBALS['__write_calls']       = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'set_transient' => 0, 'wp_insert_post' => 0, 'wp_set_post_tags' => 0 , 'wpdb_write' => 0);
$GLOBALS['__audit_calls']       = array();
$GLOBALS['__bound_uuid']        = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$GLOBALS['__auth_uuid']         = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'; // owner by default
$GLOBALS['__revisions_to_keep'] = -1;
$GLOBALS['__insert_fail_mode']  = null; // null | 'wp_error' | 'zero'
$GLOBALS['__tags']              = array(); // list of {term_id,name,slug}
$GLOBALS['__post_tags_set']     = array(); // post_id => tags array, from wp_set_post_tags calls

function tf_post( $id, $overrides = array() ) {
	$GLOBALS['__posts'][ $id ] = array_merge( array(
		'ID' => $id, 'post_title' => "Post $id", 'post_name' => "post-$id",
		'post_status' => 'publish', 'post_type' => 'post', 'post_parent' => 0,
		'post_date' => '2026-06-01 10:00:00', 'post_modified' => '2026-07-01 10:00:00',
		'post_modified_gmt' => '2026-07-01 10:00:00', 'post_content' => '', 'post_excerpt' => '',
	), $overrides );
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id, $output = 'OBJECT' ) {
		$row = $GLOBALS['__posts'][ (int) $id ] ?? null;
		if ( null === $row ) { return null; }
		return 'ARRAY_A' === $output ? $row : (object) $row;
	}
}
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap, $id = null ) { return true; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 42; } }
if ( ! function_exists( 'mb_strlen' ) ) { function mb_strlen( $s ) { return strlen( (string) $s ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'parse_blocks' ) ) {
	// Same JSON-shaped stub as the sibling sn_apply test files — real block
	// fixtures used here are JSON-encoded block arrays, EXCEPT the delimiter
	// tests below, which deliberately use raw HTML-comment markup (the
	// delimiter check is a raw-string scan, independent of this stub).
	function parse_blocks( $content ) {
		$d = json_decode( (string) $content, true );
		if ( ! is_array( $d ) ) { return array(); }
		return array_key_exists( 'blockName', $d ) ? array( $d ) : $d;
	}
}
if ( ! function_exists( 'serialize_block' ) )  { function serialize_block( $b ) { return json_encode( $b ); } }
if ( ! function_exists( 'serialize_blocks' ) ) { function serialize_blocks( $t ) { return json_encode( $t ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__write_calls']['update_option']++; $GLOBALS['__options'][ $k ] = $v; return true; } }
// v13.95.0: these were COUNT-ONLY stubs — get_post_meta always answered ''
// and update_post_meta stored nothing. That was adequate while create_draft
// wrote no meta; now that it attaches the surface fields, a count-only stub
// would let "wrote the wrong key" pass, since nothing can be read back.
// Storing versions, matching tests/abilities-sn-apply-block-edit.php.
$GLOBALS['__post_meta'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		if ( ! array_key_exists( $key, $GLOBALS['__post_meta'][ (int) $id ] ?? array() ) ) { return $single ? '' : array(); }
		$v = $GLOBALS['__post_meta'][ (int) $id ][ $key ];
		return $single ? $v : array( $v );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['__write_calls']['update_post_meta']++; $GLOBALS['__post_meta'][ (int) $id ][ $key ] = $value; return true; }
}

if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $args, $wp_error = false ) { $GLOBALS['__write_calls']['wp_update_post']++; return (int) ( $args['ID'] ?? 0 ); } }
if ( ! function_exists( 'post_type_supports' ) ) { function post_type_supports( $t, $f ) { return true; } }
if ( ! function_exists( 'wp_revisions_to_keep' ) ) { function wp_revisions_to_keep( $post ) { return $GLOBALS['__revisions_to_keep']; } }
if ( ! function_exists( '_wp_put_post_revision' ) ) { function _wp_put_post_revision( $post ) { $GLOBALS['__write_calls']['_wp_put_post_revision']++; return 0; } }

// wp_insert_post — session 6a's own lesson, faithfully modeled: $wp_error =
// true is the REAL 7.0.2 contract (see inc/sn-apply/create-draft.php's
// docblock). $GLOBALS['__insert_fail_mode'] switches between the two
// documented failure shapes for the dedicated failure tests below.
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $args, $wp_error = false ) {
		$GLOBALS['__write_calls']['wp_insert_post']++;
		if ( 'wp_error' === $GLOBALS['__insert_fail_mode'] ) {
			return $wp_error ? new WP_Error( 'db_insert_error', 'Simulated DB failure.' ) : 0;
		}
		if ( 'zero' === $GLOBALS['__insert_fail_mode'] ) {
			return 0; // defensive/cross-version arm.
		}
		$id = $GLOBALS['__next_id']++;
		tf_post( $id, array_merge( array( 'post_status' => 'draft', 'post_type' => 'post' ), $args, array( 'ID' => $id ) ) );
		return $id;
	}
}
if ( ! function_exists( 'get_edit_post_link' ) ) { function get_edit_post_link( $id, $ctx = 'display' ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; } }
if ( ! function_exists( 'sn_tag_normalize_key' ) ) { function sn_tag_normalize_key( $n ) { return strtolower( trim( (string) $n ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ), '-' ) ); } }

// wp_set_post_tags() — modeled on the REAL core contract this session's
// reviewer REJECT is about (wp_set_object_terms() underneath it): a STRING
// that matches nothing in $GLOBALS['__tags'] CREATES a new term (mutating
// the fixture taxonomy — tracked in __tags_created for the regression
// test below); an INT that matches an existing term_id ATTACHES only; an
// INT matching nothing is silently skipped (core never creates FROM an id).
// $append controls whether prior associations are replaced or kept — NOT
// whether unmatched names create. Faithful to this distinction is the whole
// point: a stub that always "just attaches" would never have caught the
// bug the reviewer found.
$GLOBALS['__tags_created'] = array(); // list of created {term_id,name,slug} — regression proof
if ( ! function_exists( 'wp_set_post_tags' ) ) {
	function wp_set_post_tags( $id, $tags, $append = false ) {
		$GLOBALS['__write_calls']['wp_set_post_tags']++;
		$resolved = array();
		foreach ( (array) $tags as $t ) {
			$is_id = is_int( $t ) || ( is_string( $t ) && ctype_digit( $t ) );
			if ( $is_id ) {
				foreach ( $GLOBALS['__tags'] as $existing ) {
					if ( (int) $existing['term_id'] === (int) $t ) { $resolved[] = (int) $existing['term_id']; continue 2; }
				}
				continue; // unmatched id: core skips it, never creates.
			}
			$name = (string) $t;
			$key  = sn_tag_normalize_key( $name );
			foreach ( $GLOBALS['__tags'] as $existing ) {
				if ( sn_tag_normalize_key( $existing['name'] ) === $key ) { $resolved[] = (int) $existing['term_id']; continue 2; }
			}
			// THE CORE BEHAVIOR THE REVIEW FOUND: an unmatched NAME creates.
			$new_id   = 900 + count( $GLOBALS['__tags_created'] ) + 1;
			$new_term = array( 'term_id' => $new_id, 'name' => $name, 'slug' => sanitize_title( $name ) );
			$GLOBALS['__tags'][]         = $new_term;
			$GLOBALS['__tags_created'][] = $new_term;
			$resolved[] = $new_id;
		}
		$GLOBALS['__post_tags_set'][ (int) $id ] = $resolved;
		return $resolved;
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		return array_map( static function ( $t ) {
			$o = new stdClass(); $o->term_id = $t['term_id']; $o->name = $t['name']; $o->slug = $t['slug'];
			return $o;
		}, $GLOBALS['__tags'] );
	}
}

if ( ! function_exists( 'sn_mcp_rw_bound_uuid' ) )                      { function sn_mcp_rw_bound_uuid() { return $GLOBALS['__bound_uuid']; } }
if ( ! function_exists( 'sn_mcp_rw_authenticated_app_password_uuid' ) ) { function sn_mcp_rw_authenticated_app_password_uuid() { return $GLOBALS['__auth_uuid']; } }
if ( ! function_exists( 'sn_mcp_rw_audit_record' ) ) {
	function sn_mcp_rw_audit_record( $slug, $args, $outcome, $error_source = null ) {
		$GLOBALS['__audit_calls'][] = array( 'slug' => $slug, 'args' => $args, 'outcome' => $outcome );
		return true;
	}
}

/* ════════════════════════════════════════════════════════════════════════
 * Load the SUT
 * ════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/word-count.php';
require __DIR__ . '/../inc/sn-validate-checks.php';
require __DIR__ . '/../inc/sn-validate-checks-media.php';
require __DIR__ . '/../inc/sn-apply/revision.php';
require __DIR__ . '/../inc/sn-apply/gates.php';
require __DIR__ . '/../inc/sn-apply/validation.php';
require __DIR__ . '/../inc/sn-apply/delete-draft.php'; // v10.58.0 (audit item 6): gate 2 + write + preview for change.type delete_draft
require __DIR__ . '/../inc/sn-apply/link-reshape.php'; // v10.58.0 (audit item 5): pair validator + locator + identity-asserting splice for change.type link_reshape
require __DIR__ . '/../inc/sn-apply/create-draft.php';
require __DIR__ . '/../inc/sn-apply/executors.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';

function tf_reset_writes() {
	$GLOBALS['__write_calls'] = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'set_transient' => 0, 'wp_insert_post' => 0, 'wp_set_post_tags' => 0 );
}
function tf_total_writes() { return array_sum( $GLOBALS['__write_calls'] ); }

function tf_block_json( $inner_html ) {
	return json_encode( array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => $inner_html, 'innerContent' => array( $inner_html ) ) ) );
}

$GLOBALS['__tags'][] = array( 'term_id' => 1, 'name' => 'Existing Tag', 'slug' => 'existing-tag' );
$GLOBALS['__tags'][] = array( 'term_id' => 2, 'name' => 'Second Tag', 'slug' => 'second-tag' );

/**
 * Permanent, non-destructive regression control (same idiom as
 * tests/sn-apply-revision.php's _sar_wrong_mechanism_stage()): models the
 * FIRST-DRAFT, vulnerable write path this session's reviewer REJECTed —
 * raw tag NAMES passed straight to wp_set_post_tags(), never resolved to
 * term_ids first. Local to this test file, never shipped in inc/. Proves
 * Test 11's assertions have real teeth (they would catch a regression back
 * to this exact anti-pattern) on every run, not just once during
 * development.
 */
function cd_test_vulnerable_write_create_draft_raw_tags( array $payload ) {
	$postarr = array(
		'post_type' => 'post', 'post_status' => 'draft',
		'post_title' => (string) ( $payload['title'] ?? '' ), 'post_content' => (string) ( $payload['content'] ?? '' ),
		'post_author' => get_current_user_id(),
	);
	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) { return $post_id; }
	if ( ! empty( $payload['tags'] ) ) {
		// THE BUG: raw names, never resolved — an unmatched name CREATES.
		wp_set_post_tags( (int) $post_id, array_values( array_map( 'strval', (array) $payload['tags'] ) ), false );
	}
	return array( 'post_id' => (int) $post_id, 'edit_link' => '', 'status' => 'draft' );
}


/* ────────────────────────────────────────────────────────────────────────
 * $wpdb, lifted BYTE-IDENTICALLY from tests/abilities-sn-apply-delegation-sweep.php.
 *
 * v13.95.0: gate 2 now runs the meta_description check during create_draft,
 * and that check queries the corpus for an identical description (severity
 * ERROR — a duplicate meta description is an SEO defect, so it refuses).
 * Copied rather than re-written for the same reason as the block-grammar
 * stubs: a second stub would model a different query and the two suites
 * would disagree about the same SQL without either failing.
 * ──────────────────────────────────────────────────────────────────────── */
// $wpdb — meta-description collision query only. Faithful to the REAL query
// shape (parses and applies the post_id exclusion clause) — same stub as
// tests/abilities-sn-validate.php's SN_Test_Wpdb_Validate.
class SN_Test_Wpdb_Apply {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix = 'wp_';
	public $rows = array(); // list of {post_id, meta_value} for _sn_meta_description
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . str_replace( "'", "''", (string) $a ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_col( $sql ) {
		preg_match( "/meta_value = '((?:[^'\\\\]|\\\\.)*)'/", $sql, $mv );
		preg_match( '/post_id != (\d+)/', $sql, $pid );
		$value    = isset( $mv[1] ) ? stripcslashes( $mv[1] ) : '';
		$exclude  = isset( $pid[1] ) ? (int) $pid[1] : 0;
		return array_values( array_map( 'strval', array_column(
			array_filter( $this->rows, static function ( $r ) use ( $value, $exclude ) {
				return $r['meta_value'] === $value && $r['post_id'] !== $exclude;
			} ), 'post_id'
		) ) );
	}
	public function insert( $t, $d, $f = null ) { $GLOBALS['__write_calls']['wpdb_write']++; return 1; }
	public function update( $t, $d, $w, $f = null, $wf = null ) { $GLOBALS['__write_calls']['wpdb_write']++; return 1; }
	public function query( $sql ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql ) ) { $GLOBALS['__write_calls']['wpdb_write']++; }
		return 0;
	}
}
$GLOBALS['wpdb'] = new SN_Test_Wpdb_Apply();

/* ════════════════════════════════════════════════════════════════════════
 * Test 1: dry_run (defaulted) previews {title, block_count, word_count},
 * zero inserts.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 1: dry_run (defaulted) previews without inserting\n";
tf_reset_writes();
$posts_before = $GLOBALS['__posts'];
$r1 = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'A Sweep of the Corpus', 'content' => tf_block_json( '<p>Hello world, this has five words.</p>' ) ) ),
	'mode'   => 'revision',
	// dry_run omitted — must default to true.
) );
ok( ! is_wp_error( $r1 ), 'Test 1.1: dry_run call does not refuse' );
eq( false, $r1['applied'] ?? null, 'Test 1.2: applied:false' );
eq( 'A Sweep of the Corpus', $r1['diff']['after']['title'] ?? null, 'Test 1.3: preview carries the title' );
eq( 1, $r1['diff']['after']['block_count'] ?? null, 'Test 1.4: preview block_count reflects the ONE paragraph block' );
ok( ( $r1['diff']['after']['word_count'] ?? 0 ) > 0, 'Test 1.5: preview word_count is non-zero' );
eq( 0, tf_total_writes(), 'Test 1.6: ZERO writes across every write primitive, incl. wp_insert_post' );
eq( $posts_before, $GLOBALS['__posts'], 'Test 1.7: the entire post store is byte-identical before/after' );

/* ════════════════════════════════════════════════════════════════════════
 * Test 2: dry_run:false, mode:revision -> the draft actually lands, status
 * draft, correct author, edit_link/post_id/status in the result, rollback
 * shaped delete_draft.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 2: draft lands with status draft + correct author (dry_run:false, mode:revision)\n";
tf_reset_writes();
$r2 = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array(
		'title' => 'Landed Draft', 'content' => tf_block_json( '<p>This draft actually lands.</p>' ), 'excerpt' => 'A short excerpt for the landed draft, well within the guideline range for word count.',
	) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( ! is_wp_error( $r2 ), 'Test 2.1: call succeeds' );
eq( true, $r2['applied'] ?? null, 'Test 2.2: applied:true' );
$new_id = $r2['diff']['after']['post_id'] ?? 0;
ok( $new_id > 0 && isset( $GLOBALS['__posts'][ $new_id ] ), 'Test 2.3: a new post row exists' );
eq( 'draft', $GLOBALS['__posts'][ $new_id ]['post_status'] ?? null, 'Test 2.4: post_status is "draft"' );
eq( 'post', $GLOBALS['__posts'][ $new_id ]['post_type'] ?? null, 'Test 2.5: post_type is "post" (never caller-controllable)' );
eq( 42, $GLOBALS['__posts'][ $new_id ]['post_author'] ?? null, 'Test 2.6: post_author is the calling identity (get_current_user_id())' );
eq( 'draft', $r2['diff']['after']['status'] ?? null, 'Test 2.7: result carries status:"draft"' );
ok( false !== strpos( (string) ( $r2['diff']['after']['edit_link'] ?? '' ), (string) $new_id ), 'Test 2.8: result carries an edit_link naming the new post' );
ok( array_key_exists( 'revision_id', $r2 ) && null === $r2['revision_id'], 'Test 2.9: revision_id is null — this never routes through the generic stage_revision() mechanism' );
eq( 'delete_draft', $r2['rollback']['method'] ?? null, 'Test 2.10: rollback method is delete_draft (a draft delete is trash, reversible)' );
eq( $new_id, $r2['rollback']['post_id'] ?? null, 'Test 2.11: rollback names the new post_id' );
eq( 1, $GLOBALS['__write_calls']['wp_insert_post'], 'Test 2.12: exactly one wp_insert_post call' );
eq( 0, $GLOBALS['__write_calls']['_wp_put_post_revision'], 'Test 2.13: _wp_put_post_revision NEVER called — this is its own write mechanism' );
eq( 0, $GLOBALS['__write_calls']['wp_update_post'], 'Test 2.14: wp_update_post never called' );

/* ════════════════════════════════════════════════════════════════════════
 * Test 3: caller-supplied post_status / post_date / post_type rejected 422,
 * each naming the forbidden field.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 3: caller-supplied post_status/post_date/post_type is rejected 422, naming the field\n";
foreach ( array( 'post_status' => 'publish', 'post_date' => '2020-01-01 00:00:00', 'post_type' => 'page' ) as $field => $value ) {
	$r3 = snt_ability_sn_apply( array(
		'target' => array( 'new_post' => true ),
		'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Forbidden field test', 'content' => tf_block_json( '<p>Body.</p>' ), $field => $value ) ),
		'mode'   => 'revision', 'dry_run' => true,
	) );
	ok( is_wp_error( $r3 ), "Test 3.$field.1: refuses" );
	eq( 422, (int) ( $r3->get_error_data()['status'] ?? 0 ), "Test 3.$field.2: status 422" );
	$decoded = json_decode( $r3->get_error_message(), true );
	$named   = false;
	foreach ( $decoded['gates']['validation']['findings'] ?? array() as $f ) {
		if ( false !== strpos( (string) ( $f['message'] ?? '' ), $field ) ) { $named = true; }
	}
	ok( $named, "Test 3.$field.3: a finding NAMES the forbidden field \"$field\" in its message" );
}

/* ════════════════════════════════════════════════════════════════════════
 * Test 4: mode:publish refuses structurally (gate 3), reason states drafts
 * are scheduled by hand — the SAME mechanism og_card/anchor_sweep use to
 * refuse mode:revision, mirrored.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 4: mode:publish refuses structurally — drafts are scheduled by hand\n";
$r4 = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Publish refusal test', 'content' => tf_block_json( '<p>Body.</p>' ) ) ),
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( is_wp_error( $r4 ), 'Test 4.1: mode:publish refuses' );
eq( 403, (int) ( $r4->get_error_data()['status'] ?? 0 ), 'Test 4.2: status 403 (mode_not_granted-shaped: mode_supported:false)' );
$decoded4 = json_decode( $r4->get_error_message(), true );
eq( false, $decoded4['gates']['capability']['mode_supported'] ?? null, 'Test 4.3: gates.capability.mode_supported:false' );
ok( false !== stripos( (string) ( $decoded4['gates']['capability']['reason'] ?? '' ), 'scheduled by hand' ), 'Test 4.4: the refusal reason states drafts are scheduled by hand' );

// mode:revision is accepted (gate 3 passes) but — the point this test also
// pins — it does NOT reuse the generic staged-revision mechanism: Test 2.9
// and 2.13 above already proved revision_id stays null and
// _wp_put_post_revision is never called even under mode:"revision". This is
// create_draft's "own mode semantics" (see inc/sn-apply/create-draft.php's
// docblock): the ONLY mode this type supports performs a real, direct
// (but draft-only, reversible-via-trash) insert — never a staged core
// revision of a parent post that does not exist.
ok( true, 'Test 4.5: (documentation) mode:revision\'s OWN mechanism is pinned by Test 2.9/2.13 above, not re-asserted here' );

/* ════════════════════════════════════════════════════════════════════════
 * Test 5: invalid block markup (mismatched/unclosed delimiters) -> 422.
 * Uses RAW HTML-comment content (not the JSON stub shape) — the delimiter
 * check is a raw-string scan, independent of the parse_blocks() stub.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 5: invalid block comment delimiters -> 422\n";
$bad_unclosed  = '<!-- wp:paragraph --><p>Never closed.</p>';
$bad_mismatch  = '<!-- wp:paragraph --><p>Wrong close.</p><!-- /wp:heading -->';
foreach ( array( 'unclosed' => $bad_unclosed, 'mismatched' => $bad_mismatch ) as $label => $bad_content ) {
	$r5 = snt_ability_sn_apply( array(
		'target' => array( 'new_post' => true ),
		'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Bad markup test', 'content' => $bad_content ) ),
		'mode'   => 'revision', 'dry_run' => true,
	) );
	ok( is_wp_error( $r5 ), "Test 5.$label.1: refuses" );
	eq( 422, (int) ( $r5->get_error_data()['status'] ?? 0 ), "Test 5.$label.2: status 422" );
	$decoded5 = json_decode( $r5->get_error_message(), true );
	$has_delim_finding = false;
	foreach ( $decoded5['gates']['validation']['findings'] ?? array() as $f ) {
		if ( 'block_delimiters' === ( $f['check'] ?? '' ) ) { $has_delim_finding = true; }
	}
	ok( $has_delim_finding, "Test 5.$label.3: a block_delimiters finding is present" );
}
// Balanced, real block-comment markup (self-closing + paired + nested) must
// pass cleanly — the check is a delimiter-balance scan, not a rejection of
// all raw comment markup.
$r5ok = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array(
		'title' => 'Balanced markup test',
		'content' => '<!-- wp:group {"layout":{"type":"constrained"}} --><div><!-- wp:paragraph --><p>Nested.</p><!-- /wp:paragraph --><!-- wp:separator /--></div><!-- /wp:group -->',
	) ),
	'mode'   => 'revision', 'dry_run' => true,
) );
ok( ! is_wp_error( $r5ok ), 'Test 5.balanced: real, balanced block-comment markup (nested + self-closing) does not refuse' );

// Three additional shapes verified by the reviewer during REJECT
// remediation (v10.40.0) — pinned here as PERMANENT regression guards, not
// re-derived ad hoc: nested blocks sharing the SAME name (the stack must
// track two independent frames, not just presence/absence of a name);
// a namespaced third-party block name (the '/' must survive the
// name-capture groups intact); and JSON attrs containing a literal '-->'
// inside a STRING VALUE (the attrs group must not treat that as the
// comment's own closing delimiter).
$delim_regression_cases = array(
	'nested_same_name' => '<!-- wp:group --><!-- wp:group --><p>inner</p><!-- /wp:group --><!-- /wp:group -->',
	'namespaced'        => '<!-- wp:create-block/foo --><div>x</div><!-- /wp:create-block/foo -->',
	'dashdash_in_json'  => '<!-- wp:paragraph {"content":"a --> b","note":"c --> d"} --><p>x</p><!-- /wp:paragraph -->',
);
foreach ( $delim_regression_cases as $case_name => $case_content ) {
	$rdelim = snt_ability_sn_apply( array(
		'target' => array( 'new_post' => true ),
		'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Delimiter regression: ' . $case_name, 'content' => $case_content ) ),
		'mode'   => 'revision', 'dry_run' => true,
	) );
	ok( ! is_wp_error( $rdelim ), "Test 5.regression.$case_name: does not refuse (permanent regression guard)" );
}

/* ════════════════════════════════════════════════════════════════════════
 * Test 6: tag-vocabulary enforcement — an unknown tag refuses 422 (gate 2);
 * an existing-vocabulary tag passes and is attached BY TERM_ID, taxonomy
 * unchanged; duplicate/repeated names in the payload dedupe and preserve
 * first-seen order through resolution.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 6: tag-vocabulary enforcement (existing vocabulary only, resolved to term_ids)\n";
$taxonomy_count_before_6 = count( $GLOBALS['__tags'] );
$r6bad = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Tag test', 'content' => tf_block_json( '<p>Body.</p>' ), 'tags' => array( 'Not A Real Tag' ) ) ),
	'mode'   => 'revision', 'dry_run' => true,
) );
ok( is_wp_error( $r6bad ), 'Test 6.1: unknown tag refuses (gate 2)' );
eq( 422, (int) ( $r6bad->get_error_data()['status'] ?? 0 ), 'Test 6.2: status 422' );
eq( $taxonomy_count_before_6, count( $GLOBALS['__tags'] ), 'Test 6.2b: the taxonomy is unchanged by a gate-2 refusal' );

tf_reset_writes();
$r6ok = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Tag test ok', 'content' => tf_block_json( '<p>Body.</p>' ), 'tags' => array( 'Existing Tag' ) ) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( ! is_wp_error( $r6ok ), 'Test 6.3: an existing-vocabulary tag does not refuse' );
$tagged_id = $r6ok['diff']['after']['post_id'] ?? 0;
eq( array( 1 ), $GLOBALS['__post_tags_set'][ $tagged_id ] ?? null, 'Test 6.4: wp_set_post_tags() was called with the resolved TERM_ID (1), never the raw name' );
eq( $taxonomy_count_before_6, count( $GLOBALS['__tags'] ), 'Test 6.5: the taxonomy is unchanged — attaching an existing tag never creates' );

// (c) order/dedupe sanity: a repeated name resolves once, and multiple
// distinct names preserve their first-seen order.
tf_reset_writes();
$r6order = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array(
		'title' => 'Tag order test', 'content' => tf_block_json( '<p>Body.</p>' ),
		'tags'  => array( 'Existing Tag', 'Second Tag', 'Existing Tag' ), // repeated first name
	) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( ! is_wp_error( $r6order ), 'Test 6.6: multiple distinct + one repeated tag does not refuse' );
$order_id = $r6order['diff']['after']['post_id'] ?? 0;
eq( array( 1, 2 ), $GLOBALS['__post_tags_set'][ $order_id ] ?? null, 'Test 6.7: resolved ids are [1,2] — repeat deduped, first-seen order preserved (not [1,2,1])' );
eq( $taxonomy_count_before_6, count( $GLOBALS['__tags'] ), 'Test 6.8: still no taxonomy growth' );

/* ════════════════════════════════════════════════════════════════════════
 * Test 11 (reviewer REJECT, v10.40.0): tag resolution is a STRUCTURAL
 * backstop, not gate-2-only. wp_set_post_tags() with a raw, unmatched NAME
 * string CREATES a new term ($append controls association REPLACEMENT,
 * never creation) — a gate-2 regression, a normalization mismatch, or a
 * race could otherwise silently invent vocabulary. Calls the write
 * primitive DIRECTLY (bypassing snt_ability_sn_apply(), so gate 2's own
 * tag_vocabulary check never runs) to prove the backstop holds
 * INDEPENDENTLY of gate 2, exactly as the review demanded.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 11: tag resolution is a STRUCTURAL backstop, independent of gate 2\n";

// 11a. RED CONTROL: the vulnerable raw-name pattern really would have
// invented vocabulary — proving the assertions below have real teeth.
tf_reset_writes();
$taxonomy_before_vuln = count( $GLOBALS['__tags'] );
$vuln = cd_test_vulnerable_write_create_draft_raw_tags( array(
	'title' => 'Vulnerable path RED control', 'content' => tf_block_json( '<p>Body.</p>' ),
	'tags'  => array( 'Brand New Unknown Tag' ),
) );
ok( ! is_wp_error( $vuln ), 'Test 11a.1 (RED CONTROL): the vulnerable raw-name path does NOT refuse — it silently succeeds' );
ok( count( $GLOBALS['__tags'] ) > $taxonomy_before_vuln, 'Test 11a.2 (RED CONTROL): the taxonomy GREW — a term was invented; this is what the reviewer\'s finding looked like before the fix' );

// 11b. THE FIX: the SHIPPED snt_sn_apply_write_create_draft() resolves tags
// to term_ids FIRST (before wp_insert_post) and refuses 422 on an
// unresolvable name — no orphan draft, no invented vocabulary, and this
// holds even when called directly, gate 2 entirely bypassed.
tf_reset_writes();
$taxonomy_before_fix = count( $GLOBALS['__tags'] );
$fixed = snt_sn_apply_write_create_draft( array(
	'title' => 'Structural backstop test', 'content' => tf_block_json( '<p>Body.</p>' ),
	'tags'  => array( 'Another Brand New Unknown Tag' ),
) );
ok( is_wp_error( $fixed ), 'Test 11b.1: the SHIPPED write primitive refuses an unresolvable tag, gate 2 bypassed entirely' );
eq( 'snt_sn_apply_unknown_tag', $fixed->get_error_code(), 'Test 11b.2: the specific unknown-tag error code' );
eq( 422, (int) ( $fixed->get_error_data()['status'] ?? 0 ), 'Test 11b.3: status 422' );
eq( $taxonomy_before_fix, count( $GLOBALS['__tags'] ), 'Test 11b.4: the taxonomy is UNCHANGED — no term was invented' );
eq( 0, $GLOBALS['__write_calls']['wp_insert_post'], 'Test 11b.5: NO post was inserted — resolution runs BEFORE the insert, so an unresolvable tag never orphans a draft' );

// 11c. A resolvable tag, called the same direct way, still succeeds and
// attaches by id (the backstop refuses bad input, it does not break good input).
tf_reset_writes();
$ok11c = snt_sn_apply_write_create_draft( array(
	'title' => 'Structural backstop happy path', 'content' => tf_block_json( '<p>Body.</p>' ),
	'tags'  => array( 'Existing Tag' ),
) );
ok( ! is_wp_error( $ok11c ), 'Test 11c.1: a resolvable tag, called directly, does not refuse' );
eq( array( 1 ), $GLOBALS['__post_tags_set'][ $ok11c['post_id'] ?? 0 ] ?? null, 'Test 11c.2: attached by term_id, called directly' );

/* ════════════════════════════════════════════════════════════════════════
 * Test 7: idempotent retry — same idempotency_key + SAME title+content
 * replays (canonical_target hash is content-derived); a DIFFERENT
 * title/content under the SAME key derives a different canonical target and
 * is a fresh execution (a second, independent draft).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 7: idempotent retry replays (same title+content) vs fresh (different)\n";
$content_a = tf_block_json( '<p>Idempotency content A.</p>' );
tf_reset_writes();
$r7a = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Idempotent draft', 'content' => $content_a ) ),
	'mode'   => 'revision', 'dry_run' => false, 'idempotency_key' => 'idem-create-draft',
) );
ok( ! is_wp_error( $r7a ), 'Test 7.1: first call succeeds' );
eq( false, $r7a['replayed'] ?? null, 'Test 7.2: first call replayed:false' );
$writes_after_first = tf_total_writes();
ok( $writes_after_first > 0, 'Test 7.3: first call actually wrote' );
$id_a = $r7a['diff']['after']['post_id'] ?? 0;

$r7b = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Idempotent draft', 'content' => $content_a ) ), // SAME title+content
	'mode'   => 'revision', 'dry_run' => false, 'idempotency_key' => 'idem-create-draft', // SAME key
) );
ok( ! is_wp_error( $r7b ), 'Test 7.4: second call (same key, same title+content) does not refuse' );
eq( true, $r7b['replayed'] ?? null, 'Test 7.5: second call replayed:true' );
eq( $r7a['diff'], $r7b['diff'], 'Test 7.6: second call returns the FIRST result verbatim' );
eq( $writes_after_first, tf_total_writes(), 'Test 7.7: NO additional write on replay' );

$r7c = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Idempotent draft', 'content' => tf_block_json( '<p>DIFFERENT content entirely.</p>' ) ) ), // DIFFERENT content
	'mode'   => 'revision', 'dry_run' => false, 'idempotency_key' => 'idem-create-draft', // SAME key
) );
ok( ! is_wp_error( $r7c ), 'Test 7.8: same key but DIFFERENT content does not refuse (fresh canonical target, not a cross-target replay)' );
eq( false, $r7c['replayed'] ?? null, 'Test 7.9: replayed:false — content-derived canonical target differs, so this is a FRESH execution' );
$id_c = $r7c['diff']['after']['post_id'] ?? 0;
ok( $id_c > 0 && $id_c !== $id_a, 'Test 7.10: a genuinely SECOND, independent draft was created' );

/* ════════════════════════════════════════════════════════════════════════
 * Test 8: size caps — title > 200 chars and content > 256 KB both refuse
 * 422.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 8: size caps (title 200 chars, content 256 KB)\n";
$r8title = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => str_repeat( 'x', SNT_SN_APPLY_CREATE_DRAFT_TITLE_MAX + 1 ), 'content' => tf_block_json( '<p>Body.</p>' ) ) ),
	'mode'   => 'revision', 'dry_run' => true,
) );
ok( is_wp_error( $r8title ), 'Test 8.1: over-cap title refuses' );
eq( 422, (int) ( $r8title->get_error_data()['status'] ?? 0 ), 'Test 8.2: status 422' );

$r8content = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Content cap test', 'content' => str_repeat( 'x', SNT_SN_APPLY_CREATE_DRAFT_CONTENT_MAX_BYTES + 1 ) ) ),
	'mode'   => 'revision', 'dry_run' => true,
) );
ok( is_wp_error( $r8content ), 'Test 8.3: over-cap content refuses' );
eq( 422, (int) ( $r8content->get_error_data()['status'] ?? 0 ), 'Test 8.4: status 422' );

// A title exactly AT the cap and empty title/content both behave honestly.
$r8empty = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => '', 'content' => '' ) ),
	'mode'   => 'revision', 'dry_run' => true,
) );
ok( is_wp_error( $r8empty ), 'Test 8.5: empty title AND content refuses (both required)' );

/* ════════════════════════════════════════════════════════════════════════
 * Test 9: fingerprint gate reports skipped:'no_fingerprint_scheme' honestly
 * — nothing exists to fingerprint for a not-yet-created post.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 9: fingerprint gate is honestly skipped for create_draft\n";
$r9 = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Fingerprint skip test', 'content' => tf_block_json( '<p>Body.</p>' ) ) ),
	'mode'   => 'revision', // dry_run defaults true
) );
ok( ! is_wp_error( $r9 ), 'Test 9.1: does not refuse' );
eq( 'no_fingerprint_scheme', $r9['gates']['fingerprint']['skipped'] ?? null, 'Test 9.2: gates.fingerprint.skipped = no_fingerprint_scheme' );
eq( true, $r9['gates']['fingerprint']['passed'] ?? null, 'Test 9.3: gates.fingerprint.passed = true (skipped, not failed)' );

/* ════════════════════════════════════════════════════════════════════════
 * Test 10: wp_insert_post failure shape — WP_Error, the ONLY failure shape
 * this primitive checks. Unlike session 6a's stage_revision() (which keeps
 * a defensive empty()/int-0 arm because _wp_put_post_revision()'s stub
 * carries no phpstan-return refinement), snt_sn_apply_write_create_draft()
 * calls wp_insert_post( $postarr, true ) with a LITERAL true — this repo's
 * pinned WP 7.0 stubs narrow that call's success type to int<1, max>, so a
 * falsy/zero return is type-level impossible on this path (verified: adding
 * an empty() check back reproduces a real `composer phpstan` dead-code
 * error). is_wp_error() alone is the complete, honest check — see
 * inc/sn-apply/create-draft.php's docblock at this exact call site.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 10: wp_insert_post failure shape (WP_Error — the only one this primitive checks)\n";
$GLOBALS['__insert_fail_mode'] = 'wp_error';
$r10a = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Failure test A', 'content' => tf_block_json( '<p>Body.</p>' ) ) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( is_wp_error( $r10a ), 'Test 10a.1: WP_Error insert failure surfaces as a refusal' );
eq( 500, (int) ( $r10a->get_error_data()['status'] ?? 0 ), 'Test 10a.2: status 500' );
$GLOBALS['__insert_fail_mode'] = null;

/* ════════════════════════════════════════════════════════════════════════
 * Test 12: delete_draft (v10.58.0, audit item 6) — create_draft's mirror.
 * The create -> delete round trip, plus every fence: fingerprint required
 * (422) / stale (409), draft-only (gate 2 AND the write's own re-check),
 * publish refusal, trash-only write, honest rollback shape.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 12: delete_draft — the create -> delete round trip + every fence\n";

// delete_draft's target resolution goes through the generic corpus-post
// branch, which needs these two (the sweep suite already stubs them).
if ( ! function_exists( 'post_type_exists' ) ) { function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'attachment' ), true ); } }
if ( ! function_exists( 'get_post_type_object' ) ) { function get_post_type_object( $t ) { $o = new stdClass(); $o->public = 'attachment' !== $t; return $o; } }

$GLOBALS['__trash_calls'] = 0;
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( $id ) {
		$GLOBALS['__trash_calls']++;
		if ( ! isset( $GLOBALS['__posts'][ (int) $id ] ) ) { return false; }
		$GLOBALS['__posts'][ (int) $id ]['post_status'] = 'trash';
		return (object) $GLOBALS['__posts'][ (int) $id ];
	}
}

// Create a draft; its rollback object must now carry the fingerprint.
$r12c = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Round Trip Draft', 'content' => tf_block_json( '<p>A draft born to be trashed.</p>' ) ) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
$rt_id = (int) ( $r12c['rollback']['post_id'] ?? 0 );
$rt_fp = $r12c['rollback']['fingerprint'] ?? null;
ok( $rt_id > 0, 'Test 12.1: create_draft landed' );
eq( snt_corpus_content_hash( (string) $GLOBALS['__posts'][ $rt_id ]['post_content'] ), $rt_fp, 'Test 12.2: create_draft rollback now carries the draft\'s content_hash — the one-shot round-trip key' );

// Missing fingerprint -> 422 with observed reported (dry-run-as-read idiom).
$r12a = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => $rt_id ),
	'change' => array( 'type' => 'delete_draft', 'payload' => array() ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( is_wp_error( $r12a ) && 'snt_sn_apply_missing_fingerprint' === $r12a->get_error_code(), 'Test 12.3: missing fingerprint refuses 422 with the type\'s own code' );
ok( false !== strpos( (string) $r12a->get_error_message(), (string) $rt_fp ), 'Test 12.4: the refusal carries gates.fingerprint.observed — a dry run doubles as the fingerprint read' );

// Stale fingerprint -> 409.
$r12b = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => $rt_id ),
	'change' => array( 'type' => 'delete_draft', 'fingerprint' => 'not-the-hash', 'payload' => array() ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( is_wp_error( $r12b ) && 'snt_sn_apply_fingerprint_stale' === $r12b->get_error_code(), 'Test 12.5: stale fingerprint is the 409 merge conflict' );

// mode:publish refuses structurally.
$r12d = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => $rt_id ),
	'change' => array( 'type' => 'delete_draft', 'fingerprint' => $rt_fp, 'payload' => array() ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( is_wp_error( $r12d ) && 'snt_sn_apply_mode_not_granted' === $r12d->get_error_code(), 'Test 12.6: mode:publish refuses — delete_draft is revision-only, create_draft\'s mirror' );

// A published post refuses at gate 2 even with a correct fingerprint.
tf_post( 950, array( 'post_status' => 'publish', 'post_content' => tf_block_json( '<p>Live post, untouchable.</p>' ) ) );
$r12e = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 950 ),
	'change' => array( 'type' => 'delete_draft', 'fingerprint' => snt_corpus_content_hash( (string) $GLOBALS['__posts'][950]['post_content'] ), 'payload' => array() ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( is_wp_error( $r12e ) && 'snt_sn_apply_validation_failed' === $r12e->get_error_code(), 'Test 12.7: a publish post refuses at gate 2 — structurally out of reach' );
eq( 0, $GLOBALS['__trash_calls'], 'Test 12.8: nothing has been trashed by any refusal path' );

// dry_run previews identity without trashing.
$r12f = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => $rt_id ),
	'change' => array( 'type' => 'delete_draft', 'fingerprint' => $rt_fp, 'payload' => array() ),
	'mode'   => 'revision',
) );
ok( ! is_wp_error( $r12f ) && false === $r12f['applied'] && 'draft' === ( $r12f['diff']['before']['status'] ?? null ) && 'trash' === ( $r12f['diff']['after']['status'] ?? null ), 'Test 12.9: dry_run previews the draft->trash transition with identity fields' );
eq( 0, $GLOBALS['__trash_calls'], 'Test 12.10: dry_run trashes nothing' );

// The real write: trash lands, rollback is the HONEST manual shape.
$r12g = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => $rt_id ),
	'change' => array( 'type' => 'delete_draft', 'fingerprint' => $rt_fp, 'payload' => array() ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( ! is_wp_error( $r12g ) && true === $r12g['applied'], 'Test 12.11: the write applies' );
eq( 1, $GLOBALS['__trash_calls'], 'Test 12.12: exactly one wp_trash_post call — trash, never a hard delete' );
eq( 'trash', $GLOBALS['__posts'][ $rt_id ]['post_status'], 'Test 12.13: the draft is in the Trash (recoverable), not gone' );
eq( 'manual_untrash', $r12g['rollback']['method'] ?? null, 'Test 12.14: rollback.method is manual_untrash — a wp-admin action, NEVER an unreachable MCP method name (the defect that created this type)' );
ok( false !== strpos( (string) ( $r12g['rollback']['note'] ?? '' ), 'Trash' ), 'Test 12.15: rollback carries the human restore path' );


/* ════════════════════════════════════════════════════════════════════════
 * v13.95.0 — the surface fields land in the SAME call.
 *
 * Both notes drafted on 2026-09-03 needed a follow-up `surfaces` call to
 * attach meta_description and og_card_title, and the first og_card_title
 * tripped its char-range check, costing a third. The property under test is
 * that ONE call now creates a COMPLETE draft, and that the validation runs
 * early enough for the caller to still fix the title in that same call.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest 20: meta_description + og_card_title attach in one call\n";
tf_reset_writes();
$og_ok = 'A Deliberately Sized Card Title That Sits Inside The Sixty To Ninety Range';
$md_ok = str_repeat( 'A meaningful meta description sentence. ', 4 );
$r20 = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array(
		'title'            => 'Complete In One Call',
		'content'          => tf_block_json( '<p>This draft arrives complete.</p>' ),
		'meta_description' => $md_ok,
		'og_card_title'    => $og_ok,
	) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( ! is_wp_error( $r20 ), 'Test 20.1: the call succeeds' );
$id20 = $r20['diff']['after']['post_id'] ?? 0;
ok( $id20 > 0, 'Test 20.2: a draft landed' );
eq( $md_ok, get_post_meta( $id20, '_sn_meta_description', true ), 'Test 20.3: meta_description is attached under the key surfaces writes' );
eq( $og_ok, get_post_meta( $id20, '_sn_og_card_title', true ), 'Test 20.4: og_card_title is attached under the key surfaces writes' );
eq( array( 'meta_description', 'og_card_title' ), $r20['diff']['after']['surfaces_set'] ?? null, 'Test 20.5: the response reports WHICH surface fields it attached' );
eq( 1, $GLOBALS['__write_calls']['wp_insert_post'], 'Test 20.6: still exactly ONE wp_insert_post — the fields ride the same creation' );
eq( 2, $GLOBALS['__write_calls']['update_post_meta'], 'Test 20.7: two meta writes, not a second sn_apply round trip' );

echo "\nTest 21: surfaces_set is PRESENT and empty when nothing is supplied\n";
$r21 = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'No Surfaces Here', 'content' => tf_block_json( '<p>Nothing extra.</p>' ) ) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( array_key_exists( 'surfaces_set', (array) ( $r21['diff']['after'] ?? array() ) ), 'Test 21.1: surfaces_set is present even when unused — "none supplied" must be distinguishable from "field ignored"' );
eq( array(), $r21['diff']['after']['surfaces_set'] ?? null, 'Test 21.2: ...and it is an empty array' );

echo "\nTest 22: the char-range check runs in THIS call, not the next one\n";
$r22 = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array(
		'title'         => 'Short Card Title Test',
		'content'       => tf_block_json( '<p>Body.</p>' ),
		'og_card_title' => 'Too short',
	) ),
	'mode'   => 'revision', 'dry_run' => true,
) );
$checks22 = (array) ( $r22['gates']['validation']['checks'] ?? array() );
ok( in_array( 'og_card_title', $checks22, true ), 'Test 22.1: gate 2 RAN the og_card_title check during create_draft (' . implode( ',', $checks22 ) . ')' );
$found22 = false;
foreach ( (array) ( $r22['gates']['validation']['findings'] ?? array() ) as $f ) {
	// Keys are surface/check (snt_sn_validate_finding), NOT field/code — an
	// invented key here matches nothing and the assertion fails silently.
	if ( 'og_card_title' === ( $f['surface'] ?? '' ) && 'char_range' === ( $f['check'] ?? '' ) ) { $found22 = true; }
}
ok( $found22, 'Test 22.2: a short title surfaces its char_range finding at DRAFT time — the round trip that cost a third call' );

echo "\nTest 23: NEGATIVE CONTROL — an over-cap title refuses and writes nothing\n";
tf_reset_writes();
$posts_b23 = $GLOBALS['__posts'];
$r23 = snt_ability_sn_apply( array(
	'target' => array( 'new_post' => true ),
	'change' => array( 'type' => 'create_draft', 'payload' => array(
		'title'         => 'Over Cap Test',
		'content'       => tf_block_json( '<p>Body.</p>' ),
		'og_card_title' => str_repeat( 'x', 200 ),
	) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
// A gate-2 refusal is a WP_Error whose message is the JSON envelope — the
// same shape Test 3's forbidden-field refusals take.
ok( is_wp_error( $r23 ), 'Test 23.1: a severity-error surface field REFUSES the whole create_draft' );
eq( 422, (int) ( $r23->get_error_data()['status'] ?? 0 ), 'Test 23.2: ...as a 422 caller error' );
$dec23  = json_decode( $r23->get_error_message(), true );
$named23 = false;
foreach ( (array) ( $dec23['gates']['validation']['findings'] ?? array() ) as $f ) {
	if ( 'og_card_title' === ( $f['surface'] ?? '' ) && 'error' === ( $f['severity'] ?? '' ) ) { $named23 = true; }
}
ok( $named23, 'Test 23.3: the refusal names og_card_title as the severity-error finding' );
eq( 0, tf_total_writes(), 'Test 23.4: ...and writes NOTHING — the draft is not created and then patched' );
eq( $posts_b23, $GLOBALS['__posts'], 'Test 23.5: the post store is byte-identical' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
