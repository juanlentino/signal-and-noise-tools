<?php
/**
 * Standalone tests for sn_apply change.type "restore_revision" (MCP
 * consolidation session 7, the acceptance path):
 * signal-noise/sn-apply. See inc/sn-apply-restore-revision.php's docblock
 * for the full design: publish-only via the existing mode-support
 * mechanism, a structural pre-check BEFORE the four gates, a REAL
 * fingerprint scheme bound to the live post's content_hash, gate 2 run
 * against the REVISION's own fields, a self-guaranteed rollback snapshot,
 * and the staged-meta queue's first application path.
 *
 * Same bootstrap/stub conventions as the sibling sn_apply test files (see
 * tests/abilities-sn-apply.php's docblock for the full rationale: standalone
 * fixture per file, each prints its own "N passed, M failed." for the
 * tests/*.php CI glob).
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

$GLOBALS['__next_id']            = 1000;
$GLOBALS['__posts']              = array(); // id => ARRAY_A row
$GLOBALS['__post_meta']          = array();
$GLOBALS['__options']            = array();
$GLOBALS['__transients']         = array();
$GLOBALS['__write_calls']        = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'delete_option' => 0, 'set_transient' => 0 );
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
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'post_type_exists' ) ) { function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'attachment' ), true ); } }
if ( ! function_exists( 'get_post_type_object' ) ) { function get_post_type_object( $t ) { $o = new stdClass(); $o->public = 'attachment' !== $t; return $o; } }
if ( ! function_exists( 'parse_blocks' ) ) { function parse_blocks( $content ) { return array(); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__write_calls']['update_option']++; $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { $GLOBALS['__write_calls']['delete_option']++; unset( $GLOBALS['__options'][ $k ] ); return true; } }
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
// Real 6.9 contract (verified against the real source, wp-includes/
// revision.php): wp_get_post_revision() returns null for BOTH "no such
// post" and "found, but not a revision" — collapsing the two. This stub
// models that faithfully rather than distinguishing them, so the SUT's own
// snt_sn_apply_restore_revision_precheck() is what proves it still turns
// both into the same honest 404.
if ( ! function_exists( 'wp_get_post_revision' ) ) {
	function wp_get_post_revision( $id ) {
		$row = $GLOBALS['__posts'][ (int) $id ] ?? null;
		if ( ! $row || 'revision' !== ( $row['post_type'] ?? '' ) ) { return null; }
		return (object) $row;
	}
}
if ( ! function_exists( 'wp_get_post_revisions' ) ) {
	function wp_get_post_revisions( $post_id, $args = null ) {
		$post_id = (int) $post_id;
		$out     = array();
		foreach ( $GLOBALS['__posts'] as $row ) {
			if ( 'revision' === ( $row['post_type'] ?? '' ) && (int) ( $row['post_parent'] ?? 0 ) === $post_id ) {
				$out[] = (object) $row;
			}
		}
		usort( $out, function ( $a, $b ) { return $b->ID <=> $a->ID; } ); // real core: 'date ID' DESC.
		return $out;
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
require __DIR__ . '/../inc/health-check-drift-time-phrases.php';
require __DIR__ . '/../inc/sn-validate-checks.php';
require __DIR__ . '/../inc/sn-validate-checks-media.php';
require __DIR__ . '/../inc/sn-apply-revision.php';
require __DIR__ . '/../inc/sn-apply-gates.php';
require __DIR__ . '/../inc/sn-apply-validation.php';
require __DIR__ . '/../inc/sn-apply-restore-revision.php';
require __DIR__ . '/../inc/sn-apply-executors.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';

function tf_reset_writes() {
	$GLOBALS['__write_calls'] = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'delete_option' => 0, 'set_transient' => 0 );
}
function tf_total_writes() { return array_sum( $GLOBALS['__write_calls'] ); }

/* ════════════════════════════════════════════════════════════════════════
 * Fixtures
 * ════════════════════════════════════════════════════════════════════════ */

