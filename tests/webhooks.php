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
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
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
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $p ) {
		$post = is_object( $p ) ? $p : get_post( $p );
		return is_object( $post ) && 'revision' === $post->post_type ? (int) $post->ID : false;
	}
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $p ) { return false; }
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
// Fix C: an http:// URL is rejected — the error copy promises https:// and the
// signed payload must not travel over plaintext.
$res = sn_webhook_create( array( 'name' => 'x', 'url' => 'http://insecure.example/hook' ) );
wh_true( $res instanceof WP_Error, 'http:// URL rejected on create' );
wh_eq( 'sn_webhook_invalid_url', $res->code, 'http create → invalid_url code (https-only)' );

// ─── Test 4: update + secret rotation ────────────────────────────────
echo "\nTest 4: sn_webhook_update\n";
$old_secret = $created['secret'];
$updated = sn_webhook_update( $created['id'], array( 'name' => 'n8n v2' ) );
wh_eq( 'n8n v2', $updated['name'], 'name updated' );
wh_eq( $old_secret, $updated['secret'], 'secret unchanged without rotate flag' );
$updated = sn_webhook_update( $created['id'], array( 'rotate_secret' => '1' ) );
wh_true( $updated['secret'] !== $old_secret, 'secret changes with rotate_secret=1' );
wh_eq( 48, strlen( $updated['secret'] ), 'rotated secret is still 48 chars' );
// Fix C: an http:// update is ignored — the existing https URL is preserved.
$prev_url = $updated['url'];
$updated  = sn_webhook_update( $created['id'], array( 'url' => 'http://insecure.example/hook' ) );
wh_eq( $prev_url, $updated['url'], 'http:// update ignored — https URL preserved (https-only contract)' );
// And a valid https update DOES apply.
$updated = sn_webhook_update( $created['id'], array( 'url' => 'https://new.example/hook' ) );
wh_eq( 'https://new.example/hook', $updated['url'], 'https:// update applied' );

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
// v4.10.0: widened cron-arg order is [ webhook_id, event, post_id, snapshot, attempt, delivery_id ].
echo "\nTest 8: sn_webhook_on_transition enqueue logic\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
$enabled = sn_webhook_create( array( 'name' => 'on',  'url' => 'https://on.example',  'enabled' => '1' ) );
$disabled = sn_webhook_create( array( 'name' => 'off', 'url' => 'https://off.example', 'enabled' => false ) );
$GLOBALS['__test_scheduled_events'] = array();
$post = (object) array( 'ID' => 200, 'post_type' => 'post', 'post_status' => 'publish' );
$GLOBALS['__test_posts'][200] = (object) array(
	'ID'            => 200,
	'post_status'   => 'publish',
	'post_title'    => 'Pub 200',
	'post_name'     => 'pub-200',
	'post_author'   => 1,
	'post_date_gmt' => '2026-05-20 12:00:00',
	'post_type'     => 'post',
);
sn_webhook_on_transition( 'publish', 'draft', $post );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'one enqueue (enabled only — disabled skipped)' );
wh_eq( SN_WEBHOOK_DISPATCH_HOOK, $GLOBALS['__test_scheduled_events'][0]['hook'], 'dispatch hook scheduled' );
wh_eq( $enabled['id'], $GLOBALS['__test_scheduled_events'][0]['args'][0], 'enqueue carries the enabled webhook id (arg 0)' );
wh_eq( 'post.published', $GLOBALS['__test_scheduled_events'][0]['args'][1], 'enqueue carries the event (arg 1)' );
wh_eq( 200, $GLOBALS['__test_scheduled_events'][0]['args'][2], 'enqueue carries the post id (arg 2)' );
wh_true( is_array( $GLOBALS['__test_scheduled_events'][0]['args'][3] ), 'snapshot arg is an array (arg 3)' );
wh_eq( 1, $GLOBALS['__test_scheduled_events'][0]['args'][4], 'attempt starts at 1 (arg 4)' );

// ─── Test 9: transition handler skips non-publish transitions ─────────
echo "\nTest 9: sn_webhook_on_transition guard\n";
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_on_transition( 'publish', 'publish', $post ); // re-save while published → post.updated (publish-only default = 0)
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'publish→publish does not enqueue for publish-only webhooks' );
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_on_transition( 'draft', 'publish', $post );   // unpublishing → post.unpublished (publish-only = 0)
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'publish→draft does not enqueue for publish-only webhooks' );
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_on_transition( 'trash', 'publish', $post );   // trashed → post.deleted (publish-only = 0)
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'publish→trash does not enqueue for publish-only webhooks' );

