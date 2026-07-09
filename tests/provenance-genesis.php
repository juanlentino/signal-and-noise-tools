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
$GLOBALS['__pv_meta'] = array();
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
	function get_post_meta( $id, $k, $single = false ) {
		$v = $GLOBALS['__pv_meta'][ $id ][ $k ] ?? null;
		return $single ? ( null === $v ? '' : $v ) : ( null === $v ? array() : array( $v ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) {
		$GLOBALS['__pv_meta'][ $id ][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	$GLOBALS['__pv_uidc'] = 0;
	function wp_generate_uuid4() {
		return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['__pv_uidc'] ); }
}
require_once SNT_PATH . 'inc/provenance-core.php';
require_once SNT_PATH . 'inc/provenance-genesis.php';

$pass = 0;
$fail = 0;
function gn_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function gn_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}
function gn_make_post( $id, $title, $body, $date ) {
	$p               = new stdClass();
	$p->ID           = $id;
	$p->post_title   = $title;
	$p->post_content = $body;
	$p->post_date    = $date;
	$p->post_date_gmt = $date;
	$p->post_author  = 1;
	return $p;
}

echo "Genesis suite\n\nTask 2: v0 leaf\n";
$p1   = gn_make_post( 101, 'First note', '<p>Body one.</p>', '2025-01-01 00:00:00' );
$leaf = sn_prov_genesis_leaf( $p1, 'Juan Lentino' );
gn_true( is_string( $leaf ) && '' !== $leaf, 'leaf is a non-empty canonical string' );
$decoded = json_decode( $leaf, true );
gn_eq( 0, $decoded['version'], 'v0 payload' );
gn_eq( null, $decoded['parent'], 'v0 parent null' );
gn_eq( 'Body one.', $decoded['content'], 'v0 content normalized' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
