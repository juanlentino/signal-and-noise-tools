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

$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_http']    = array(); // captured wp_remote_post calls

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
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__pv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0, $depth = 512 ) {
		return json_encode( $d, $f, $depth ); }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__pv_http'][] = array( $url, $args );
		return array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array(
			'signature'   => 'SIGBASE64',
			'pubkey_id'   => 'sn-ed25519-2026-07',
			'ledger_path' => 'notes/u/v1.json',
			'ots_status'  => 'pending',
		) ) );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) {
		return $t instanceof WP_Error; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return $r['response']['code'] ?? 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return $r['body'] ?? ''; }
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
	function wp_generate_uuid4() {
		return 'uuuuuuuu-0000-4000-8000-000000000000'; }
}

require_once SNT_PATH . 'inc/provenance-core.php';
require_once SNT_PATH . 'inc/provenance-webhook.php';

$pass = 0;
$fail = 0;
function wh_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function wh_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

echo "Provenance webhook suite\n\nTask 1: config\n";
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'https://worker.example/';
wh_eq( 'https://worker.example/', sn_prov_worker_url(), 'worker url from option' );
wh_eq( '', sn_prov_hmac_secret(), 'hmac secret empty when unset' );

echo "\nTask 2: dispatch\n";
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
// Seed a chain entry (version 1) as Plan 1 would have appended.
update_post_meta( 42, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'unanchored' ) ) );
update_post_meta( 42, SN_PROV_UID_META, 'u' );

$GLOBALS['__pv_http'] = array();
$commit   = array( 'version' => 1, 'content_hash' => 'aa' );
$canonical = '{"algo":"sn-normalize-v1"}';
sn_prov_dispatch( 42, $commit, $canonical );

wh_eq( 1, count( $GLOBALS['__pv_http'] ), 'one webhook POST fired' );
$sent = $GLOBALS['__pv_http'][0];
wh_eq( 'https://worker.example/', $sent[0], 'posted to the worker url' );
$expected_sig = 'sha256=' . hash_hmac( 'sha256', $sent[1]['body'], 'shh' );
wh_eq( $expected_sig, $sent[1]['headers']['X-SN-Signature'], 'HMAC signature over raw body' );
$body = json_decode( $sent[1]['body'], true );
wh_eq( 'u', $body['note_uid'], 'body carries note_uid' );
wh_eq( $canonical, $body['canonical'], 'body carries canonical bytes' );

$chain = sn_prov_get_chain( 42 );
wh_eq( 'pending', $chain[0]['status'], 'chain entry flipped to pending' );
wh_eq( 'SIGBASE64', $chain[0]['signature'], 'signature stored on the commit' );
wh_eq( 'notes/u/v1.json', $chain[0]['ledger_path'], 'ledger_path stored' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