// ─── Test 10: transition handler respects allowed post types ──────────
echo "\nTest 10: sn_webhook_on_transition skips non-post/non-page types\n";
$GLOBALS['__test_scheduled_events'] = array();
$attachment = (object) array( 'ID' => 300, 'post_type' => 'attachment', 'post_status' => 'publish' );
sn_webhook_on_transition( 'publish', 'inherit', $attachment );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'attachment publish does NOT enqueue' );

// ─── Test 11: event registry (v4.10.0) ───────────────────────────────
echo "\nTest 11: sn_webhook_events registry\n";
$events = sn_webhook_events();
wh_eq( 4, count( $events ), 'registry has 4 events' );
wh_eq(
	array( 'post.published', 'post.updated', 'post.unpublished', 'post.deleted' ),
	array_keys( $events ),
	'registry keys are in canonical order'
);
wh_true( is_string( $events['post.published'] ) && '' !== $events['post.published'], 'each event has a label' );

// ─── Test 12: sn_webhook_event_enabled ───────────────────────────────
echo "\nTest 12: sn_webhook_event_enabled back-compat + explicit\n";
// Back-compat: unset events → only post.published is enabled.
$legacy = array( 'id' => 'wh_legacy', 'enabled' => true );
wh_eq( true,  sn_webhook_event_enabled( $legacy, 'post.published' ), 'legacy (no events key) → post.published enabled' );
wh_eq( false, sn_webhook_event_enabled( $legacy, 'post.updated' ),   'legacy (no events key) → post.updated NOT enabled' );
// Explicit opt-in list.
$multi = array( 'id' => 'wh_multi', 'enabled' => true, 'events' => array( 'post.updated', 'post.deleted' ) );
wh_eq( true,  sn_webhook_event_enabled( $multi, 'post.updated' ),   'explicit list → post.updated enabled' );
wh_eq( true,  sn_webhook_event_enabled( $multi, 'post.deleted' ),   'explicit list → post.deleted enabled' );
wh_eq( false, sn_webhook_event_enabled( $multi, 'post.published' ), 'explicit list without published → NOT enabled' );
wh_eq( false, sn_webhook_event_enabled( $multi, 'bogus.event' ),    'unlisted event rejected' );

// ─── Test 13: events sanitizer in create/update (v4.10.0) ─────────────
echo "\nTest 13: events[] sanitizer\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
$ev1 = sn_webhook_create( array( 'name' => 'ev1', 'url' => 'https://ev1.example', 'events' => array( 'post.updated', 'bogus' ) ) );
wh_eq( array( 'post.updated' ), $ev1['events'], 'create drops bogus event, keeps post.updated' );
$ev2 = sn_webhook_create( array( 'name' => 'ev2', 'url' => 'https://ev2.example' ) );
wh_eq( array( 'post.published' ), $ev2['events'], 'create with no events defaults to [post.published]' );
$ev3 = sn_webhook_create( array( 'name' => 'ev3', 'url' => 'https://ev3.example', 'events' => array( 'nope', 'still-nope' ) ) );
wh_eq( array( 'post.published' ), $ev3['events'], 'create with only-invalid events defaults to [post.published]' );
$ev1u = sn_webhook_update( $ev1['id'], array( 'events' => array( 'post.deleted', 'post.published', 'xyz' ) ) );
wh_eq( array( 'post.published', 'post.deleted' ), $ev1u['events'], 'update sanitizes + preserves canonical order' );
$ev1e = sn_webhook_update( $ev1['id'], array( 'events' => array() ) );
wh_eq( array( 'post.published' ), $ev1e['events'], 'update with empty events defaults to [post.published]' );

