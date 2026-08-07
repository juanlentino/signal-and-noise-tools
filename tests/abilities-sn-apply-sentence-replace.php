<?php
/**
 * Standalone tests for sn_apply change.type "sentence_replace" — the
 * agent-composed body edit. See inc/sn-apply-sentence-replace.php's
 * docblock for the design: the ONLY body type whose fingerprint a
 * composing caller can produce (the LIVE content_hash, restore_revision's
 * binding), plain-prose-only replacement, byte-exact sentence-scale
 * phrase, revision + publish modes via the shared write-callback.
 *
 * Same bootstrap/stub conventions as the sibling sn_apply test files (see
 * tests/abilities-sn-apply.php's docblock): standalone fixture per file,
 * each prints its own "N passed, M failed." for the tests/*.php CI glob.
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
	$defaults = array(
		'ID' => $id, 'post_title' => "Post $id", 'post_name' => "post-$id",
		'post_status' => 'publish', 'post_type' => 'post', 'post_parent' => 0,
		'post_date' => '2026-06-01 10:00:00', 'post_modified' => '2026-07-01 10:00:00',
		'post_modified_gmt' => '2026-07-01 10:00:00', 'post_content' => '', 'post_excerpt' => '',
	);
	// MEDIUM 1 fix (REJECT #10): a real revision's post_name carries core's
	// own "{parent}-revision-v1" / "{parent}-autosave-v1" idiom (real 6.9
	// source, wp-includes/revision.php's _wp_post_revision_data()) -- never
	// the generic "post-$id" default -- so a fixture representing a
	// pre-existing revision defaults to the REVISION shape unless the caller
	// overrides post_name explicitly (e.g. to model an autosave row).
	if ( 'revision' === ( $overrides['post_type'] ?? '' ) && ! isset( $overrides['post_name'] ) ) {
		$defaults['post_name'] = ( (int) ( $overrides['post_parent'] ?? 0 ) ) . '-revision-v1';
	}
	$GLOBALS['__posts'][ $id ] = array_merge( $defaults, $overrides );
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
/* ════════════════════════════════════════════════════════════════════════
 * Load the SUT
 * ════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/health-check-drift-time-phrases.php';
require __DIR__ . '/../inc/ai-drift-phrase-suggest.php';
require __DIR__ . '/../inc/sn-validate-checks.php';
require __DIR__ . '/../inc/sn-validate-checks-media.php';
require __DIR__ . '/../inc/sn-apply-revision.php';
require __DIR__ . '/../inc/sn-apply-gates.php';
require __DIR__ . '/../inc/sn-apply-validation.php';
require __DIR__ . '/../inc/sn-apply-delete-draft.php'; // v10.58.0 (audit item 6): gate 2 + write + preview for change.type delete_draft
require __DIR__ . '/../inc/sn-apply-link-reshape.php'; // v10.58.0 (audit item 5): pair validator + locator + identity-asserting splice for change.type link_reshape
require __DIR__ . '/../inc/sn-apply-sentence-replace.php';
require __DIR__ . '/../inc/sn-apply-executors.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';

echo "sn_apply sentence_replace — the agent-composed body edit\n\n";

function tf_reset_writes() {
	foreach ( $GLOBALS['__write_calls'] as $k => $_ ) { $GLOBALS['__write_calls'][ $k ] = 0; }
}
function tf_total_writes() {
	return array_sum( $GLOBALS['__write_calls'] );
}

/* ════════════════════════════════════════════════════════════════════════
 * Fixture: one post, one long sentence inside a paragraph block.
 * ════════════════════════════════════════════════════════════════════════ */
$long   = 'It can hand the artist a tool to sign their own work with their own key, and attest to nothing about the content beyond the fact that a signature was applied at a given time.';
$split  = 'It can hand the artist a tool to sign their own work with their own key. That tool attests to nothing about the content itself, only to the fact that a signature was applied at a given time.';
$body   = '<!-- wp:paragraph --><p>The DAW does not have to vouch for anything. ' . $long . ' The meaning lives with the key holder.</p><!-- /wp:paragraph -->';
tf_post( 900, array( 'post_content' => $body ) );
$fp = snt_corpus_content_hash( $body );

