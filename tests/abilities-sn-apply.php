<?php
/**
 * Standalone tests for sn_apply (MCP consolidation session 6b, v10.40.0):
 * signal-noise/sn-apply. Absorbs block-migrations-apply, pattern-adoption-apply,
 * ai-alt-apply, ai-drift-apply, ai-link-apply, update-post-surfaces,
 * regenerate-og-card, anchor-sweep — every absorbed ability stays live
 * (verified separately by tests/mcp-capabilities.php).
 *
 * INTEGRATION suite: snt_ability_sn_apply() is called directly (not via the
 * MCP dispatch layer — sn_mcp_call_tool() is proven elsewhere,
 * tests/mcp-telemetry.php etc.), and every write path calls the REAL
 * absorbed impl (snt_block_migrations_apply_impl, snt_ai_drift_apply_impl,
 * snt_ai_alt_apply_impl, snt_ability_update_post_surfaces, ...) — never a
 * re-implementation. Identity resolution (sn_mcp_rw_bound_uuid /
 * sn_mcp_rw_authenticated_app_password_uuid) and the audit rails
 * (sn_mcp_rw_audit_record) are stubbed directly here rather than requiring
 * the real inc/mcp/mcp-rw-guard.php / mcp-rw-audit.php — this file tests
 * sn_apply's OWN gate logic against those functions' real signatures, not
 * the door's rate-limit/kill-switch machinery (covered by
 * tests/mcp-telemetry.php and the mcp-rw-* suites already).
 *
 * PART 1 of 2 — acceptance tests 1-5 (dry_run, stale fingerprint,
 * validation error, idempotency, capability refusal). See the sibling
 * tests/abilities-sn-apply-batch-and-rollback.php for acceptance tests
 * 6-8 (the mode:"revision" byte-identical crown jewel, batch semantics,
 * rollback) — split because each standalone fixture test in this repo
 * must independently print its own "N passed, M failed." summary for the
 * CI sweep (tests/*.php); a shared bootstrap file would be picked up and
 * executed as its own (empty) suite by that same glob.
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
require __DIR__ . '/../inc/sn-apply-revision.php';
require __DIR__ . '/../inc/sn-apply-gates.php';
require __DIR__ . '/../inc/sn-apply-validation.php';
require __DIR__ . '/../inc/sn-apply-delete-draft.php'; // v10.58.0 (audit item 6): gate 2 + write + preview for change.type delete_draft
require __DIR__ . '/../inc/sn-apply-link-reshape.php'; // v10.58.0 (audit item 5): pair validator + locator + identity-asserting splice for change.type link_reshape
require __DIR__ . '/../inc/sn-apply-executors.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';


/* ════════════════════════════════════════════════════════════════════════
 * Fixtures
 * ════════════════════════════════════════════════════════════════════════ */

// Post 100: block_migration target — one core/heading block, level 3.
$block_100 = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Old</h3>', 'innerContent' => array( '<h3>Old</h3>' ) );
tf_post( 100, array( 'post_content' => json_encode( array( $block_100 ) ) ) );
$fp_100 = snt_block_fp_fingerprint( $block_100, 100, '0/0' );
$replacement_100 = json_encode( array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 2 ), 'innerBlocks' => array(), 'innerHTML' => '<h2>New</h2>', 'innerContent' => array( '<h2>New</h2>' ) ) );

// Post 200: drift_replace target.
tf_post( 200, array( 'post_content' => 'This was updated last week and needs a refresh.' ) );
$phrase_200   = 'last week';
$pos_200      = strpos( $GLOBALS['__posts'][200]['post_content'], $phrase_200 );
$fp_200       = snt_ai_drift_fingerprint( $GLOBALS['__posts'][200]['post_content'], $phrase_200, $pos_200 );

// Post 300: surfaces target.
tf_post( 300, array( 'post_excerpt' => 'Old excerpt.' ) );

// Attachment 400: alt_text target.
tf_post( 400, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );

function tf_reset_writes() {
	$GLOBALS['__write_calls'] = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'set_transient' => 0 );
}
function tf_total_writes() { return array_sum( $GLOBALS['__write_calls'] ); }

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 1: dry_run:true produces a diff and writes nothing,
 * verified against the DB (the write-call recorder, not "response says
 * applied:false" — a structural guard).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 1: dry_run writes nothing (DB-verified)\n";
tf_reset_writes();
$posts_before = $GLOBALS['__posts'];
$r1 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 100 ),
	'change' => array( 'type' => 'block_migration', 'fingerprint' => $fp_100, 'payload' => array( 'migration_type' => 'heading-hierarchy-skip', 'replacement_markup' => $replacement_100 ) ),
	'mode'   => 'revision',
	// dry_run omitted — must default to true.
) );
ok( ! is_wp_error( $r1 ), 'Test 1.1: dry_run call does not refuse' );
eq( false, $r1['applied'] ?? null, 'Test 1.2: applied:false' );
ok( is_array( $r1['diff'] ) && ( $r1['diff']['after'] ?? '' ) !== '', 'Test 1.3: a diff was produced' );
eq( 0, tf_total_writes(), 'Test 1.4: ZERO writes across every write primitive (wp_update_post/update_post_meta/_wp_put_post_revision/update_option/set_transient)' );
eq( $posts_before, $GLOBALS['__posts'], 'Test 1.5: the entire post store is byte-identical before/after — structural, not just the write-call counters' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 1b (v10.41.1): the live bug this session fixed — `target`
 * arriving as a JSON-encoded STRING (an MCP client stringifying an untyped
 * parameter; see inc/abilities-sn-apply.php's schema fix + this function's
 * own transport-tolerance decode). Same call as Test 1, target pre-encoded.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 1b: target arrives as a JSON string (transport tolerance)\n";
tf_reset_writes();
$r1b = snt_ability_sn_apply( array(
	'target' => wp_json_encode( array( 'post_id' => 100 ) ),
	'change' => array( 'type' => 'block_migration', 'fingerprint' => $fp_100, 'payload' => array( 'migration_type' => 'heading-hierarchy-skip', 'replacement_markup' => $replacement_100 ) ),
	'mode'   => 'revision',
) );
ok( ! is_wp_error( $r1b ), 'Test 1b.1: a JSON-string target decodes and does not refuse' );
eq( false, $r1b['applied'] ?? null, 'Test 1b.2: applied:false (still a dry_run)' );
eq( array( 'post_id' => 100 ), $r1b['target'] ?? null, 'Test 1b.3: the decoded target reaches the response as a native array, not the original string' );
eq( 0, tf_total_writes(), 'Test 1b.4: ZERO writes' );

$r1c = snt_ability_sn_apply( array(
	'target' => '{not valid json',
	'change' => array( 'type' => 'block_migration', 'fingerprint' => $fp_100, 'payload' => array( 'migration_type' => 'heading-hierarchy-skip', 'replacement_markup' => $replacement_100 ) ),
	'mode'   => 'revision',
) );
ok( is_wp_error( $r1c ), 'Test 1c.1: an undecodable string target refuses (not a silent empty target)' );
eq( 'snt_sn_apply_bad_target_encoding', is_wp_error( $r1c ) ? $r1c->get_error_code() : null, 'Test 1c.2: refusal code names the encoding failure' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 2: stale fingerprint → refusal, with current fingerprint
 * returned so the caller can re-scan.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 2: stale fingerprint refusal carries the current fingerprint\n";
$r2 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 200 ),
	'change' => array( 'type' => 'drift_replace', 'fingerprint' => 'deadbeefdeadbeefdeadbeefdeadbeef', 'payload' => array( 'phrase' => $phrase_200, 'replacement' => 'on 2026-07-01', 'position' => $pos_200 ) ),
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( is_wp_error( $r2 ), 'Test 2.1: refuses' );
eq( 409, (int) ( $r2->get_error_data()['status'] ?? 0 ), 'Test 2.2: status 409' );
$decoded2 = json_decode( $r2->get_error_message(), true );
ok( is_array( $decoded2 ), 'Test 2.3: refusal content is JSON-decodable (actionable content, not a bare string)' );
eq( false, $decoded2['gates']['fingerprint']['passed'], 'Test 2.4: gates.fingerprint.passed = false' );
eq( $fp_200, $decoded2['gates']['fingerprint']['observed'], 'Test 2.5: gates.fingerprint.observed = the CURRENT fingerprint (re-derivable without a second lookup)' );
ok( isset( $decoded2['gates']['validation'] ) && isset( $decoded2['gates']['capability'] ) && isset( $decoded2['gates']['idempotency'] ), 'Test 2.6: every gate reports even though fingerprint failed first' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 3: a proposal carrying a validation error → refusal at
 * gate 2, even when the fingerprint is valid (or has no scheme, e.g. alt_text).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 3: validation error refuses at gate 2 even with a valid/no-scheme fingerprint\n";
$r3 = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 400 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => '' ) ), // empty -> char_range error
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( is_wp_error( $r3 ), 'Test 3.1: refuses' );
eq( 422, (int) ( $r3->get_error_data()['status'] ?? 0 ), 'Test 3.2: status 422' );
$decoded3 = json_decode( $r3->get_error_message(), true );
eq( true, $decoded3['gates']['fingerprint']['passed'], 'Test 3.3: gate 1 (fingerprint) PASSED — alt_text has no scheme, always trivially passes' );
eq( false, $decoded3['gates']['validation']['passed'], 'Test 3.4: gate 2 (validation) FAILED' );
ok( ! empty( $decoded3['gates']['validation']['findings'] ), 'Test 3.5: findings array is non-empty' );
$has_error_sev = false;
foreach ( $decoded3['gates']['validation']['findings'] as $f ) { if ( 'error' === ( $f['severity'] ?? '' ) ) { $has_error_sev = true; } }
ok( $has_error_sev, 'Test 3.6: at least one finding has severity=error' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 4: same idempotency_key twice → second call is a no-op
 * returning the FIRST result, replayed:true, no re-execution.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 4: same idempotency_key twice -> replay, no re-execution\n";
tf_reset_writes();
$r4a = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 400 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'A cat sitting on a windowsill in morning light' ) ),
	'mode' => 'publish', 'dry_run' => false, 'idempotency_key' => 'idem-test-4',
) );
ok( ! is_wp_error( $r4a ), 'Test 4.1: first call succeeds' );
eq( true, $r4a['applied'], 'Test 4.2: first call applied:true' );
eq( false, $r4a['replayed'], 'Test 4.3: first call replayed:false' );
$writes_after_first = tf_total_writes();
ok( $writes_after_first > 0, 'Test 4.4: first call actually wrote something' );