// ─── Test 14: generalized payload builder (v4.10.0) ───────────────────
echo "\nTest 14: sn_webhook_build_payload\n";
// publish/updated resolve via get_post() (require publish).
$body_pub = sn_webhook_build_payload( 'post.published', 100, 'del_p', array() );
wh_true( is_string( $body_pub ), 'post.published builds a body from get_post()' );
$d = json_decode( $body_pub, true );
wh_eq( 'post.published', $d['event'], 'post.published event field' );
$body_upd = sn_webhook_build_payload( 'post.updated', 100, 'del_u', array() );
$d = json_decode( $body_upd, true );
wh_eq( 'post.updated', $d['event'], 'post.updated event field' );
wh_eq( 100, $d['post']['id'], 'post.updated resolves the live post' );
// updated/published on a non-publish post → null (must still be published).
wh_eq( null, sn_webhook_build_payload( 'post.updated', 101, 'del_x', array() ), 'post.updated on a draft → null' );
// Snapshot path: post may be gone at dispatch — build from $snapshot, NOT get_post().
$snap = array( 'id' => 555, 'title' => 'Gone Post', 'slug' => 'gone-post', 'url' => 'https://juanlentino.com/gone-post', 'type' => 'post', 'author_id' => 7 );
$body_del = sn_webhook_build_payload( 'post.deleted', 555, 'del_d', $snap );
wh_true( is_string( $body_del ), 'post.deleted builds a body from the snapshot' );
$d = json_decode( $body_del, true );
wh_eq( 'post.deleted', $d['event'], 'post.deleted event field' );
wh_eq( 555, $d['post']['id'], 'snapshot id used' );
wh_eq( 'Gone Post', $d['post']['title'], 'snapshot title used' );
wh_eq( 'https://juanlentino.com/gone-post', $d['post']['url'], 'snapshot url used' );
wh_eq( 'gone-post', $d['post']['slug'], 'snapshot slug used' );
$body_unp = sn_webhook_build_payload( 'post.unpublished', 555, 'del_un', $snap );
$d = json_decode( $body_unp, true );
wh_eq( 'post.unpublished', $d['event'], 'post.unpublished event field' );
wh_eq( 'Gone Post', $d['post']['title'], 'unpublished snapshot title used' );
// Legacy shim still delegates.
$shim = sn_webhook_build_post_published_payload( 100, 'del_shim' );
$d = json_decode( $shim, true );
wh_eq( 'post.published', $d['event'], 'legacy shim still builds post.published' );

// ─── Test 15: fan-out by subscribed events (v4.10.0) ──────────────────
echo "\nTest 15: fan-out — events list gates the enqueue\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
// A webhook subscribed ONLY to post.updated.
$upd_only = sn_webhook_create( array( 'name' => 'upd', 'url' => 'https://upd.example', 'enabled' => '1', 'events' => array( 'post.updated' ) ) );
$GLOBALS['__test_posts'][201] = (object) array(
	'ID' => 201, 'post_status' => 'publish', 'post_title' => 'P201', 'post_name' => 'p201',
	'post_author' => 1, 'post_date_gmt' => '2026-05-20 12:00:00', 'post_type' => 'post',
);
$p201 = (object) array( 'ID' => 201, 'post_type' => 'post', 'post_status' => 'publish' );
// First publish (draft→publish): post.updated subscriber → 0 enqueues.
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_on_transition( 'publish', 'draft', $p201 );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'post.updated subscriber: first publish → 0 enqueues' );
// Re-save while published (publish→publish): post.updated subscriber → 1 enqueue, event=post.updated.
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_on_transition( 'publish', 'publish', $p201 );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'post.updated subscriber: publish→publish → 1 enqueue' );
wh_eq( 'post.updated', $GLOBALS['__test_scheduled_events'][0]['args'][1], 'enqueued event is post.updated' );

// A default (publish-only, unset events) webhook.
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
$pub_default = sn_webhook_create( array( 'name' => 'pub', 'url' => 'https://pub.example', 'enabled' => '1' ) );
// Strip events to simulate a pre-v4.10.0 stored webhook (no events key at all).
$all = sn_webhooks_all();
unset( $all[0]['events'] );
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = $all;
// First publish: default → 1 enqueue, event=post.published.
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_on_transition( 'publish', 'draft', $p201 );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'publish-only default: first publish → 1 enqueue' );
wh_eq( 'post.published', $GLOBALS['__test_scheduled_events'][0]['args'][1], 'enqueued event is post.published' );
// Re-save while published: default → 0 enqueues (not subscribed to post.updated).
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_on_transition( 'publish', 'publish', $p201 );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'publish-only default: publish→publish → 0 enqueues' );