// Post 900 + revision 901 (differs from live) — the main restore target.
tf_post( 900, array( 'post_content' => 'Live current content.', 'post_excerpt' => 'Live excerpt.' ) );
tf_post( 901, array( 'post_type' => 'revision', 'post_parent' => 900, 'post_content' => 'Staged proposal content.', 'post_title' => 'Post 900', 'post_excerpt' => 'Live excerpt.' ) );
$fp900 = snt_corpus_content_hash( $GLOBALS['__posts'][900]['post_content'] );

// Post 910 — a DIFFERENT post, used to construct a foreign-parent revision.
tf_post( 910, array( 'post_content' => 'Post 910 own content.' ) );
tf_post( 902, array( 'post_type' => 'revision', 'post_parent' => 910, 'post_content' => 'Belongs to 910, not 900.', 'post_title' => 'Post 910', 'post_excerpt' => '' ) );

/* ════════════════════════════════════════════════════════════════════════
 * Test A: mode:"revision" refuses structurally (honest reason) — the exact
 * mechanism og_card/anchor_sweep use.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest A: mode:revision refuses structurally, honest reason\n";
$rA = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp900, 'payload' => array( 'revision_id' => 901 ) ),
	'mode'   => 'revision', 'dry_run' => true,
) );
ok( is_wp_error( $rA ), 'A.1: refuses' );
eq( 403, (int) ( $rA->get_error_data()['status'] ?? 0 ), 'A.2: status 403' );
$decodedA = json_decode( $rA->get_error_message(), true );
eq( false, $decodedA['gates']['capability']['passed'] ?? null, 'A.3: gate 3 (capability) failed' );
ok( false !== strpos( (string) ( $decodedA['gates']['capability']['reason'] ?? '' ), 'revision of a revision' ), 'A.4: the reason is honest — names the "revision of a revision" mechanism, not a generic denial' );

/* ════════════════════════════════════════════════════════════════════════
 * Test B: a routine (non-owner) credential requesting mode:publish refuses
 * at gate 3 by IDENTITY, not by the type's own structural support (publish
 * IS supported for this type — only the calling identity lacks it).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest B: routine credential (granted revision-only) requesting mode:publish -> gate 3 identity refusal\n";
$GLOBALS['__auth_uuid'] = 'ffffffff-ffff-ffff-ffff-ffffffffffff'; // mismatched -> not the owner
$rB = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp900, 'payload' => array( 'revision_id' => 901 ) ),
	'mode'   => 'publish', 'dry_run' => true,
) );
$GLOBALS['__auth_uuid'] = $GLOBALS['__bound_uuid']; // restore owner identity for later tests
ok( is_wp_error( $rB ), 'B.1: refuses' );
eq( 403, (int) ( $rB->get_error_data()['status'] ?? 0 ), 'B.2: status 403' );
$decodedB = json_decode( $rB->get_error_message(), true );
eq( true, $decodedB['gates']['capability']['mode_supported'] ?? null, 'B.3: mode_supported:true — publish IS a supported mode for this type' );
eq( array( 'revision' ), $decodedB['gates']['capability']['granted_modes'] ?? null, 'B.4: granted_modes = ["revision"] for the non-owner identity — the IDENTITY is what refused, not the type' );

/* ════════════════════════════════════════════════════════════════════════
 * Test C: missing revision -> 404, caught by the structural pre-check
 * BEFORE any gate runs (never a 409/422 from gates 1-3).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest C: missing revision -> 404 (structural pre-check, before any gate)\n";
$rC = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp900, 'payload' => array( 'revision_id' => 999999 ) ),
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( is_wp_error( $rC ), 'C.1: refuses' );
eq( 'snt_sn_apply_revision_not_found', $rC->get_error_code(), 'C.2: error code' );
eq( 404, (int) ( $rC->get_error_data()['status'] ?? 0 ), 'C.3: status 404' );

/* ════════════════════════════════════════════════════════════════════════
 * Test D: foreign-parent revision -> 409, naming BOTH ids (the cross-target
 * lesson, generalized).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest D: foreign-parent revision -> 409 naming both ids\n";
$rD = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp900, 'payload' => array( 'revision_id' => 902 ) ), // 902 belongs to 910
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( is_wp_error( $rD ), 'D.1: refuses' );
eq( 'snt_sn_apply_revision_wrong_parent', $rD->get_error_code(), 'D.2: error code' );
eq( 409, (int) ( $rD->get_error_data()['status'] ?? 0 ), 'D.3: status 409' );
ok( false !== strpos( $rD->get_error_message(), '910' ) && false !== strpos( $rD->get_error_message(), '900' ), 'D.4: the refusal names BOTH the actual parent (910) and the requested target (900)' );

/* ════════════════════════════════════════════════════════════════════════
 * Test E: missing fingerprint -> 422 (distinct from a stale/mismatched one,
 * which is 409 — Test F below).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest E: missing fingerprint -> 422 (distinct from mismatched -> 409)\n";
$rE = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'change' => array( 'type' => 'restore_revision', 'payload' => array( 'revision_id' => 901 ) ), // no 'fingerprint' key at all
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( is_wp_error( $rE ), 'E.1: refuses' );
eq( 'snt_sn_apply_missing_fingerprint', $rE->get_error_code(), 'E.2: error code' );
eq( 422, (int) ( $rE->get_error_data()['status'] ?? 0 ), 'E.3: status 422 (not the generic 409 every other gate1 failure gets)' );

echo "\nTest F: mismatched fingerprint -> 409 (the stale-branch merge conflict)\n";
$rF = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => 'deadbeefdeadbeefdeadbeefdeadbeef', 'payload' => array( 'revision_id' => 901 ) ),
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( is_wp_error( $rF ), 'F.1: refuses' );
eq( 'snt_sn_apply_fingerprint_stale', $rF->get_error_code(), 'F.2: error code (the generic default — restore_revision only overrides for the MISSING case)' );
eq( 409, (int) ( $rF->get_error_data()['status'] ?? 0 ), 'F.3: status 409' );
$decodedF = json_decode( $rF->get_error_message(), true );
eq( $fp900, $decodedF['gates']['fingerprint']['observed'] ?? null, 'F.4: observed = the CURRENT live content_hash (re-derivable without a second lookup)' );

/* ════════════════════════════════════════════════════════════════════════
 * Test G: gate-2 findings are computed from the REVISION's fields, not the
 * live post's — proven by a drift-lexicon phrase present ONLY in the
 * revision's content.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest G: gate 2 findings reflect the REVISION's content, not the live post's\n";
tf_post( 903, array( 'post_content' => 'Live content has no drift phrases at all.' ) );
tf_post( 904, array( 'post_type' => 'revision', 'post_parent' => 903, 'post_content' => 'This mentions last week explicitly, a drift phrase.', 'post_title' => 'Post 903', 'post_excerpt' => '' ) );
$fp903 = snt_corpus_content_hash( $GLOBALS['__posts'][903]['post_content'] );
$rG = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 903 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp903, 'payload' => array( 'revision_id' => 904 ) ),
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( ! is_wp_error( $rG ), 'G.1: does not refuse (drift_lexicon is a warning, not an error)' );
$drift_found = false;
foreach ( $rG['gates']['validation']['findings'] as $f ) {
	if ( 'drift_lexicon' === ( $f['check'] ?? '' ) && false !== strpos( (string) ( $f['observed'] ?? '' ), 'last week' ) ) { $drift_found = true; }
}
ok( $drift_found, 'G.2: a drift_lexicon finding for "last week" IS present — proves gate 2 read the REVISION\'s content (live 903 has no such phrase at all)' );

/* ════════════════════════════════════════════════════════════════════════
 * Test H: dry_run zero-writes with a {before,after,fields_changed} diff
 * shape.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest H: dry_run (defaulted) -> zero writes, diff shape is {before,after,fields_changed}\n";
tf_reset_writes();
$posts_before = $GLOBALS['__posts'];
$opts_before  = $GLOBALS['__options'];
$rH = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp900, 'payload' => array( 'revision_id' => 901 ) ),
	'mode'   => 'publish', // dry_run omitted -- must default true
) );
ok( ! is_wp_error( $rH ), 'H.1: does not refuse' );
eq( false, $rH['applied'] ?? null, 'H.2: applied:false' );
eq( 0, tf_total_writes(), 'H.3: ZERO writes across every write primitive' );
eq( $posts_before, $GLOBALS['__posts'], 'H.4: post store byte-identical' );
eq( $opts_before, $GLOBALS['__options'], 'H.5: options store byte-identical (no idempotency/staged-meta row from a dry run without a key)' );
ok( is_array( $rH['diff'] ) && array_key_exists( 'before', $rH['diff'] ) && array_key_exists( 'after', $rH['diff'] ) && array_key_exists( 'fields_changed', $rH['diff'] ), 'H.6: diff shape is {before,after,fields_changed}' );
eq( 'Live current content.', $rH['diff']['before']['post_content'] ?? null, 'H.7: diff.before = the LIVE content' );
eq( 'Staged proposal content.', $rH['diff']['after']['post_content'] ?? null, 'H.8: diff.after = the REVISION content' );

/* ════════════════════════════════════════════════════════════════════════
 * Test I: rollback snapshot STAGED (newest revision != live) — the common
 * case, since the revision being restored almost always differs from live.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest I: rollback snapshot is STAGED fresh when the newest revision != live, and restore applies via the real wrapper\n";
tf_reset_writes();
$posts_count_before = count( $GLOBALS['__posts'] );
$rI = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp900, 'payload' => array( 'revision_id' => 901, 'apply_staged_meta' => false ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rI ), 'I.1: apply does not refuse' );
eq( true, $rI['applied'] ?? null, 'I.2: applied:true' );
eq( 1, $GLOBALS['__write_calls']['_wp_put_post_revision'], 'I.3: _wp_put_post_revision() called exactly once — a NEW rollback snapshot was staged (901 was the only pre-existing revision and it differs from live)' );
eq( 900, $rI['post_id'] ?? null, 'I.4: post_id' );
eq( 901, $rI['restored_revision_id'] ?? null, 'I.5: restored_revision_id = the revision that was actually restored' );
$rollback_id = $rI['rollback_revision_id'] ?? null;
ok( is_int( $rollback_id ) && $rollback_id > 0, 'I.6: rollback_revision_id is a real int' );
ok( $rollback_id !== 901, 'I.7: rollback_revision_id is NOT the restored revision (901) — restoring it would re-apply the restore, not undo it' );
eq( $posts_count_before + 1, count( $GLOBALS['__posts'] ), 'I.8: exactly one new post row exists (the fresh rollback snapshot)' );
$snapshot_row = $GLOBALS['__posts'][ $rollback_id ];
eq( 'revision', $snapshot_row['post_type'], 'I.9: the snapshot row is post_type=revision' );
eq( 900, $snapshot_row['post_parent'], 'I.10: the snapshot post_parent = 900' );
eq( 'Live current content.', $snapshot_row['post_content'], 'I.11: the snapshot carries the PRE-RESTORE live content (never the revision\'s content)' );
eq( 'Staged proposal content.', $GLOBALS['__posts'][900]['post_content'], 'I.12: the live post NOW holds the restored (revision\'s) content — restore applied via the real wrapper path' );
ok( isset( $rI['rollback'] ) && 'restore_revision' === ( $rI['rollback']['method'] ?? '' ), 'I.13: rollback.method = restore_revision' );
eq( $rollback_id, $rI['rollback']['revision_id'] ?? null, 'I.14: rollback.revision_id = the SNAPSHOT (never the just-restored revision 901) — response rollback always points at the pre-restore state' );

/* ════════════════════════════════════════════════════════════════════════
 * Test J: rollback snapshot REUSED (not duplicated) when the newest
 * existing revision already matches live's current state.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest J: rollback snapshot is REUSED, never duplicated, when the newest revision already matches live\n";
tf_post( 920, array( 'post_content' => 'Post 920 live content.', 'post_title' => 'Post 920', 'post_excerpt' => 'Post 920 excerpt.' ) );
// 921: the revision to RESTORE (differs from live).
tf_post( 921, array( 'post_type' => 'revision', 'post_parent' => 920, 'post_content' => 'Post 920 proposed content.', 'post_title' => 'Post 920', 'post_excerpt' => 'Post 920 excerpt.' ) );
// 922: created AFTER 921 (higher ID => sorts newest) and matches live's
// CURRENT state exactly — the reusable snapshot.
tf_post( 922, array( 'post_type' => 'revision', 'post_parent' => 920, 'post_content' => 'Post 920 live content.', 'post_title' => 'Post 920', 'post_excerpt' => 'Post 920 excerpt.' ) );
$fp920 = snt_corpus_content_hash( $GLOBALS['__posts'][920]['post_content'] );

tf_reset_writes();
$posts_count_before_j = count( $GLOBALS['__posts'] );
$rJ = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 920 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp920, 'payload' => array( 'revision_id' => 921, 'apply_staged_meta' => false ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rJ ), 'J.1: apply does not refuse' );
eq( true, $rJ['applied'] ?? null, 'J.2: applied:true' );
eq( 0, $GLOBALS['__write_calls']['_wp_put_post_revision'], 'J.3: _wp_put_post_revision() NEVER called — no new snapshot was created, the existing 922 was reused' );
eq( $posts_count_before_j, count( $GLOBALS['__posts'] ), 'J.4: post count UNCHANGED — no duplicate snapshot row was created' );
eq( 922, $rJ['rollback_revision_id'] ?? null, 'J.5: rollback_revision_id = 922, the pre-existing matching revision, REUSED' );
eq( 'Post 920 proposed content.', $GLOBALS['__posts'][920]['post_content'], 'J.6: the live post now holds the restored content' );

/* ════════════════════════════════════════════════════════════════════════
 * Test K: idempotent replay verbatim + cross-target freshness.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest K: idempotent replay verbatim, and a DIFFERENT target under the same key is a fresh execution\n";
tf_post( 930, array( 'post_content' => 'Post 930 live content.' ) );
tf_post( 931, array( 'post_type' => 'revision', 'post_parent' => 930, 'post_content' => 'Post 930 proposed content.', 'post_title' => 'Post 930', 'post_excerpt' => '' ) );
$fp930 = snt_corpus_content_hash( $GLOBALS['__posts'][930]['post_content'] );

tf_post( 940, array( 'post_content' => 'Post 940 live content.' ) );
tf_post( 941, array( 'post_type' => 'revision', 'post_parent' => 940, 'post_content' => 'Post 940 proposed content.', 'post_title' => 'Post 940', 'post_excerpt' => '' ) );
$fp940 = snt_corpus_content_hash( $GLOBALS['__posts'][940]['post_content'] );

$rK1 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 930 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp930, 'payload' => array( 'revision_id' => 931, 'apply_staged_meta' => false ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'rr-idem-1',
) );
ok( ! is_wp_error( $rK1 ) && true === ( $rK1['applied'] ?? null ), 'K.1: first call applies' );
eq( false, $rK1['replayed'], 'K.2: first call replayed:false' );

$rK2 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 930 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp930, 'payload' => array( 'revision_id' => 931, 'apply_staged_meta' => false ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'rr-idem-1',
) );
ok( ! is_wp_error( $rK2 ), 'K.3: second call (same key, same target) does not refuse' );
eq( true, $rK2['replayed'], 'K.4: second call replayed:true' );
eq( $rK1['rollback_revision_id'], $rK2['rollback_revision_id'], 'K.5: second call returns the FIRST result verbatim (same rollback_revision_id)' );

$rK3 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 940 ), // DIFFERENT target, SAME key
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp940, 'payload' => array( 'revision_id' => 941, 'apply_staged_meta' => false ) ),
	'mode'   => 'publish', 'dry_run' => false, 'idempotency_key' => 'rr-idem-1',
) );
ok( ! is_wp_error( $rK3 ), 'K.6: third call (same key, DIFFERENT target) does not refuse' );
eq( false, $rK3['replayed'] ?? null, 'K.7: DIFFERENT target with the same key is a FRESH execution, not a replay' );
eq( 940, $rK3['post_id'] ?? null, 'K.8: the response is about THIS call\'s target (940), never the first call\'s (930)' );
eq( 'Post 940 proposed content.', $GLOBALS['__posts'][940]['post_content'], 'K.9: post 940 actually received its own restore — the executor genuinely ran' );

/* ════════════════════════════════════════════════════════════════════════
 * Test L: staged-meta rows applied + option rows deleted + meta_applied
 * reported.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest L: staged-meta rows are applied via update_post_meta, their option rows deleted, and meta_applied reported\n";
tf_post( 950, array( 'post_content' => 'Post 950 live content.' ) );
tf_post( 951, array( 'post_type' => 'revision', 'post_parent' => 950, 'post_content' => 'Post 950 proposed content.', 'post_title' => 'Post 950', 'post_excerpt' => '' ) );
$fp950 = snt_corpus_content_hash( $GLOBALS['__posts'][950]['post_content'] );

$staged1 = snt_sn_apply_stage_meta( 950, '_sn_meta_description', 'New meta description', 'fp-md' );
$staged2 = snt_sn_apply_stage_meta( 950, '_sn_og_card_title', 'New OG Title', 'fp-og' );
ok( ! is_wp_error( $staged1 ) && ! is_wp_error( $staged2 ), 'L.1: both meta rows staged successfully' );
ok( null !== snt_sn_apply_get_staged_meta( 950, '_sn_meta_description' ), 'L.2: sanity — staged row retrievable BEFORE restore' );

$rL = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 950 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp950, 'payload' => array( 'revision_id' => 951 ) ), // apply_staged_meta omitted -> default true
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rL ), 'L.3: apply does not refuse' );
eq( 'New meta description', $GLOBALS['__post_meta'][950]['_sn_meta_description'] ?? null, 'L.4: _sn_meta_description applied via update_post_meta' );
eq( 'New OG Title', $GLOBALS['__post_meta'][950]['_sn_og_card_title'] ?? null, 'L.5: _sn_og_card_title applied via update_post_meta' );
ok( null === snt_sn_apply_get_staged_meta( 950, '_sn_meta_description' ), 'L.6: the _sn_meta_description staged option row was deleted after applying' );
ok( null === snt_sn_apply_get_staged_meta( 950, '_sn_og_card_title' ), 'L.7: the _sn_og_card_title staged option row was deleted after applying' );
$meta_applied = $rL['meta_applied'] ?? array();
sort( $meta_applied );
eq( array( '_sn_meta_description', '_sn_og_card_title' ), $meta_applied, 'L.8: meta_applied reports both keys' );

/* ════════════════════════════════════════════════════════════════════════
 * Test M: attachment-staged meta (a DIFFERENT target's own queue) is
 * untouched by a post restore.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nTest M: attachment-staged meta is untouched by an unrelated post's restore\n";
tf_post( 960, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
$staged_attachment = snt_sn_apply_stage_meta( 960, '_wp_attachment_image_alt', 'Alt text proposal', 'fp-alt' );
ok( ! is_wp_error( $staged_attachment ), 'M.1: attachment meta staged successfully' );

tf_post( 970, array( 'post_content' => 'Post 970 live content.' ) );
tf_post( 971, array( 'post_type' => 'revision', 'post_parent' => 970, 'post_content' => 'Post 970 proposed content.', 'post_title' => 'Post 970', 'post_excerpt' => '' ) );
$fp970 = snt_corpus_content_hash( $GLOBALS['__posts'][970]['post_content'] );

$rM = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 970 ),
	'change' => array( 'type' => 'restore_revision', 'fingerprint' => $fp970, 'payload' => array( 'revision_id' => 971 ) ), // default true
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rM ), 'M.2: apply does not refuse' );
$attachment_staged_still = snt_sn_apply_get_staged_meta( 960, '_wp_attachment_image_alt' );
ok( null !== $attachment_staged_still, 'M.3: attachment 960\'s staged meta is STILL PRESENT — a different target\'s (post 970) restore never touches it' );
eq( 'Alt text proposal', $attachment_staged_still['proposed_value'], 'M.4: attachment 960\'s staged value is unchanged' );
ok( ! in_array( '_wp_attachment_image_alt', $rM['meta_applied'] ?? array(), true ), 'M.5: meta_applied does not include the attachment\'s meta_key — it was never in post 970\'s own queue' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