$r4b = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 400 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'A COMPLETELY DIFFERENT proposed value' ) ), // different payload — must be IGNORED
	'mode' => 'publish', 'dry_run' => false, 'idempotency_key' => 'idem-test-4',
) );
ok( ! is_wp_error( $r4b ), 'Test 4.5: second call (same key) does not refuse' );
eq( true, $r4b['replayed'], 'Test 4.6: second call replayed:true' );
eq( $r4a['diff'], $r4b['diff'], 'Test 4.7: second call returns the FIRST result verbatim (same diff, not re-derived from the different payload)' );
eq( $writes_after_first, tf_total_writes(), 'Test 4.8: NO additional write happened on replay — the executor never ran (zero delta across every write primitive)' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 4b (adversarial review HIGH, v10.40.0): the idempotency
 * key is TARGET-SCOPED, never global. A routine that reuses a key like
 * 'batch-item-1' across DIFFERENT targets must get a FRESH execution for
 * each target — never target A's stored response replayed for a call about
 * target B (which would report A's applied:true/revision_id while B was
 * never touched, with nothing signalling the mismatch).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 4b (review HIGH): same key on a DIFFERENT target is a fresh execution\n";
tf_post( 401, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
tf_post( 402, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );

$r4b1 = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 401 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'First target alt text describing image one' ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'batch-item-1',
) );
ok( ! is_wp_error( $r4b1 ) && ! empty( $r4b1['applied'] ), 'Test 4b.1: first call applied to attachment 401' );

$r4b2 = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 402 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'Second target alt text describing image two' ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'batch-item-1', // SAME key, DIFFERENT target
) );
ok( ! is_wp_error( $r4b2 ), 'Test 4b.2: second call does not refuse' );
eq( false, $r4b2['replayed'] ?? null, 'Test 4b.3: DIFFERENT target with the same key is a FRESH execution, NOT a replay' );
eq( 402, $r4b2['target']['attachment_id'] ?? null, 'Test 4b.4: the response is about THIS call\'s target (402), never the first call\'s (401)' );
eq( 'Second target alt text describing image two', $GLOBALS['__post_meta'][402]['_wp_attachment_image_alt'] ?? null, 'Test 4b.5: attachment 402 actually received ITS OWN write — the executor genuinely ran' );
eq( 'First target alt text describing image one', $GLOBALS['__post_meta'][401]['_wp_attachment_image_alt'] ?? null, 'Test 4b.6: attachment 401 keeps its own value — the two executions never crossed' );