// ─── Test 16: unpublish + trash branches (snapshot) ───────────────────
echo "\nTest 16: post.unpublished / post.deleted triggers + snapshot\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
$all_events = sn_webhook_create( array( 'name' => 'all', 'url' => 'https://all.example', 'enabled' => '1', 'events' => array_keys( sn_webhook_events() ) ) );
// publish→draft → post.unpublished, with a real snapshot.
$GLOBALS['__test_scheduled_events'] = array();
$p200 = (object) array( 'ID' => 200, 'post_type' => 'post', 'post_status' => 'draft' );
sn_webhook_on_transition( 'draft', 'publish', $p200 );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'publish→draft → 1 enqueue for an all-events webhook' );
wh_eq( 'post.unpublished', $GLOBALS['__test_scheduled_events'][0]['args'][1], 'event is post.unpublished' );
$snap_arg = $GLOBALS['__test_scheduled_events'][0]['args'][3];
wh_eq( 'Pub 200', $snap_arg['title'], 'unpublish snapshot captured title at trigger time' );
wh_true( false !== strpos( (string) $snap_arg['url'], '200' ), 'unpublish snapshot captured url' );
// publish→trash → post.deleted ONLY (not also unpublished — no double-fire).
$GLOBALS['__test_scheduled_events'] = array();
$p200t = (object) array( 'ID' => 200, 'post_type' => 'post', 'post_status' => 'trash' );
sn_webhook_on_transition( 'trash', 'publish', $p200t );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'publish→trash → exactly 1 enqueue (no double-fire)' );
wh_eq( 'post.deleted', $GLOBALS['__test_scheduled_events'][0]['args'][1], 'trash event is post.deleted (not unpublished)' );

// ─── Test 17: before_delete_post → post.deleted (publish rows only) ────
echo "\nTest 17: sn_webhook_on_delete (permanent delete)\n";
// Direct force-delete of a still-PUBLISHED post (never trashed) → 1 post.deleted.
$GLOBALS['__test_scheduled_events'] = array();
$del_pub = (object) array(
	'ID' => 200, 'post_status' => 'publish', 'post_title' => 'Pub 200', 'post_name' => 'pub-200',
	'post_author' => 1, 'post_date_gmt' => '2026-05-20 12:00:00', 'post_type' => 'post',
);
sn_webhook_on_delete( 200, $del_pub );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'force-delete of a published post → 1 enqueue' );
wh_eq( 'post.deleted', $GLOBALS['__test_scheduled_events'][0]['args'][1], 'before_delete event is post.deleted' );
wh_eq( 'Pub 200', $GLOBALS['__test_scheduled_events'][0]['args'][3]['title'], 'delete snapshot has the title' );
// Emptying the trash hits an already-'trash' row — but the publish→trash transition
// already fired post.deleted, so on_delete must NOT double-fire (T1-001).
$GLOBALS['__test_scheduled_events'] = array();
$del_trash = (object) array(
	'ID' => 200, 'post_status' => 'trash', 'post_title' => 'Pub 200', 'post_name' => 'pub-200',
	'post_author' => 1, 'post_date_gmt' => '2026-05-20 12:00:00', 'post_type' => 'post',
);
sn_webhook_on_delete( 200, $del_trash );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'empty-trash of an already-trash row → 0 (no double-fire)' );
// Never-published content permanently deleted → 0 (no public subscriber saw it) (T1-002).
$GLOBALS['__test_scheduled_events'] = array();
$del_draft = (object) array(
	'ID' => 203, 'post_status' => 'draft', 'post_title' => 'Draft 203', 'post_name' => 'draft-203',
	'post_author' => 1, 'post_date_gmt' => '2026-05-20 12:00:00', 'post_type' => 'post',
);
sn_webhook_on_delete( 203, $del_draft );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'force-delete of a never-published draft → 0 enqueues' );
$GLOBALS['__test_scheduled_events'] = array();
$del_auto = (object) array(
	'ID' => 204, 'post_status' => 'auto-draft', 'post_title' => '', 'post_name' => '',
	'post_author' => 1, 'post_date_gmt' => '2026-05-20 12:00:00', 'post_type' => 'post',
);
sn_webhook_on_delete( 204, $del_auto );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'auto-draft GC delete → 0 enqueues' );
// Revisions and disallowed types are skipped (gates before the status check).
$GLOBALS['__test_scheduled_events'] = array();
$rev = (object) array( 'ID' => 201, 'post_type' => 'revision', 'post_status' => 'inherit' );
sn_webhook_on_delete( 201, $rev );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'revision delete → 0 enqueues' );
$GLOBALS['__test_scheduled_events'] = array();
$att = (object) array( 'ID' => 202, 'post_type' => 'attachment', 'post_status' => 'inherit' );
sn_webhook_on_delete( 202, $att );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'attachment delete → 0 enqueues (not in post_types gate)' );
// Full lifecycle: publish → trash → empty-trash fires post.deleted EXACTLY ONCE.
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
sn_webhook_create( array( 'name' => 'lc', 'url' => 'https://lc.example', 'enabled' => '1', 'events' => array_keys( sn_webhook_events() ) ) );
$GLOBALS['__test_scheduled_events'] = array();
$lc_trash = (object) array(
	'ID' => 205, 'post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'LC 205',
	'post_name' => 'lc-205', 'post_author' => 1, 'post_date_gmt' => '2026-05-20 12:00:00',
);
sn_webhook_on_transition( 'trash', 'publish', $lc_trash );           // publish→trash → post.deleted #1
$lc_purge = (object) array(
	'ID' => 205, 'post_status' => 'trash', 'post_title' => 'LC 205', 'post_name' => 'lc-205',
	'post_author' => 1, 'post_date_gmt' => '2026-05-20 12:00:00', 'post_type' => 'post',
);
sn_webhook_on_delete( 205, $lc_purge );                              // empty-trash → suppressed
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'lifecycle publish→trash→purge → exactly 1 post.deleted' );

