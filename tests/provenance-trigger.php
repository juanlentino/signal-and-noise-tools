<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_type = 'post';
		public $post_status = 'publish';
		public $post_title = 'T';
		public $post_content = '<p>Body.</p>';
		public $post_date = '2026-01-01 00:00:00';
		public $post_date_gmt = '2026-01-01 00:00:00';
		public $post_author = 1;
	}
}
$GLOBALS['__pv_meta']  = array();
$GLOBALS['__pv_terms'] = array(); // post_id => bool has 'notes'
$GLOBALS['__pv_recorded'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action() {
		return null; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0, $depth = 512 ) {
		return json_encode( $d, $f, $depth ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s, $rb = false ) {
		return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $p ) {
		return is_object( $p ) ? $p->post_title : ''; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		$v = $GLOBALS['__pv_meta'][ $id ][ $key ] ?? null;
		return $single ? ( null === $v ? '' : $v ) : ( null === $v ? array() : array( $v ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['__pv_meta'][ $id ][ $key ] = $value;
		return true; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return '00000000-0000-4000-8000-000000000000'; }
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $id ) {
		return ! empty( $GLOBALS['__pv_autosave'] ); }
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $id ) {
		return ! empty( $GLOBALS['__pv_revision'] ); }
}
if ( ! function_exists( 'has_term' ) ) {
	function has_term( $term, $tax, $id ) {
		return ! empty( $GLOBALS['__pv_terms'][ is_object( $id ) ? $id->ID : $id ] );
	}
}
if ( ! function_exists( 'get_the_author_meta' ) ) {
	function get_the_author_meta( $field, $uid ) {
		return 'Juan Lentino'; }
}

require_once SNT_PATH . 'inc/provenance-core.php';

$pass = 0;
$fail = 0;
function tg_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}

function tg_make_post( $id ) {
	$p     = new WP_Post();
	$p->ID = $id;
	return $p;
}

echo "Provenance trigger guard-matrix suite\n\n";

// Baseline: a published Note in the 'notes' category records a commit.
$GLOBALS['__pv_terms'][10] = true;
sn_prov_on_after_insert( 10, tg_make_post( 10 ), true, null );
tg_eq( 1, count( sn_prov_get_chain( 10 ) ), 'published Note records a commit' );

// Wrong post type -> skip.
$p = tg_make_post( 11 );
$p->post_type = 'page';
$GLOBALS['__pv_terms'][11] = true;
sn_prov_on_after_insert( 11, $p, true, null );
tg_eq( 0, count( sn_prov_get_chain( 11 ) ), 'non-post type skipped' );

// Not published -> skip.
$p = tg_make_post( 12 );
$p->post_status = 'draft';
$GLOBALS['__pv_terms'][12] = true;
sn_prov_on_after_insert( 12, $p, true, null );
tg_eq( 0, count( sn_prov_get_chain( 12 ) ), 'draft skipped' );

// Not a Note (no category) -> skip.
sn_prov_on_after_insert( 13, tg_make_post( 13 ), true, null );
tg_eq( 0, count( sn_prov_get_chain( 13 ) ), 'non-Note post skipped' );

// Autosave -> skip.
$GLOBALS['__pv_terms'][14] = true;
$GLOBALS['__pv_autosave']  = true;
sn_prov_on_after_insert( 14, tg_make_post( 14 ), true, null );
tg_eq( 0, count( sn_prov_get_chain( 14 ) ), 'autosave skipped' );
$GLOBALS['__pv_autosave'] = false;

// Revision -> skip.
$GLOBALS['__pv_terms'][15] = true;
$GLOBALS['__pv_revision']  = true;
sn_prov_on_after_insert( 15, tg_make_post( 15 ), true, null );
tg_eq( 0, count( sn_prov_get_chain( 15 ) ), 'revision skipped' );
$GLOBALS['__pv_revision'] = false;

// ── v9.88.0 (hardening gate): a password-protected Note must never reach the
// PUBLIC, append-only ledger. A protected post IS status=publish, and the
// commit payload carries the entire normalized post_content — irreversible
// once written (git history + a Bitcoin anchor). sn_prov_credential() already
// gated on this; the RECORDING leg did not.
$p = tg_make_post( 20 );
$p->post_password = 'hunter2';
$GLOBALS['__pv_terms'][20] = true;
sn_prov_on_after_insert( 20, $p, true, null );
tg_eq( 0, count( sn_prov_get_chain( 20 ) ), 'password-protected published Note is NOT recorded to the public ledger' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
