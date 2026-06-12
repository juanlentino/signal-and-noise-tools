<?php
/**
 * Tests for inc/indexnow.php — key management, request key-file serving,
 * enqueue hygiene, deferred submission payload, and the lifecycle handlers.
 * Standalone CLI fixture; stubs the WP option store + HTTP + scheduling.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

// ── In-memory option store ──────────────────────────────────────────
$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
// ── Settings + WP stubs ─────────────────────────────────────────────
$GLOBALS['__settings'] = array(); // dot-path => value
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $s ) { return $s; }
function add_action() {} // no-op: suppress hook registration on require
function add_filter() {}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/indexnow.php';

// ── Key management ──────────────────────────────────────────────────
ok( '' === sn_indexnow_get_key(), 'key: empty before generation' );
$k1 = sn_indexnow_ensure_key();
ok( 1 === preg_match( '/^[a-f0-9]{8,128}$/', $k1 ), 'key: generated key is valid IndexNow charset (' . $k1 . ')' );
ok( 32 === strlen( $k1 ), 'key: 32 hex chars' );
$k2 = sn_indexnow_ensure_key();
ok( $k1 === $k2, 'key: ensure is idempotent (does not regenerate)' );
$k3 = sn_indexnow_regenerate_key();
ok( $k3 !== $k1 && 1 === preg_match( '/^[a-f0-9]{32}$/', $k3 ), 'key: regenerate mints a different valid key' );
ok( sn_indexnow_key_url() === 'https://example.com/' . $k3 . '.txt', 'key: key_url is home-root /<key>.txt' );

// ── Key-file serving decision (pure, no header/exit) ────────────────
$GLOBALS['__options'][ SN_INDEXNOW_KEY_OPT ] = $k3; // current key (from regenerate above)
$GLOBALS['__settings']['indexnow.enabled']   = true;
ok( $k3 === sn_indexnow_key_for_request( '/' . $k3 . '.txt' ), 'serve: matching /<key>.txt yields the key' );
ok( $k3 === sn_indexnow_key_for_request( '/' . $k3 . '.txt?foo=bar' ), 'serve: query string is ignored' );
ok( '' === sn_indexnow_key_for_request( '/deadbeefdeadbeef.txt' ), 'serve: a different valid-shaped key is refused' );
ok( '' === sn_indexnow_key_for_request( '/notes/' ), 'serve: a normal path is ignored' );
$GLOBALS['__settings']['indexnow.enabled'] = false;
ok( '' === sn_indexnow_key_for_request( '/' . $k3 . '.txt' ), 'serve: disabled → not served even on the right path' );
$GLOBALS['__settings']['indexnow.enabled'] = true;
$GLOBALS['__options'][ SN_INDEXNOW_KEY_OPT ] = '';
ok( '' === sn_indexnow_key_for_request( '/aaaaaaaa.txt' ), 'serve: no stored key → nothing served' );
$GLOBALS['__options'][ SN_INDEXNOW_KEY_OPT ] = $k3; // restore for later tasks

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
