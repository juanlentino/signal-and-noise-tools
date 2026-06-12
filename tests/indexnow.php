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

$GLOBALS['__scheduled'] = array();
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$GLOBALS['__scheduled'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
	return true;
}
$GLOBALS['__remote_post'] = array();
$GLOBALS['__remote_post_return'] = null; // null → default 200 below
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__remote_post'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['__remote_post_return'] ?? array( 'response' => array( 'code' => 200 ) );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { private $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function wp_json_encode( $d ) { return json_encode( $d ); }

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

// ── Enqueue hygiene ─────────────────────────────────────────────────
$GLOBALS['__settings']['indexnow.enabled'] = true;
$GLOBALS['__options'][ SN_INDEXNOW_KEY_OPT ] = $k3;
$GLOBALS['__scheduled'] = array();
sn_indexnow_enqueue( array(
	'https://example.com/notes/x/',
	'https://example.com/notes/x/',          // dupe
	'http://example.com/insecure/',          // non-https → dropped
	'https://other.com/foreign/',            // cross-host → dropped
	'https://example.com/notes/',
) );
ok( 1 === count( $GLOBALS['__scheduled'] ), 'enqueue: schedules exactly one cron event' );
$sched = $GLOBALS['__scheduled'][0]['args'][0];
ok( in_array( 'https://example.com/notes/x/', $sched, true ) && in_array( 'https://example.com/notes/', $sched, true ), 'enqueue: keeps same-host https URLs' );
ok( 2 === count( $sched ), 'enqueue: de-dupes + drops non-https + cross-host (2 survive)' );
ok( SN_INDEXNOW_CRON_HOOK === $GLOBALS['__scheduled'][0]['hook'], 'enqueue: schedules the submit hook' );

$GLOBALS['__settings']['indexnow.enabled'] = false;
$GLOBALS['__scheduled'] = array();
sn_indexnow_enqueue( array( 'https://example.com/a/' ) );
ok( 0 === count( $GLOBALS['__scheduled'] ), 'enqueue: no-op when disabled' );
$GLOBALS['__settings']['indexnow.enabled'] = true;

// ── Submission payload + last-result ────────────────────────────────
$GLOBALS['__remote_post'] = array();
sn_indexnow_submit( array( 'https://example.com/notes/x/', 'https://example.com/notes/' ) );
ok( 1 === count( $GLOBALS['__remote_post'] ), 'submit: makes exactly one POST' );
ok( SN_INDEXNOW_ENDPOINT === $GLOBALS['__remote_post'][0]['url'], 'submit: POSTs to api.indexnow.org/indexnow' );
$body = json_decode( $GLOBALS['__remote_post'][0]['args']['body'], true );
ok( 'example.com' === $body['host'], 'submit: body host is the home host' );
ok( $body['key'] === $k3 && $body['keyLocation'] === sn_indexnow_key_url(), 'submit: body carries key + keyLocation' );
ok( 2 === count( $body['urlList'] ), 'submit: body urlList has both URLs' );
ok( true === $GLOBALS['__remote_post'][0]['args']['blocking'], 'submit: blocking POST (so the response can be logged)' );
$res = get_option( SN_INDEXNOW_RESULT_OPT );
ok( is_array( $res ) && 200 === $res['code'] && 2 === $res['count'], 'submit: last-result logs code + count' );

// WP_Error path stores the message + code 0 (swap the stub's return value).
$GLOBALS['__remote_post_return'] = new WP_Error( 'http', 'boom' );
sn_indexnow_submit( array( 'https://example.com/notes/x/' ) );
$err = get_option( SN_INDEXNOW_RESULT_OPT );
ok( 'boom' === $err['error'] && 0 === $err['code'], 'submit: WP_Error stores the message + code 0' );
$GLOBALS['__remote_post_return'] = null; // restore default 200 for any later calls

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