function sr_call( $overrides = array() ) {
	global $fp, $long, $split;
	$base = array(
		'target'  => array( 'post_id' => 900 ),
		'mode'    => 'revision',
		'dry_run' => false,
		'change'  => array(
			'type'        => 'sentence_replace',
			'fingerprint' => $fp,
			'payload'     => array( 'phrase' => $long, 'replacement' => $split, 'context_snippet' => '' ),
		),
	);
	// Deep-ish merge, enough for these tests.
	foreach ( $overrides as $k => $v ) {
		if ( 'change' === $k ) {
			foreach ( $v as $ck => $cv ) {
				if ( 'payload' === $ck ) { $base['change']['payload'] = array_merge( $base['change']['payload'], $cv ); }
				elseif ( '__unset_fingerprint' === $ck ) { unset( $base['change']['fingerprint'] ); }
				else { $base['change'][ $ck ] = $cv; }
			}
		} else { $base[ $k ] = $v; }
	}
	return snt_ability_sn_apply( $base );
}

/* ════════════════════════════════════════════════════════════════════════
 * 1. Fingerprint contract — REQUIRED (422) vs stale (409), the
 *    restore_revision binding exactly.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$r = sr_call( array( 'change' => array( '__unset_fingerprint' => true ) ) );
ok( is_wp_error( $r ), 'FP.1: missing fingerprint refuses' );
eq( 'snt_sn_apply_missing_fingerprint', $r->get_error_code(), 'FP.2: missing fingerprint is the 422 caller error, distinct from stale' );
eq( 422, (int) ( $r->get_error_data()['status'] ?? 0 ), 'FP.3: 422, not 409' );
eq( 0, tf_total_writes(), 'FP.4: zero writes' );

tf_reset_writes();
$r = sr_call( array( 'change' => array( 'fingerprint' => str_repeat( 'd', 32 ) ) ) );
ok( is_wp_error( $r ), 'FP.5: stale fingerprint refuses' );
eq( 'snt_sn_apply_fingerprint_stale', $r->get_error_code(), 'FP.6: stale is the 409 merge conflict' );
eq( 409, (int) ( $r->get_error_data()['status'] ?? 0 ), 'FP.7: 409' );
$rep = json_decode( $r->get_error_message(), true );
eq( $fp, $rep['gates']['fingerprint']['observed'] ?? null, 'FP.8: gate reports the observed live content_hash so the caller can re-sync' );
eq( 0, tf_total_writes(), 'FP.9: zero writes' );

/* ════════════════════════════════════════════════════════════════════════
 * 2. Byte-exactness — a retyped smart-quote phrase is NOT the stored
 *    phrase (the live-agent trap this type documents).
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$curly = str_replace( 'artist', "\u{2019}artist", $long ); // one smart-quote character injected
$r = sr_call( array( 'change' => array( 'payload' => array( 'phrase' => $curly ) ) ) );
ok( is_wp_error( $r ), 'BYTE.1: near-miss phrase refuses' );
$rep = json_decode( $r->get_error_message(), true );
ok( false !== strpos( (string) ( $rep['gates']['fingerprint']['detail'] ?? '' ), 'byte-exact' ), 'BYTE.2: the refusal detail teaches the byte-exact contract' );
eq( 0, tf_total_writes(), 'BYTE.3: zero writes' );

/* ════════════════════════════════════════════════════════════════════════
 * 3. Pair fences — plain prose only, sentence-scale phrase only.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$r = sr_call( array( 'change' => array( 'payload' => array( 'replacement' => 'Now with <em>markup</em> inside.' ) ) ) );
ok( is_wp_error( $r ), 'PAIR.0: HTML replacement refuses' );
eq( 'snt_sn_apply_invalid_replacement', $r->get_error_code(), 'PAIR.1: HTML replacement refused — block structure unreachable from this type' );
eq( 422, (int) ( $r->get_error_data()['status'] ?? 0 ), 'PAIR.2: 422 caller error' );

$r = sr_call( array( 'change' => array( 'payload' => array( 'phrase' => 'short span' ) ) ) );
ok( is_wp_error( $r ), 'PAIR.2b: sub-sentence phrase refuses' );
eq( 'snt_sn_apply_invalid_phrase', $r->get_error_code(), 'PAIR.3: sub-sentence phrase refused (first-occurrence splice hazard)' );

$r = sr_call( array( 'change' => array( 'payload' => array( 'replacement' => str_repeat( 'long ', 500 ) ) ) ) );
ok( is_wp_error( $r ), 'PAIR.3b: oversize replacement refuses' );
eq( 'snt_sn_apply_invalid_replacement', $r->get_error_code(), 'PAIR.4: oversize replacement refused — sentence surgery, not a section rewrite' );
eq( 0, tf_total_writes(), 'PAIR.5: zero writes across all pair refusals' );

// Review-MEDIUM pin: '<' in ordinary prose notation is NOT html — only
// tag-shaped sequences ('<' + letter//!?) refuse.
$r = sr_call( array( 'change' => array( 'payload' => array( 'replacement' => 'The metric moved by <5 percent in the quarter, which is fairly minor overall for this site.' ) ) ) );
ok( ! is_wp_error( $r ), 'PAIR.6: prose "<5 percent" is NOT rejected as HTML (strip_tags false-positive fixed)' );
$r = sr_call( array( 'change' => array( 'payload' => array( 'replacement' => 'A tag like <em>this</em> is still refused, and so is a lone closer.' ) ) ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_invalid_replacement' === $r->get_error_code(), 'PAIR.7: a real tag-shaped sequence still refuses' );

/* ════════════════════════════════════════════════════════════════════════
 * 4. dry_run defaults TRUE and previews the exact splice.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$snap = $GLOBALS['__posts'];
$r = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 900 ),
	'mode'   => 'revision',
	'change' => array( 'type' => 'sentence_replace', 'fingerprint' => $fp, 'payload' => array( 'phrase' => $long, 'replacement' => $split, 'context_snippet' => '' ) ),
) );
ok( ! is_wp_error( $r ) && false === ( $r['applied'] ?? null ), 'DRY.1: dry_run (defaulted) previews without applying' );
ok( true === ( $r['gates']['fingerprint']['passed'] ?? null ), 'DRY.2: fingerprint gate passed' );
eq( $GLOBALS['__posts'][900]['post_content'], $r['diff']['before'] ?? null, 'DRY.3: diff.before is the live content' );
ok( false !== strpos( (string) ( $r['diff']['after'] ?? '' ), 'That tool attests to nothing' ), 'DRY.4: diff.after shows the split applied' );
ok( false === strpos( (string) ( $r['diff']['after'] ?? '' ), $long ), 'DRY.5: the original long sentence is gone from the preview' );
eq( 0, tf_total_writes(), 'DRY.6: zero writes' );
eq( $snap, $GLOBALS['__posts'], 'DRY.7: post store byte-identical' );

/* ════════════════════════════════════════════════════════════════════════
 * 5. mode:"revision" — stages a revision, never touches the live post.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$live_before = $GLOBALS['__posts'][900]['post_content'];
$r = sr_call();
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'REV.1: applies' );
ok( is_int( $r['revision_id'] ?? null ) && $r['revision_id'] > 0, 'REV.2: a revision ID comes back' );
eq( $live_before, $GLOBALS['__posts'][900]['post_content'], 'REV.3: live post content UNTOUCHED' );
$rev_row = $GLOBALS['__posts'][ $r['revision_id'] ] ?? null;
ok( is_array( $rev_row ) && false !== strpos( (string) $rev_row['post_content'], 'That tool attests to nothing' ), 'REV.4: the staged revision carries the split content' );
eq( 'revision', $rev_row['post_type'] ?? null, 'REV.5: it is a real revision row parented to the post' );
eq( 900, (int) ( $rev_row['post_parent'] ?? 0 ), 'REV.6: parented to the target' );

/* ════════════════════════════════════════════════════════════════════════
 * 6. mode:"publish" — writes the live post; the fingerprint from BEFORE
 *    the staged revision still matches (staging never changed the live row).
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$r = sr_call( array( 'mode' => 'publish' ) );
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'PUB.1: applies live' );
ok( false !== strpos( (string) $GLOBALS['__posts'][900]['post_content'], 'That tool attests to nothing' ), 'PUB.2: live content carries the split' );
ok( false === strpos( (string) $GLOBALS['__posts'][900]['post_content'], $long ), 'PUB.3: the long sentence is gone from the live row' );

/* ════════════════════════════════════════════════════════════════════════
 * 7. After the live write, the OLD fingerprint is stale — the merge
 *    conflict fires on the very next compose-against-stale attempt.
 * ════════════════════════════════════════════════════════════════════════ */
$r = sr_call( array( 'change' => array( 'payload' => array( 'phrase' => $split ) ) ) );
ok( is_wp_error( $r ), 'STALE.0: refuses' );
eq( 'snt_sn_apply_fingerprint_stale', $r->get_error_code(), 'STALE.1: yesterday\'s content_hash refuses after the content moved' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
