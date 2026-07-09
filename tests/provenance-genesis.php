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

echo "\nTask 3: assemble + persist\n";
$posts = array(
	gn_make_post( 201, 'Note A', '<p>Alpha body.</p>', '2025-02-01 00:00:00' ),
	gn_make_post( 202, 'Note B', '<p>Beta body.</p>',  '2025-03-01 00:00:00' ),
	gn_make_post( 203, 'Note C', '<p>Gamma body.</p>', '2025-04-01 00:00:00' ),
);
$genesis = sn_prov_genesis_build( $posts, 'Juan Lentino' );

gn_true( 1 === preg_match( '/^[0-9a-f]{64}$/', $genesis['root'] ), 'genesis root is 64-hex' );
gn_eq( 3, count( $genesis['leaves'] ), 'one leaf entry per Note' );

// Every stored inclusion proof verifies against the root.
foreach ( $genesis['leaves'] as $entry ) {
	gn_true(
		sn_prov_merkle_verify( $entry['leaf'], $entry['proof'], $genesis['root'] ),
		"proof verifies for note {$entry['post_id']}"
	);
}

// Persistence: each Note gets genesis parent + proof meta + a v0 chain entry.
sn_prov_genesis_persist( $genesis );
gn_eq( $genesis['root'], get_post_meta( 201, SN_PROV_GENESIS_META, true ), 'genesis root stored as parent baseline' );
$proof201 = get_post_meta( 201, SN_PROV_PROOF_META, true );
gn_true( is_array( $proof201 ) && count( $proof201 ) >= 1, 'inclusion proof stored on the Note' );
$chain201 = sn_prov_get_chain( 201 );
gn_eq( 0, $chain201[0]['version'], 'v0 commit written to the chain' );
gn_eq( 'genesis', $chain201[0]['status'], 'v0 commit marked genesis' );
gn_true( isset( $chain201[0]['genesis'] ) && true === $chain201[0]['genesis'], 'v0 commit flagged genesis snapshot' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