// ─── Test 18: widened enqueue arg order (v4.10.0) ─────────────────────
echo "\nTest 18: sn_webhook_enqueue arg order\n";
$GLOBALS['__test_scheduled_events'] = array();
sn_webhook_enqueue( 'wh_x', 'post.deleted', 42, array( 'id' => 42, 'title' => 'T' ), 2, 'del_fixed' );
$args = $GLOBALS['__test_scheduled_events'][0]['args'];
wh_eq( 'wh_x', $args[0], 'arg0 = webhook_id' );
wh_eq( 'post.deleted', $args[1], 'arg1 = event' );
wh_eq( 42, $args[2], 'arg2 = post_id' );
wh_eq( array( 'id' => 42, 'title' => 'T' ), $args[3], 'arg3 = snapshot' );
wh_eq( 2, $args[4], 'arg4 = attempt' );
wh_eq( 'del_fixed', $args[5], 'arg5 = delivery_id' );

// ─── Test 19: in-flight back-compat — 4-arg dispatch still works ──────
// An OLD cron event scheduled before this deploy carries [webhook_id, post_id,
// attempt, delivery_id] and calls sn_webhook_dispatch() with 4 positional args.
// The widened signature MUST default $event/$snapshot so it does NOT fatal.
echo "\nTest 19: in-flight OLD 4-arg dispatch back-compat\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
$bc = sn_webhook_create( array( 'name' => 'bc', 'url' => 'https://bc.example', 'enabled' => '1' ) );
$GLOBALS['__test_posts'][100]->post_status = 'publish'; // ensure resolvable
// Define a wp_remote_post stub so dispatch records without real I/O.
$GLOBALS['__wh_last_post'] = null;
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__wh_last_post'] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => 'OK' );
	}
	function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
}
$GLOBALS['__test_options'][ SN_WEBHOOKS_LOG_PREFIX . $bc['id'] ] = array();
// Call with EXACTLY 4 args in the OLD order [webhook_id, post_id, attempt, delivery_id].
// The widened sig's 2nd param is now $event; an old event passes a numeric post_id
// there. Dispatch must detect the legacy shape, remap, and still deliver the post.
$GLOBALS['__wh_last_post'] = null;
$before = count( sn_webhook_log_read( $bc['id'] ) );
sn_webhook_dispatch( $bc['id'], 100, 1, 'del_oldcron' );
$after = count( sn_webhook_log_read( $bc['id'] ) );
wh_eq( $before + 1, $after, '4-arg dispatch records a delivery (no fatal)' );
wh_true( is_array( $GLOBALS['__wh_last_post'] ), '4-arg dispatch actually POSTed (legacy shape remapped, not dropped)' );
$bc_body = json_decode( (string) $GLOBALS['__wh_last_post']['args']['body'], true );
wh_eq( 100, $bc_body['post']['id'], 'legacy 4-arg dispatch resolved the right post id (100)' );
wh_eq( 'post.published', $bc_body['event'], 'legacy 4-arg dispatch defaults to post.published' );
wh_eq( 'del_oldcron', $bc_body['delivery_id'], 'legacy delivery_id (4th arg) preserved' );

