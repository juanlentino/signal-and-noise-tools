<?php
/**
 * Standalone tests for sn_apply (MCP consolidation session 6b, v10.40.0):
 * signal-noise/sn-apply — PART 2 of 2 (acceptance tests 6, 7, 8: the
 * mode:"revision" byte-identical crown jewel driven through the FULL tool
 * path, batch semantics, and rollback). tests/abilities-sn-apply.php holds
 * gates 1-5 (dry_run, stale fingerprint, validation error, idempotency,
 * capability refusal) — split purely because each standalone fixture test
 * in this repo must independently print its own "N passed, M failed."
 * summary for the CI sweep (tests/*.php), so a shared bootstrap can't be
 * `require`d as a silent partial — see that file's docblock for the full
 * rationale (INTEGRATION suite, real absorbed impls, stubbed identity/audit
 * rails).
 *
 * The 8 acceptance tests from
 * ~/.claude/session-data/SN-MCP-new/sn-apply-spec.md are each pinned
 * explicitly below (search "ACCEPTANCE TEST").
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
 * WP + rails stubs (BEFORE the SUT loads)
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
function tf_add_filter( $hook, $cb ) { $GLOBALS['__filters'][ $hook ][] = $cb; }

$GLOBALS['__next_id']            = 1000;
$GLOBALS['__posts']              = array(); // id => ARRAY_A row
$GLOBALS['__post_meta']          = array();
$GLOBALS['__options']            = array();
$GLOBALS['__transients']         = array();
$GLOBALS['__write_calls']        = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'set_transient' => 0 );
$GLOBALS['__audit_calls']        = array();
$GLOBALS['__bound_uuid']         = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$GLOBALS['__auth_uuid']          = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'; // = bound => owner, by default
$GLOBALS['__revisions_to_keep']  = -1; // unlimited

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
if ( ! function_exists( 'post_type_exists' ) ) { function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'attachment' ), true ); } }
if ( ! function_exists( 'get_post_type_object' ) ) { function get_post_type_object( $t ) { $o = new stdClass(); $o->public = 'attachment' !== $t; return $o; } }
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$d = json_decode( (string) $content, true );
		if ( ! is_array( $d ) ) { return array(); }
		return array_key_exists( 'blockName', $d ) ? array( $d ) : $d;
	}
}
if ( ! function_exists( 'serialize_block' ) )  { function serialize_block( $b ) { return json_encode( $b ); } }
if ( ! function_exists( 'serialize_blocks' ) ) { function serialize_blocks( $t ) { return json_encode( $t ); } }
if ( ! function_exists( 'wp_kses_post' ) )     { function wp_kses_post( $h ) { return $h; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'esc_url' ) )          { function esc_url( $u ) { return $u; } }
if ( ! function_exists( 'sanitize_text_field' ) )     { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'home_url' ) )   { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t ) { $GLOBALS['__write_calls']['set_transient']++; $GLOBALS['__transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__write_calls']['update_option']++; $GLOBALS['__options'][ $k ] = $v; return true; } }
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
if ( ! function_exists( 'delete_post_meta' ) ) { function delete_post_meta( $id, $key ) { unset( $GLOBALS['__post_meta'][ (int) $id ][ $key ] ); return true; } }

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) {
		$GLOBALS['__write_calls']['wp_update_post']++;
		$id = (int) ( $args['ID'] ?? 0 );
		if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) { return $wp_error ? new WP_Error( 'invalid_post', 'no such post' ) : 0; }
		foreach ( $args as $k => $v ) { if ( 'ID' !== $k ) { $GLOBALS['__posts'][ $id ][ $k ] = $v; } }
		return $id;
	}
}
if ( ! function_exists( 'post_type_supports' ) ) { function post_type_supports( $t, $f ) { return true; } }
if ( ! function_exists( 'wp_revisions_to_keep' ) ) { function wp_revisions_to_keep( $post ) { return $GLOBALS['__revisions_to_keep']; } }
// v10.41.2: snt_sn_apply_stage_revision() now overrides post_modified/post_modified_gmt via current_time() before staging (backdated-revision fix) — every fixture that loads inc/sn-apply/revision.php needs this stub.
if ( ! function_exists( 'current_time' ) ) { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( '_wp_put_post_revision' ) ) {
	function _wp_put_post_revision( $post ) {
		$GLOBALS['__write_calls']['_wp_put_post_revision']++;
		$rid = $GLOBALS['__next_id']++;
		tf_post( $rid, array(
			'post_type' => 'revision', 'post_parent' => (int) ( $post['ID'] ?? 0 ),
			'post_content' => (string) ( $post['post_content'] ?? '' ),
			'post_title'   => (string) ( $post['post_title'] ?? '' ),
			'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ),
		) );
		return $rid;
	}
}
if ( ! function_exists( 'wp_restore_post_revision' ) ) {
	function wp_restore_post_revision( $revision_id ) {
		$rev = $GLOBALS['__posts'][ (int) $revision_id ] ?? null;
		if ( ! $rev || 'revision' !== ( $rev['post_type'] ?? '' ) ) { return false; }
		$parent_id = (int) $rev['post_parent'];
		if ( ! isset( $GLOBALS['__posts'][ $parent_id ] ) ) { return false; }
		foreach ( array( 'post_content', 'post_title', 'post_excerpt' ) as $f ) {
			$GLOBALS['__posts'][ $parent_id ][ $f ] = $rev[ $f ];
		}
		return $parent_id;
	}
}

if ( ! function_exists( 'sn_mcp_rw_bound_uuid' ) )                      { function sn_mcp_rw_bound_uuid() { return $GLOBALS['__bound_uuid']; } }
if ( ! function_exists( 'sn_mcp_rw_authenticated_app_password_uuid' ) ) { function sn_mcp_rw_authenticated_app_password_uuid() { return $GLOBALS['__auth_uuid']; } }
if ( ! function_exists( 'sn_mcp_rw_audit_record' ) ) {
	function sn_mcp_rw_audit_record( $slug, $args, $outcome, $error_source = null ) {
		$row = array( 'slug' => $slug, 'args' => $args, 'outcome' => $outcome, 'error' => is_wp_error( $error_source ) ? $error_source->get_error_code() : $error_source );
		$GLOBALS['__audit_calls'][] = $row;
		return $row;
	}
}

/* ════════════════════════════════════════════════════════════════════════
 * Load the SUT
 * ════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/block-fingerprint-engine.php';
require __DIR__ . '/../inc/block-migrations-apply.php';
require __DIR__ . '/../inc/pattern-adoption-apply.php';
require __DIR__ . '/../inc/ai-alt-text-suggest.php';
require __DIR__ . '/../inc/ai-drift-phrase-suggest.php';
require __DIR__ . '/../inc/ai-link-suggest.php';
require __DIR__ . '/../inc/abilities-permission-helpers.php';
require __DIR__ . '/../inc/abilities-update-post-surfaces.php';
require __DIR__ . '/../inc/abilities-content.php';
require __DIR__ . '/../inc/abilities-provenance.php';
require __DIR__ . '/../inc/sn-validate-checks.php';
require __DIR__ . '/../inc/sn-validate-checks-media.php';
require __DIR__ . '/../inc/sn-apply/revision.php';
require __DIR__ . '/../inc/sn-apply/gates.php';
require __DIR__ . '/../inc/sn-apply/validation.php';
require __DIR__ . '/../inc/sn-apply/delete-draft.php'; // v10.58.0 (audit item 6): gate 2 + write + preview for change.type delete_draft
require __DIR__ . '/../inc/sn-apply/link-reshape.php'; // v10.58.0 (audit item 5): pair validator + locator + identity-asserting splice for change.type link_reshape
require __DIR__ . '/../inc/sn-apply/executors.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';


/* ════════════════════════════════════════════════════════════════════════
 * Fixtures — a fresh, larger corpus for batch + revision-mode tests.
 * ════════════════════════════════════════════════════════════════════════ */

