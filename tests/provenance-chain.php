<?php
/**
 * Standalone fixture tests for the Notes-provenance commit core
 * (inc/provenance-core.php) — note_uid, hashing, payload, chain, coalescing.
 */
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

// ── WP stubs ──────────────────────────────────────────────────────────
$GLOBALS['__pv_meta'] = array(); // [ post_id ][ key ] => value

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		$v = $GLOBALS['__pv_meta'][ $id ][ $key ] ?? null;
		if ( $single ) {
			return null === $v ? '' : $v;
		}
		return null === $v ? array() : array( $v );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['__pv_meta'][ $id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
			wp_rand_stub(),
			wp_rand_stub(),
			wp_rand_stub(),
			wp_rand_stub() & 0x0fff,
			wp_rand_stub() & 0x3fff | 0x8000,
			wp_rand_stub(),
			wp_rand_stub(),
			wp_rand_stub()
		);
	}
	function wp_rand_stub() {
		return random_int( 0, 0xffff ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
		return json_encode( $data, $flags, $depth );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s, $remove_breaks = false ) {
		$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s );
		$s = strip_tags( $s );
		return trim( $s );
	}
}

require_once SNT_PATH . 'inc/provenance-core.php';

$pass = 0;
$fail = 0;
function pv_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  PASS: $msg\n";
	} else {
		++$fail;
		echo "  FAIL: $msg\n";
	}
}
function pv_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		++$pass;
		echo "  PASS: $msg\n";
	} else {
		++$fail;
		echo "  FAIL: $msg\n    Expected: " . var_export( $expected, true ) . "\n    Actual:   " . var_export( $actual, true ) . "\n";
	}
}

echo "Notes-provenance core suite\n\nTask 1: bootstrap\n";
pv_true( defined( 'SN_PROV_ALGO' ) && 'sn-normalize-v1' === SN_PROV_ALGO, 'SN_PROV_ALGO defined' );
pv_true( defined( 'SN_PROV_CHAIN_META' ), 'SN_PROV_CHAIN_META defined' );
pv_true( function_exists( 'sn_prov_active' ), 'sn_prov_active() exists' );

echo "\nTask 2: note_uid minting\n";
$uid1 = sn_prov_note_uid( 501 );
pv_true( is_string( $uid1 ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uid1 ), 'note_uid is a v4 UUID' );
pv_eq( $uid1, sn_prov_note_uid( 501 ), 'note_uid is stable across calls (persisted)' );
pv_true( $uid1 !== sn_prov_note_uid( 502 ), 'distinct posts get distinct uids' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