// Both (key, target) store entries independently retrievable.
$g401 = snt_sn_apply_gate_idempotency( 'batch-item-1', 'attachment:401' );
$g402 = snt_sn_apply_gate_idempotency( 'batch-item-1', 'attachment:402' );
ok( is_array( $g401['replay'] ?? null ) && is_array( $g402['replay'] ?? null ), 'Test 4b.7: BOTH (key,target) store entries exist and are retrievable independently' );
eq( 401, $g401['replay']['target']['attachment_id'] ?? null, 'Test 4b.8: the (key, 401) entry stores 401\'s own response' );
eq( 402, $g402['replay']['target']['attachment_id'] ?? null, 'Test 4b.9: the (key, 402) entry stores 402\'s own response' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 4c (review HIGH, belt-and-braces): the stored row also
 * records the canonical target, and a replay whose stored target doesn't
 * match the requested one (a hand-corrupted row here — in production, a
 * future key-derivation change) is a 409 refusal naming BOTH targets,
 * never a silent cross-target replay.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 4c (review HIGH): stored-target mismatch on replay -> 409 naming both targets\n";
// Hand-corrupt: plant a row at the store key (key='corrupt-1', target
// 'attachment:401') whose recorded canonical target says 'post:812'.
$blob_4c = snt_sn_apply_idempotency_get_blob();
$blob_4c['rows'][ snt_sn_apply_idempotency_store_key( 'corrupt-1', 'attachment:401' ) ] = array(
	'ts'     => time(),
	'target' => 'post:812',
	'result' => array( 'applied' => true, 'target' => array( 'post_id' => 812 ) ),
);
$GLOBALS['__options'][ SN_APPLY_IDEMPOTENCY_OPTION ] = $blob_4c;

$r4c = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 401 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'Never applied, mismatch must refuse first' ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'corrupt-1',
) );
ok( is_wp_error( $r4c ), 'Test 4c.1: refuses (never silently replays a row recorded for a different target)' );
eq( 409, (int) ( $r4c->get_error_data()['status'] ?? 0 ), 'Test 4c.2: status 409' );
ok( false !== strpos( $r4c->get_error_message(), 'post:812' ) && false !== strpos( $r4c->get_error_message(), 'attachment:401' ), 'Test 4c.3: the refusal names BOTH targets (stored and requested)' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 4d (fix round, REJECT #10, pre-existing defect flagged
 * theoretical in the session 6b build, graduated to likely by
 * restore_revision's own documented dry-run-diff-then-apply workflow): a
 * dry_run:true call carrying an idempotency_key must NOT poison the store —
 * neither recording its preview response nor being replayable — so the
 * natural follow-up dry_run:false call under the SAME key genuinely
 * executes the write, rather than replaying the untouched preview
 * (applied:false) forever.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 4d (fix round, REJECT #10): dry_run:true does not poison the idempotency store for the same key\n";
tf_post( 403, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
tf_reset_writes();

$r4d1 = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 403 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'A preview alt text, never meant to apply' ) ),
	'mode'   => 'publish', 'dry_run' => true, 'idempotency_key' => 'dry-then-apply-1',
) );
ok( ! is_wp_error( $r4d1 ), 'Test 4d.1: dry_run preview does not refuse' );
eq( false, $r4d1['applied'] ?? null, 'Test 4d.2: dry_run preview applied:false' );
eq( 0, tf_total_writes(), 'Test 4d.3: dry_run preview performed ZERO writes' );

$r4d2 = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 403 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'The real applied alt text' ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'dry-then-apply-1', // SAME key as the dry run
) );
ok( ! is_wp_error( $r4d2 ), 'Test 4d.4: the follow-up dry_run:false call does not refuse' );
eq( false, $r4d2['replayed'] ?? null, 'Test 4d.5: the follow-up call is NOT a replay of the dry-run preview -- the dry run never recorded into the store' );
eq( true, $r4d2['applied'] ?? null, 'Test 4d.6: the follow-up call genuinely APPLIED -- the write actually executed, never stuck behind a replayed preview' );
ok( tf_total_writes() > 0, 'Test 4d.7: at least one real write happened' );
eq( 'The real applied alt text', $GLOBALS['__post_meta'][403]['_wp_attachment_image_alt'] ?? null, 'Test 4d.8: the real value landed in postmeta, not the dry-run preview text' );