$block_500 = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Old</h3>', 'innerContent' => array( '<h3>Old</h3>' ) );
tf_post( 500, array( 'post_content' => json_encode( array( $block_500 ) ) ) );
$fp_500          = snt_block_fp_fingerprint( $block_500, 500, '0/0' );
$replacement_500 = json_encode( array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 2 ), 'innerBlocks' => array(), 'innerHTML' => '<h2>New</h2>', 'innerContent' => array( '<h2>New</h2>' ) ) );

function tf_reset_writes() {
	$GLOBALS['__write_calls'] = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'set_transient' => 0 );
}

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 6: mode:"revision" apply -> live post byte-identical
 * afterward, revision present and correct. Driven through the FULL
 * sn_apply tool path (snt_ability_sn_apply), not directly against
 * inc/sn-apply/revision.php — that primitive already has its own crown-
 * jewel test (tests/sn-apply-revision.php); this proves the FULL gate
 * pipeline routes into it correctly.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 6: mode:revision apply -> live post byte-identical, revision correct\n";
$live_content_before = $GLOBALS['__posts'][500]['post_content'];
tf_reset_writes();

$r6 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 500 ),
	'change' => array( 'type' => 'block_migration', 'fingerprint' => $fp_500, 'candidate_id' => 'cand-500', 'payload' => array( 'migration_type' => 'heading-hierarchy-skip', 'replacement_markup' => $replacement_500 ) ),
	'mode'   => 'revision', 'dry_run' => false,
) );