// ─── Test 20: auto-draft permanent delete fires NOTHING ───────────────
// WordPress core's wp_delete_auto_drafts() cron force-deletes every
// post_status='auto-draft' row older than 7 days via wp_delete_post($id, true),
// firing before_delete_post → sn_webhook_on_delete daily. 'auto-draft' is a post
// STATUS, not a post_type: these rows are post_type='post'/'page', so the
// post-type gate does NOT exclude them. Without an explicit status guard they
// spray a spurious post.deleted for posts that never had a public existence.
echo "\nTest 20: sn_webhook_on_delete skips auto-draft status\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
sn_webhook_create( array( 'name' => 'all20', 'url' => 'https://all20.example', 'enabled' => '1', 'events' => array_keys( sn_webhook_events() ) ) );
$GLOBALS['__test_scheduled_events'] = array();
$auto_draft = (object) array(
	'ID' => 400, 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft',
	'post_name' => '', 'post_author' => 1, 'post_date_gmt' => '2026-05-01 00:00:00', 'post_type' => 'post',
);
sn_webhook_on_delete( 400, $auto_draft );
wh_eq( 0, count( $GLOBALS['__test_scheduled_events'] ), 'auto-draft permanent delete → 0 enqueues (never-published; must not fire post.deleted)' );
// Guard against over-suppression: a real published post still fires post.deleted.
$GLOBALS['__test_scheduled_events'] = array();
$real_del = (object) array(
	'ID' => 401, 'post_status' => 'publish', 'post_title' => 'Real', 'post_name' => 'real',
	'post_author' => 1, 'post_date_gmt' => '2026-05-01 00:00:00', 'post_type' => 'post',
);
sn_webhook_on_delete( 401, $real_del );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'published-post permanent delete → 1 enqueue (fix does not over-suppress real posts)' );

// ─── Test 21: trash → purge fires post.deleted EXACTLY ONCE (contract) ────
// A published post that is trashed and later purged hits two hooks, but is ONE
// logical deletion: the publish→trash transition fires post.deleted, and the
// permanent before_delete_post then sees an already-'trash' row and stays silent
// (the two deliveries would carry different delivery_ids, so receivers could not
// dedupe). This pins the single-fire contract so the double-fire can't regress.
echo "\nTest 21: trash → purge fires post.deleted exactly once (contract)\n";
$GLOBALS['__test_options'][ SN_WEBHOOKS_OPTION ] = array();
sn_webhook_create( array( 'name' => 'all21', 'url' => 'https://all21.example', 'enabled' => '1', 'events' => array_keys( sn_webhook_events() ) ) );
$GLOBALS['__test_scheduled_events'] = array();
// Step 1 — publish → trash (soft delete) fires post.deleted once.
$p_trash = (object) array(
	'ID' => 500, 'post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'Gone',
	'post_name' => 'gone', 'post_author' => 1, 'post_date_gmt' => '2026-05-01 00:00:00',
);
sn_webhook_on_transition( 'trash', 'publish', $p_trash );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'step 1: publish→trash fires post.deleted once' );
wh_eq( 'post.deleted', $GLOBALS['__test_scheduled_events'][0]['args'][1], 'step 1 event is post.deleted' );
// Step 2 — purge the already-trashed row (permanent delete) must NOT re-fire.
sn_webhook_on_delete( 500, $p_trash );
wh_eq( 1, count( $GLOBALS['__test_scheduled_events'] ), 'step 2: purging the already-trashed row does not re-fire (still 1)' );
wh_eq( 500, $GLOBALS['__test_scheduled_events'][0]['args'][2], 'the single post.deleted carries the post id' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
