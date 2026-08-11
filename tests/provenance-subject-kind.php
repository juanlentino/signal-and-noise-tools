<?php
/**
 * Subject kinds (v10.84.0): R2A step 2 — the plugin learns to sign more than notes.
 *
 * The Worker (sn-provenance v1.10.0+) builds the ledger path from a `kind` on
 * the dispatch. This is the plugin half: one resolver decides what is a subject
 * and which kind it is.
 *
 * TWO THINGS THIS SUITE EXISTS TO STOP.
 *
 * 1. THE SECOND GATE. sn_prov_is_note() asks has_term( 'notes', 'category', … ),
 *    and `category` is a POST-ONLY taxonomy — a page can never satisfy it. So
 *    widening the post_type check alone would have looked like shipping the
 *    feature while changing nothing at all. A page must reach the dispatch, and
 *    a non-note post must still not.
 *
 * 2. PAGES ARE OPT-IN AND MUST STAY THAT WAY. The ledger is public,
 *    append-only and Bitcoin-anchored — every signed version is permanent.
 *    Signing pages wholesale would ledger /verify, /stats and the maturity
 *    pages, whose text changes because a number moved rather than because
 *    anyone wrote anything, minting a new anchored version each time. If a
 *    future edit makes `page` default-on, these assertions go red.
 *
 * @since plugin v10.84.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

$GLOBALS['__meta']     = array();  // "id|key" => value
$GLOBALS['__has_term'] = array();  // id => bool

if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'add_action' ) )    { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) )    { function add_filter() { return true; } }
if ( ! function_exists( '__' ) )            { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'has_term' ) ) {
	function has_term( $term, $tax, $post_id = 0 ) {
		// Model the REAL constraint: `category` is a post-only taxonomy, so a
		// page never has one however it was tagged. A stub that answered from a
		// flat map would let a page look like a note and hide the whole bug.
		$type = $GLOBALS['__types'][ (int) $post_id ] ?? 'post';
		if ( 'post' !== $type || 'category' !== $tax ) { return false; }
		return ! empty( $GLOBALS['__has_term'][ (int) $post_id ] );
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		return $GLOBALS['__meta'][ (int) $id . '|' . $key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $val ) { $GLOBALS['__meta'][ (int) $id . '|' . $key ] = $val; return true; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; } }

$GLOBALS['__types'] = array();
function tk_post( $id, $type ) {
	$GLOBALS['__types'][ $id ] = $type;
	return (object) array( 'ID' => $id, 'post_type' => $type );
}

require_once __DIR__ . '/../inc/provenance-core.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Provenance subject kinds — R2A step 2 (v10.84.0)\n\n";

/* ═════════════════════════════════════════════════════════════════
 * 1. NOTES ARE UNCHANGED
 * ═════════════════════════════════════════════════════════════════ */

$note = tk_post( 1, 'post' );
$GLOBALS['__has_term'][1] = true;
ok( 'note' === sn_prov_subject_kind( $note ), 'a post in the notes category is kind "note"' );

$plain = tk_post( 2, 'post' );
$GLOBALS['__has_term'][2] = false;
ok( '' === sn_prov_subject_kind( $plain ), 'a post OUTSIDE the notes category is not a subject (unchanged behaviour)' );

/* ═════════════════════════════════════════════════════════════════
 * 2. PAGES ARE OPT-IN — the safety property
 * ═════════════════════════════════════════════════════════════════ */

$page = tk_post( 3, 'page' );
ok( '' === sn_prov_subject_kind( $page ),
	'DEFAULT OFF: a page with no opt-in meta is NOT a subject — the ledger is permanent, so pages are never signed wholesale' );

update_post_meta( 3, SN_PROV_SIGN_META, '1' );
ok( 'page' === sn_prov_subject_kind( $page ), 'a page the author opted in IS kind "page"' );

update_post_meta( 3, SN_PROV_SIGN_META, '' );
ok( '' === sn_prov_subject_kind( $page ), 'clearing the opt-in makes the page a non-subject again' );

// THE SECOND GATE, stated as an assertion. If someone "simplifies" the resolver
// back to a has_term() check, a page can never satisfy it and this goes red.
$GLOBALS['__has_term'][3] = true;     // meaningless for a page, deliberately
update_post_meta( 3, SN_PROV_SIGN_META, '1' );
ok( 'page' === sn_prov_subject_kind( $page ),
	'a page does not need — and cannot have — a category: `category` is a POST-ONLY taxonomy' );

/* ═════════════════════════════════════════════════════════════════
 * 3. EVERYTHING ELSE IS REFUSED
 * ═════════════════════════════════════════════════════════════════ */

foreach ( array( 'attachment', 'nav_menu_item', 'revision', 'product', '' ) as $type ) {
	$other = tk_post( 10, $type );
	update_post_meta( 10, SN_PROV_SIGN_META, '1' ); // even opted in
	ok( '' === sn_prov_subject_kind( $other ),
		"post_type '$type' is never a subject, even with the opt-in meta set" );
}

ok( '' === sn_prov_subject_kind( null ) && '' === sn_prov_subject_kind( 'not-an-object' ),
	'a missing or malformed post is not a subject, and never fatal' );

// Media specifically: the board row says "pages and then media", and media is
// deliberately NOT modelled — the signature covers normalized PROSE and has
// nothing to say about a JPEG. Refusing is the honest answer until that exists.
$media = tk_post( 11, 'attachment' );
update_post_meta( 11, SN_PROV_SIGN_META, '1' );
ok( '' === sn_prov_subject_kind( $media ),
	'MEDIA IS OUT OF SCOPE: an attachment is refused even when opted in — the signature covers prose, not file bytes' );

/* ═════════════════════════════════════════════════════════════════
 * 4. THE SUBJECT POST-TYPE SET stays in step with the resolver
 * ═════════════════════════════════════════════════════════════════ */

$types = sn_prov_subject_post_types();
ok( in_array( 'post', $types, true ) && in_array( 'page', $types, true ),
	'the subject post-type set spans both kinds — the UID resolver and the reconcile sweep read this' );
ok( ! in_array( 'attachment', $types, true ),
	'and does not include attachments, so the sweep never walks media' );

// Every kind the resolver can return must be walkable by the resolver/sweep, or
// a signed subject would become unreachable to its own confirm callback.
$kinds_to_types = array( 'note' => 'post', 'page' => 'page' );
$all_walkable   = true;
foreach ( $kinds_to_types as $kind => $type ) {
	if ( ! in_array( $type, $types, true ) ) { $all_walkable = false; }
}
ok( $all_walkable,
	'every kind the resolver returns maps to a post type the sweep walks (no orphaned subject kind)' );

ok( array() === $GLOBALS['__php_errors'],
	'no PHP notices, warnings or deprecations: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