ok( ! is_wp_error( $r6 ), 'Test 6.1: apply does not refuse' );
eq( true, $r6['applied'] ?? null, 'Test 6.2: applied:true' );
eq( $live_content_before, $GLOBALS['__posts'][500]['post_content'], 'Test 6.3: THE CROWN JEWEL — the live post_content is BYTE-IDENTICAL to before the call' );
eq( 0, $GLOBALS['__write_calls']['wp_update_post'], 'Test 6.4: wp_update_post() was never called (block-fingerprint engine routed through the write_callback instead)' );
ok( ( $GLOBALS['__write_calls']['_wp_put_post_revision'] ?? 0 ) > 0, 'Test 6.5: _wp_put_post_revision() WAS called — a real revision was staged' );
$rev_id = $r6['revision_id'] ?? null;
ok( is_int( $rev_id ) && $rev_id > 0, 'Test 6.6: revision_id is a real int' );
ok( isset( $GLOBALS['__posts'][ $rev_id ] ), 'Test 6.7: the revision row actually exists in the store' );
eq( 'revision', $GLOBALS['__posts'][ $rev_id ]['post_type'] ?? null, 'Test 6.8: the staged row has post_type=revision' );
eq( 500, $GLOBALS['__posts'][ $rev_id ]['post_parent'] ?? null, 'Test 6.9: the staged revision post_parent = the target post' );
$expected_new_content = json_encode( array( json_decode( $replacement_500, true ) ) );
eq( $expected_new_content, $GLOBALS['__posts'][ $rev_id ]['post_content'] ?? null, 'Test 6.10: the staged revision post_content holds the REPLACED tree' );
ok( isset( $r6['rollback'] ) && 'restore_revision' === ( $r6['rollback']['method'] ?? '' ), 'Test 6.11: rollback.method = restore_revision' );
eq( $rev_id, $r6['rollback']['revision_id'] ?? null, 'Test 6.12: rollback.revision_id = the staged revision (mode:"revision" never touches the live post, so there is no separate PRE-apply revision to point at — see FINDINGS.md session 6b for this deviation from the spec\'s own illustrative example)' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 8 (run right after 6, while the revision from test 6 is
 * still fresh): rollback.revision_id, invoked via
 * inc/sn-apply/revision.php's restore function, ACTUALLY restores the
 * staged state into the live post.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 8: invoking rollback.revision_id actually changes the live post\n";
ok( $GLOBALS['__posts'][500]['post_content'] !== $expected_new_content, 'Test 8.1: sanity — live post still holds the ORIGINAL content before rollback is invoked' );
$restore = snt_sn_apply_restore_revision( $r6['rollback']['revision_id'] );
ok( ! is_wp_error( $restore ), 'Test 8.2: restore call succeeds' );
eq( 500, $restore['post_id'] ?? null, 'Test 8.3: restore reports the correct parent post_id' );
eq( $expected_new_content, $GLOBALS['__posts'][500]['post_content'], 'Test 8.4: the live post NOW holds the staged content — the rollback mechanism genuinely works, verified end-to-end' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 7: batch of 10 targets, 1 engineered failure -> 9
 * applied, 1 reported failed, no partial writes on the failed post.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 7: batch of 10, 1 engineered failure -> 9 applied, 1 failed, no partial writes\n";
for ( $i = 0; $i < 10; $i++ ) {
	tf_post( 600 + $i, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
}
// Engineer target #5 (attachment 605) to fail gate 2 (empty alt text ->
// char_range error) — every other target gets a valid alt text.
$batch_targets = array();
$batch_before  = array();
for ( $i = 0; $i < 10; $i++ ) {
	$id                 = 600 + $i;
	$batch_targets[]    = array( 'attachment_id' => $id );
	$batch_before[ $id ] = $GLOBALS['__post_meta'][ $id ]['_wp_attachment_image_alt'] ?? null;
}

// The engineered failure is the TARGET itself (attachment 605 doesn't
// exist) rather than a per-target payload variant — sn_apply's payload is
// per-CALL, not per-target — still a genuine, independent per-target
// failure exercising the "9 applied, 1 failed, no partial writes on the
// failed post" contract.
unset( $GLOBALS['__posts'][605] ); // 605 now fails target resolution (404).
tf_reset_writes();
$r7 = snt_ability_sn_apply( array(
	'target' => $batch_targets,
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'A red bicycle leaning against a brick wall' ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'batch-test-7-v2',
) );

ok( ! is_wp_error( $r7 ), 'Test 7.1: batch call itself does not refuse (per-target failures stay inside the batch response)' );
ok( ! empty( $r7['batch'] ), 'Test 7.2: response is batch-shaped' );
eq( 10, $r7['summary']['total'] ?? null, 'Test 7.3: summary.total = 10' );
eq( 9, $r7['summary']['applied'] ?? null, 'Test 7.4: summary.applied = 9' );
eq( 1, $r7['summary']['failed'] ?? null, 'Test 7.5: summary.failed = 1' );

$failed_results = array_values( array_filter( $r7['results'], function( $r ) { return ! empty( $r['error'] ); } ) );
eq( 1, count( $failed_results ), 'Test 7.6: exactly one result carries an error' );
eq( 605, $failed_results[0]['target']['attachment_id'] ?? null, 'Test 7.7: the failed result is target 605 (the engineered failure)' );
eq( 404, $failed_results[0]['error']['status'] ?? null, 'Test 7.8: the failure status is 404 (target not resolved)' );

ok( ! isset( $GLOBALS['__post_meta'][605] ) || ! isset( $GLOBALS['__post_meta'][605]['_wp_attachment_image_alt'] ), 'Test 7.9: NO partial write landed on the failed post (605 never got alt text written)' );

$applied_results = array_values( array_filter( $r7['results'], function( $r ) { return ! empty( $r['applied'] ); } ) );
eq( 9, count( $applied_results ), 'Test 7.10: 9 results report applied:true' );
foreach ( $applied_results as $r ) {
	$aid = $r['target']['attachment_id'];
	eq( 'A red bicycle leaning against a brick wall', $GLOBALS['__post_meta'][ $aid ]['_wp_attachment_image_alt'] ?? null, "Test 7.11.$aid: attachment $aid actually received the write" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
