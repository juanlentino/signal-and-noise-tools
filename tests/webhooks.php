<?php
/**
 * Standalone fixture tests for inc/webhooks.php (v3.4.0).
 *
 * Verifies the pure logic: HMAC signature shape + reproducibility,
 * CRUD over the option store, log capping, payload shape, and the
 * transition_post_status guard's enqueue-on-publish path.
 *
 * Skips the actual HTTP I/O in sn_webhook_dispatch — that's pure
 * wp_remote_post which has its own WP-core coverage.
 *
 * Run: php tests/webhooks.php
 *
 * @since plugin v3.4.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS',   3600 );
define( 'DAY_IN_SECONDS',    86400 );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { return is_string( $u ) ? $u : ''; }
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $u ) {
		return is_string( $u ) && preg_match( '#^https?://#', $u ) === 1 ? $u : false;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) { return json_encode( $v ); }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $len = 12, $special = false, $extra = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}

$GLOBALS['__test_options'] = array();
function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__test_options'][ $key ] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__test_options'][ $key ] );
	return true;
}

$GLOBALS['__test_scheduled_events'] = array();
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$GLOBALS['__test_scheduled_events'][] = compact( 'ts', 'hook', 'args' );
	return true;
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return isset( $GLOBALS['__test_posts'][ $id ] ) ? $GLOBALS['__test_posts'][ $id ] : null;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $p ) { return is_object( $p ) ? $p->post_title : ''; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $p ) { return is_object( $p ) ? "https://juanlentino.com/?p={$p->ID}" : ''; }
}

class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

require_once __DIR__ . '/../inc/webhooks.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function wh_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function wh_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test 1: HMAC signature shape + determinism ──────────────────────
echo "\nTest 1: sn_webhook_compute_signature\n";
$sig = sn_webhook_compute_signature( 'secret_value', '{"hello":"world"}' );
wh_true( 0 === strpos( $sig, 'sha256=' ), 'signature begins with sha256=' );
wh_eq( 71, strlen( $sig ), 'signature is 71 chars (sha256= + 64 hex)' );
// Reproducibility: same secret + same body = same signature.
wh_eq( $sig, sn_webhook_compute_signature( 'secret_value', '{"hello":"world"}' ), 'deterministic for same inputs' );
// Sensitivity: different secret OR body changes the digest.
wh_true( $sig !== sn_webhook_compute_signature( 'other_secret', '{"hello":"world"}' ), 'changes with secret' );
wh_true( $sig !== sn_webhook_compute_signature( 'secret_value', '{"hello":"world!"}' ), 'changes with body' );
// Known vector — verified independently via openssl dgst.
$expected_hex = hash_hmac( 'sha256', 'body', 'secret' );
wh_eq( 'sha256=' . $expected_hex, sn_webhook_compute_signature( 'secret', 'body' ), 'matches independent hash_hmac' );

// ─── Test 2: CRUD round-trip ──────────────────────────────────────────
echo "\nTest 2: sn_webhook_create + sn_webhook_find\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
$created = sn_webhook_create( array( 'name' => 'n8n', 'url' => 'https://n8n.example/webhook/x', 'enabled' => '1' ) );
wh_true( is_array( $created ), 'create returns array on success' );
wh_true( 0 === strpos( $created['id'], 'wh_' ), 'id has wh_ prefix' );
wh_eq( 48, strlen( $created['secret'] ), 'secret is 48 chars' );
wh_eq( true, $created['enabled'], 'enabled flag set' );
wh_eq( 'https://n8n.example/webhook/x', $created['url'], 'url echoed' );
$found = sn_webhook_find( $created['id'] );
wh_eq( $created['id'], $found['id'], 'find returns the created webhook' );

// ─── Test 3: create validation ────────────────────────────────────────
echo "\nTest 3: sn_webhook_create validation\n";
$res = sn_webhook_create( array( 'name' => '', 'url' => 'https://x.example' ) );
wh_true( $res instanceof WP_Error, 'empty name rejected' );
wh_eq( 'sn_webhook_invalid_name', $res->code, 'invalid_name code' );
$res = sn_webhook_create( array( 'name' => 'x', 'url' => 'not-a-url' ) );
wh_true( $res instanceof WP_Error, 'invalid URL rejected' );
wh_eq( 'sn_webhook_invalid_url', $res->code, 'invalid_url code' );

// ─── Test 4: update + secret rotation ────────────────────────────────
echo "\nTest 4: sn_webhook_update\n";
$old_secret = $created['secret'];
$updated = sn_webhook_update( $created['id'], array( 'name' => 'n8n v2' ) );
wh_eq( 'n8n v2', $updated['name'], 'name updated' );
wh_eq( $old_secret, $updated['secret'], 'secret unchanged without rotate flag' );
$updated = sn_webhook_update( $created['id'], array( 'rotate_secret' => '1' ) );
wh_true( $updated['secret'] !== $old_secret, 'secret changes with rotate_secret=1' );
wh_eq( 48, strlen( $updated['secret'] ), 'rotated secret is still 48 chars' );

// ─── Test 5: delete ──────────────────────────────────────────────────
echo "\nTest 5: sn_webhook_delete\n";
$wh2 = sn_webhook_create( array( 'name' => 'second', 'url' => 'https://b.example' ) );
sn_webhook_log_record( $wh2['id'], array( 'delivery_id' => 'd1', 'attempt' => 1, 'fired_at' => 0, 'response_code' => 200, 'response_excerpt' => 'ok', 'success' => true ) );
wh_eq( 1, count( sn_webhook_log_read( $wh2['id'] ) ), 'log has 1 entry before delete' );
$res = sn_webhook_delete( $wh2['id'] );
wh_eq( true, $res, 'delete returns true' );
wh_eq( null, sn_webhook_find( $wh2['id'] ), 'find returns null after delete' );
wh_eq( 0, count( sn_webhook_log_read( $wh2['id'] ) ), 'log purged on delete' );

$res = sn_webhook_delete( 'nonexistent_id' );
wh_true( $res instanceof WP_Error, 'delete nonexistent → WP_Error' );

// ─── Test 6: log cap at 20 entries ────────────────────────────────────
echo "\nTest 6: sn_webhook_log_record caps at 20\n";
$wh3 = sn_webhook_create( array( 'name' => 'cap-test', 'url' => 'https://c.example' ) );
for ( $i = 0; $i < 25; $i++ ) {
	sn_webhook_log_record( $wh3['id'], array( 'delivery_id' => "d$i", 'attempt' => 1, 'fired_at' => $i, 'response_code' => 200, 'response_excerpt' => '', 'success' => true ) );
}
$log = sn_webhook_log_read( $wh3['id'] );
wh_eq( 20, count( $log ), 'log capped at SN_WEBHOOK_LOG_CAP (20)' );
// Newest entries retained — fired_at should range 5..24.
wh_eq( 5, $log[0]['fired_at'], 'oldest retained is index 5 (last 20 of 25)' );
wh_eq( 24, $log[19]['fired_at'], 'newest retained is the last one written' );

// ─── Test 7: payload shape ────────────────────────────────────────────
echo "\nTest 7: sn_webhook_build_post_published_payload\n";
$GLOBALS['__test_posts'] = array(
	100 => (object) array(
		'ID'            => 100,
		'post_status'   => 'publish',
		'post_title'    => 'A post',
		'post_name'     => 'a-post',
		'post_author'   => 1,
		'post_date_gmt' => '2026-05-20 12:00:00',
		'post_type'     => 'post',
	),
);
$body = sn_webhook_build_post_published_payload( 100, 'del_test' );
wh_true( is_string( $body ), 'returns a string body' );
$decoded = json_decode( $body, true );
wh_eq( 'post.published', $decoded['event'], 'event = post.published' );
wh_eq( 'del_test', $decoded['delivery_id'], 'delivery_id round-trips' );
wh_eq( 100, $decoded['post']['id'], 'post.id correct' );
wh_eq( 'A post', $decoded['post']['title'], 'post.title correct' );
wh_eq( 'post', $decoded['post']['type'], 'post.type correct' );
wh_true( $decoded['post']['published_at'] > 0, 'published_at is a unix ts' );

// Non-published post → null
$GLOBALS['__test_posts'][101] = (object) array( 'ID' => 101, 'post_status' => 'draft' );
wh_eq( null, sn_webhook_build_post_published_payload( 101, 'del_d' ), 'draft post → null' );
wh_eq( null, sn_webhook_build_post_published_payload( 99999, 'del_z' ), 'unknown post → null' );

// ─── Test 8: transition handler enqueues for enabled webhooks ─────────
echo "\nTest 8: sn_webhook_on_transition enqueue logic\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
$enabled = sn_webhook_create( array( 'name' => 'on',  'url' => 'https://on.example',  'enabled' => '1' ) );
$disabled = sn_webhook_create( array( 'name' => 'off', 'url' => 'https://off.example', 'enabled' => false ) );
$GLOBALS['__test_scheduled_events'] = array();
$post = (object) array( 'ID' => 200, 'post_type' => 'post', 'post_status' => 'publish' );
sn_webhook_on_transition( 'publish', 'draft', $post );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'one enqueue (enabled only — disabled skipped)' );
wh_eq( SN_WEBHOOK_DISPATCH_HOOK, $GLOBALS['__test_scheduled_events'][0]['hook'], 'dispatch hook scheduled' );
wh_eq( $enabled['id'], $GLOBALS['__test_scheduled_events'][0]['args'][0], 'enqueue carries the enabled webhook id' );
wh_eq( 200, $GLOBALS['__test_scheduled_events'][0]['args'][1], 'enqueue carries the post id' );
wh_eq( 1, $GLOBALS['__test_scheduled_events'][0]['args'][2], 'attempt starts at 1' );

// ─── Test 9: transition handler skips non-publish transitions ─────────
echo "\nTest 9: sn_webhook_on_transition guard\n";
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_on_transition( 'publish', 'publish', $post ); // already published
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'publish→publish (meta edit) does not enqueue' );
sn_webhook_on_transition( 'draft', 'publish', $post );   // unpublishing
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'publish→draft does not enqueue' );
sn_webhook_on_transition( 'trash', 'publish', $post );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'publish→trash does not enqueue' );

// ─── Test 10: transition handler respects allowed post types ──────────
echo "\nTest 10: sn_webhook_on_transition skips non-post/non-page types\n";
$GLOBALS['__test_scheduled_events'] = array();
$attachment = (object) array( 'ID' => 300, 'post_type' => 'attachment', 'post_status' => 'publish' );
sn_webhook_on_transition( 'publish', 'inherit', $attachment );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'attachment publish does NOT enqueue' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