// A genuine repeat of the SAME dry_run:false call (third call, same key) now
// replays the SECOND call's result (the real applied one) -- proving the
// store's write-half idempotency is unaffected; only the dry-run half changed.
$r4d3 = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 403 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'A third, ignored value' ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'dry-then-apply-1',
) );
ok( ! is_wp_error( $r4d3 ), 'Test 4d.9: third call does not refuse' );
eq( true, $r4d3['replayed'] ?? null, 'Test 4d.10: third call (a genuine dry_run:false repeat) DOES replay -- write-side idempotency is unaffected by this fix' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 5: a credential scoped to "revision" requesting
 * mode:"publish" → refusal at gate 3, logged via audit.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nAcceptance test 5: non-owner credential requesting mode:publish -> gate 3 refusal, logged\n";
$GLOBALS['__auth_uuid'] = 'ffffffff-ffff-ffff-ffff-ffffffffffff'; // mismatched -> not the owner
$audit_count_before = count( $GLOBALS['__audit_calls'] );
$r5 = snt_ability_sn_apply( array(
	'target' => array( 'attachment_id' => 400 ),
	'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'A dog running on a beach at sunset' ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
$GLOBALS['__auth_uuid'] = $GLOBALS['__bound_uuid']; // restore owner identity for later tests
ok( is_wp_error( $r5 ), 'Test 5.1: refuses' );
eq( 403, (int) ( $r5->get_error_data()['status'] ?? 0 ), 'Test 5.2: status 403' );
$decoded5 = json_decode( $r5->get_error_message(), true );
eq( true, $decoded5['gates']['fingerprint']['passed'], 'Test 5.3: gate 1 passed (no scheme for alt_text)' );
eq( true, $decoded5['gates']['validation']['passed'], 'Test 5.4: gate 2 passed (valid alt text)' );
eq( false, $decoded5['gates']['capability']['passed'], 'Test 5.5: gate 3 FAILED' );
eq( array( 'revision' ), $decoded5['gates']['capability']['granted_modes'], 'Test 5.6: granted_modes = ["revision"] for the non-owner identity' );
ok( count( $GLOBALS['__audit_calls'] ) > $audit_count_before, 'Test 5.7: the refusal was logged via sn_mcp_rw_audit_record()' );
$last_audit = end( $GLOBALS['__audit_calls'] );
eq( 'error', $last_audit['outcome'], 'Test 5.8: logged with outcome=error' );
eq( false, $last_audit['args']['gate_capability_passed'], 'Test 5.9: the audit row carries gate_capability_passed:false' );

echo "\nGroup: dismiss — the wave-1 gap the allowlist itself flags (v13.47.0)\n";
//
// WHY. dismiss-candidate has been reachable from NEITHER door since wave 1, and
// sn_mcp_allowlist()'s own comment carries the WATCH: "dismiss-candidate backed
// sn-scan's `dismissed` flow". It also GATES phase 10. Practically: every scan
// surfaces candidates a caller can APPLY but cannot DISMISS, so a candidate list
// can never converge from a session.
//
// THE CONTRACT IS THE ABILITY'S, NOT THE TOOL'S. dismiss-candidate does NOT take
// sn-scan's candidate_id. It requires surface + post_id + block_fingerprint +
// candidate_type (inc/abilities-dismiss.php), so the payload mirrors that.

ok( in_array( 'dismiss', SNT_SN_APPLY_CHANGE_TYPES, true ), 'dismiss is a declared change type' );

// PUBLISH-ONLY, the og_card / anchor_sweep / roadmap_board posture: a dismissal
// writes a store, not a post field, so there is no WordPress revision to stage.
// Refuse BY NAME rather than fabricate a staged version of a side effect.
$sn_dis_rev = snt_ability_sn_apply( array(
	'target'  => array( 'post_id' => 100 ),
	'change'  => array( 'type' => 'dismiss', 'payload' => array( 'surface' => 'block-migrations', 'block_fingerprint' => 'abc', 'candidate_type' => 'x' ) ),
	'mode'    => 'revision',
	'dry_run' => true,
) );
ok( is_wp_error( $sn_dis_rev ), 'mode:revision refuses' );
ok( in_array( $sn_dis_rev->get_error_code(), array( 'snt_sn_apply_mode_not_granted', 'snt_sn_apply_bad_mode' ), true ),
	'and refuses BY NAME, never by fabricating a staged dismissal' );

// SIX OF NINE SCAN TYPES HAVE NO DISMISSAL STORE. dismiss-candidate's surface
// enum is exactly three; sn-scan has nine scan_types. A surface with no store
// must refuse by name — a silent no-op would report success for a dismissal
// that was never recorded, and the caller would never re-see the candidate as
// undismissed either.
$sn_dis_bad = snt_ability_sn_apply( array(
	'target'  => array( 'post_id' => 100 ),
	'change'  => array( 'type' => 'dismiss', 'payload' => array( 'surface' => 'near-duplicate', 'block_fingerprint' => 'abc', 'candidate_type' => 'x' ) ),
	'mode'    => 'publish',
	'dry_run' => true,
) );
ok( is_wp_error( $sn_dis_bad ), 'a surface with no dismissal store refuses' );
// Guarded: under a mutation that stops the refusal, an unguarded
// get_error_message() on a non-error FATALS and takes every later assertion in
// this file with it — a suite that dies prints no summary line, which the CI
// gate reads as "did not assert", not as a pass. Today already produced one
// negative control invalidated exactly that way.
ok( is_wp_error( $sn_dis_bad ) && false !== strpos( (string) $sn_dis_bad->get_error_message(), 'near-duplicate' ),
	'and names the offending surface rather than failing generically' );

// GATE 1 SKIPS, and the REASON matters. The first design said "the candidate_id
// IS the binding"; that was wrong — the ability takes no candidate_id. The
// second said block_fingerprint should be a REAL check; that was also wrong —
// snt_ability_dismiss_candidate() passes it straight through to the per-surface
// impl as an OPAQUE IDENTITY KEY and never validates it against live content.
// No absorbed impl has a fingerprint scheme, so the honest report is the same
// skip alt_text/surfaces/og_card/anchor_sweep already use — never a fabricated
// pass, which would tell a caller staleness was checked when it was not.
$sn_dis_dry = snt_ability_sn_apply( array(
	'target'  => array( 'post_id' => 100 ),
	'change'  => array( 'type' => 'dismiss', 'payload' => array( 'surface' => 'block-migrations', 'block_fingerprint' => 'abc', 'candidate_type' => 'heading_level' ) ),
	'mode'    => 'publish',
	'dry_run' => true,
) );
ok( ! is_wp_error( $sn_dis_dry ), 'a well-formed dismissal dry-runs' );
ok( 'no_fingerprint_scheme' === ( $sn_dis_dry['gates']['fingerprint']['skipped'] ?? null ),
	'gate 1 reports no_fingerprint_scheme — the store keeps the fingerprint as an identity key, not a staleness token' );

// The payload must be complete: a missing key is a caller error, not a write
// with an empty string quietly standing in for it.
foreach ( array( 'surface', 'block_fingerprint', 'candidate_type' ) as $sn_k ) {
	$sn_pl = array( 'surface' => 'block-migrations', 'block_fingerprint' => 'abc', 'candidate_type' => 'x' );
	unset( $sn_pl[ $sn_k ] );
	$sn_r = snt_ability_sn_apply( array(
		'target'  => array( 'post_id' => 100 ),
		'change'  => array( 'type' => 'dismiss', 'payload' => $sn_pl ),
		'mode'    => 'publish',
		'dry_run' => true,
	) );
	ok( is_wp_error( $sn_r ), "a dismissal missing $sn_k refuses" );
}

echo "\nGroup: two exclusions the options framing reverses (Phase 3)\n";
//
// WHY THESE REVERSE. inc/mcp/mcp-capabilities.php holds both OFF the rw door
// "on purpose" — merge-tags as "sitewide term reassign + delete",
// clear-template-overrides as "wipes Site Editor template rows". Both reasons
// were written against a DOORED TOOL. As an sn-apply change type each inherits
// dry_run:true by default, four gates reported even when an earlier one failed,
// idempotency, and the rw audit trail. Different risk object, same capability.
//
// Both abilities are snt_ability_perm_manage_options — the same gate sn-apply
// carries — so nothing crosses a permission tier.

ok( in_array( 'merge_tags', SNT_SN_APPLY_CHANGE_TYPES, true ), 'merge_tags is a declared change type' );
ok( in_array( 'clear_template_overrides', SNT_SN_APPLY_CHANGE_TYPES, true ), 'clear_template_overrides is a declared change type' );

// Both are PUBLISH-ONLY: a term reassign and an option/row wipe have no
// WordPress revision to stage. Refuse by name, never fabricate a staged
// version of a side effect that cannot be staged.
foreach ( array(
	'merge_tags'               => array( 'scope' => 'tags' ),
	'clear_template_overrides' => array( 'scope' => 'template_overrides' ),
) as $sn_t => $sn_target ) {
	$sn_r = snt_ability_sn_apply( array(
		'target'  => $sn_target,
		'change'  => array( 'type' => $sn_t, 'payload' => array( 'from_slugs' => array( 'a' ), 'into_slug' => 'b' ) ),
		'mode'    => 'revision',
		'dry_run' => true,
	) );
	ok( is_wp_error( $sn_r ), "$sn_t refuses mode:revision" );
}

// merge_tags mirrors the ability's OWN required input (from_slugs, into_slug),
// read from inc/abilities-content.php rather than assumed from the tool's
// vocabulary — the mistake that produced two wrong dismiss designs.
foreach ( array( 'from_slugs', 'into_slug' ) as $sn_k ) {
	$sn_pl = array( 'from_slugs' => array( 'old-tag' ), 'into_slug' => 'new-tag' );
	unset( $sn_pl[ $sn_k ] );
	$sn_r = snt_ability_sn_apply( array(
		'target'  => array( 'scope' => 'tags' ),
		'change'  => array( 'type' => 'merge_tags', 'payload' => $sn_pl ),
		'mode'    => 'publish',
		'dry_run' => true,
	) );
	ok( is_wp_error( $sn_r ), "merge_tags missing $sn_k refuses" );
}

// clear_template_overrides takes NO payload — its ability's input_schema has an
// empty properties map. Requiring one would invent a contract.
$sn_cto = snt_ability_sn_apply( array(
	'target'  => array( 'scope' => 'template_overrides' ),
	'change'  => array( 'type' => 'clear_template_overrides', 'payload' => array() ),
	'mode'    => 'publish',
	'dry_run' => true,
) );
ok( ! is_wp_error( $sn_cto ), 'clear_template_overrides dry-runs with an empty payload' );

// Explicit scope targets, the anchor_sweep/roadmap_board posture: name the
// surface exactly rather than accepting any object at all.
$sn_bad_scope = snt_ability_sn_apply( array(
	'target'  => array( 'scope' => 'not_a_surface' ),
	'change'  => array( 'type' => 'clear_template_overrides', 'payload' => array() ),
	'mode'    => 'publish',
	'dry_run' => true,
) );
ok( is_wp_error( $sn_bad_scope ), 'a wrong target.scope refuses rather than being accepted loosely' );

// merge_tags needs its OWN scope pin — the assertion above only exercises
// clear_template_overrides, and a mutation proved merge_tags' guard was
// untested. Two types, two guards, two pins.
$sn_mt_scope = snt_ability_sn_apply( array(
	'target'  => array( 'scope' => 'not_tags' ),
	'change'  => array( 'type' => 'merge_tags', 'payload' => array( 'from_slugs' => array( 'a' ), 'into_slug' => 'b' ) ),
	'mode'    => 'publish',
	'dry_run' => true,
) );
ok( is_wp_error( $sn_mt_scope ), 'merge_tags refuses a target.scope that is not "tags"' );

// THE SELF-MERGE GUARD WAS WRITTEN WITH NO TEST, and a mutation caught that:
// deleting it changed nothing. Merging a term into itself would reassign its
// posts to itself and then DELETE it — a silent vocabulary loss, and exactly
// the "sitewide term reassign + delete" risk the original exclusion named.
$sn_self = snt_ability_sn_apply( array(
	'target'  => array( 'scope' => 'tags' ),
	'change'  => array( 'type' => 'merge_tags', 'payload' => array( 'from_slugs' => array( 'keep-me', 'other' ), 'into_slug' => 'keep-me' ) ),
	'mode'    => 'publish',
	'dry_run' => true,
) );
ok( is_wp_error( $sn_self ), 'merging a term into itself refuses' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
