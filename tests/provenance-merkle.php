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
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
require_once SNT_PATH . 'inc/provenance-genesis.php';

$pass = 0;
$fail = 0;
function mk_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function mk_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

echo "Merkle (RFC 6962) suite\n\n";

// Empty tree root = SHA-256("") — the RFC 6962 constant.
mk_eq( 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', sn_prov_merkle_root( array() ), 'empty root = sha256("")' );

// Single leaf root = leaf hash = SHA-256(0x00 || data).
mk_eq( bin2hex( hash( 'sha256', "\x00" . 'a', true ) ), sn_prov_merkle_root( array( 'a' ) ), 'single-leaf root = leaf hash' );

// Two-leaf root = node(leaf a, leaf b), structurally.
$la = hash( 'sha256', "\x00" . 'a', true );
$lb = hash( 'sha256', "\x00" . 'b', true );
mk_eq( bin2hex( hash( 'sha256', "\x01" . $la . $lb, true ) ), sn_prov_merkle_root( array( 'a', 'b' ) ), 'two-leaf root = node(leaf a, leaf b)' );

// Order sensitivity.
mk_true( sn_prov_merkle_root( array( 'a', 'b', 'c' ) ) !== sn_prov_merkle_root( array( 'c', 'b', 'a' ) ), 'root is order-sensitive' );

// Inclusion proofs verify for every leaf, including the odd/promoted one.
$leaves = array( 'alpha', 'beta', 'gamma', 'delta', 'epsilon' ); // 5 = odd promotion case
$root   = sn_prov_merkle_root( $leaves );
foreach ( $leaves as $i => $leaf ) {
	$proof = sn_prov_merkle_proof( $leaves, $i );
	mk_true( sn_prov_merkle_verify( $leaf, $proof, $root ), "inclusion proof verifies for leaf $i" );
}

// A tampered leaf must NOT verify against the honest proof.
$proof0 = sn_prov_merkle_proof( $leaves, 0 );
mk_true( ! sn_prov_merkle_verify( 'ALPHA-tampered', $proof0, $root ), 'tampered leaf rejected' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
