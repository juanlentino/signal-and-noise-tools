<?php
/**
 * Tests for inc/websub.php — WebSub (PubSubHubbub) publisher ping (D4).
 *
 * On publish/update/unpublish/delete of a post, the plugin notifies a WebSub hub
 * ("the feed changed, re-fetch it") so feed readers get push instead of polling.
 * The counterpart to IndexNow; mirrors its deferred-cron + lifecycle structure.
 * Standalone CLI fixture: stubs the WP option store, HTTP, scheduling, the feed
 * links, and the shared SSRF host-guard.
 *
 * @since plugin v6.17.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
define( 'SN_WEBSUB_TEST', true ); // suppress hook registration on require.

// ── In-memory option store ──────────────────────────────────────────
$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}

// ── Filters: honor test overrides for the two WebSub filter tags ────
function apply_filters( $tag, $value ) {
	if ( 'sn_websub_hub' === $tag ) {
		return array_key_exists( '__hub', $GLOBALS ) ? $GLOBALS['__hub'] : $value;
	}
	if ( 'sn_websub_enabled' === $tag ) {
		return array_key_exists( '__enabled', $GLOBALS ) ? $GLOBALS['__enabled'] : $value;
	}
	return $value;
}

// ── WP + feed stubs ─────────────────────────────────────────────────
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function get_feed_link( $feed = '' ) { return 'https://example.com/feed/' . ( 'atom' === $feed ? 'atom/' : '' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function add_action() {}
function add_filter() {}

// Shared SSRF host-guard — stubbed so the test stays offline (the real one
// resolves DNS). Honors a per-test blocklist.
function sn_ssrf_host_blocked( $host ) {
	return in_array( (string) $host, (array) ( $GLOBALS['__blocked_hosts'] ?? array() ), true );
}

$GLOBALS['__scheduled'] = array();
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$GLOBALS['__scheduled'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
	return true;
}

$GLOBALS['__remote_post']        = array();
$GLOBALS['__remote_post_return'] = null; // null → default 200 below
function wp_safe_remote_post( $url, $args = array() ) {
	$GLOBALS['__remote_post'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['__remote_post_return'] ?? array( 'response' => array( 'code' => 200 ) );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { private $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function wp_is_post_revision( $id ) { return ! empty( $GLOBALS['__is_revision'] ); }
function wp_is_post_autosave( $id ) { return ! empty( $GLOBALS['__is_autosave'] ); }
function sn_ws_post( $type, $status ) { $p = new stdClass(); $p->ID = 7; $p->post_type = $type; $p->post_status = $status; return $p; }

require __DIR__ . '/../inc/websub.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "WebSub publisher-ping suite — plugin v6.17.0\n\n";

// ── HUB + ENABLED ───────────────────────────────────────────────────
unset( $GLOBALS['__hub'], $GLOBALS['__enabled'] );
ok( sn_websub_hub() === 'https://pubsubhubbub.appspot.com/', 'hub: default is the public hub' );
$GLOBALS['__hub'] = 'https://hub.example.net/';
ok( sn_websub_hub() === 'https://hub.example.net/', 'hub: sn_websub_hub filter override honored' );
unset( $GLOBALS['__hub'] );

ok( sn_websub_is_enabled() === true, 'enabled: default true' );
$GLOBALS['__enabled'] = false;
ok( sn_websub_is_enabled() === false, 'enabled: sn_websub_enabled filter false honored' );
unset( $GLOBALS['__enabled'] );

// ── TOPICS + BODY (pure) ────────────────────────────────────────────
$topics = sn_websub_topics();
ok( in_array( 'https://example.com/feed/', $topics, true ) && in_array( 'https://example.com/feed/atom/', $topics, true ), 'topics: rss2 + atom feed URLs' );
ok( count( $topics ) === count( array_unique( $topics ) ), 'topics: de-duplicated' );

$body = sn_websub_build_body( array( 'https://example.com/feed/', 'https://example.com/feed/atom/' ) );
ok( strpos( $body, 'hub.mode=publish' ) === 0, 'body: starts with hub.mode=publish' );
ok( substr_count( $body, 'hub.url=' ) === 2, 'body: one hub.url param per topic' );
ok( strpos( $body, 'hub.url=' . rawurlencode( 'https://example.com/feed/' ) ) !== false, 'body: topic URL is url-encoded' );

// ── ENQUEUE ─────────────────────────────────────────────────────────
$GLOBALS['__scheduled'] = array();
sn_websub_enqueue();
ok( count( $GLOBALS['__scheduled'] ) === 1 && $GLOBALS['__scheduled'][0]['hook'] === SN_WEBSUB_CRON_HOOK, 'enqueue: schedules a single deferred ping' );

$GLOBALS['__scheduled'] = array();
$GLOBALS['__enabled'] = false;
sn_websub_enqueue();
ok( count( $GLOBALS['__scheduled'] ) === 0, 'enqueue: disabled → schedules nothing' );
unset( $GLOBALS['__enabled'] );

// ── PING (cron callback) ────────────────────────────────────────────
$GLOBALS['__remote_post'] = array();
unset( $GLOBALS['__options'][ SN_WEBSUB_RESULT_OPT ] );
sn_websub_ping();
ok( count( $GLOBALS['__remote_post'] ) === 1, 'ping: makes one POST to the hub' );
ok( $GLOBALS['__remote_post'][0]['url'] === 'https://pubsubhubbub.appspot.com/', 'ping: POSTs to the configured hub' );
ok( strpos( (string) $GLOBALS['__remote_post'][0]['args']['body'], 'hub.mode=publish' ) === 0, 'ping: body is the publish notification' );
ok( (int) ( $GLOBALS['__remote_post'][0]['args']['redirection'] ?? -1 ) === 0, 'ping: redirection=0 (no internal-redirect follow)' );
$res = get_option( SN_WEBSUB_RESULT_OPT );
ok( is_array( $res ) && (int) $res['code'] === 200, 'ping: records the HTTP result' );

// ping: SSRF-blocked hub → no POST, error recorded.
$GLOBALS['__remote_post'] = array();
$GLOBALS['__hub'] = 'https://metadata.internal/';
$GLOBALS['__blocked_hosts'] = array( 'metadata.internal' );
unset( $GLOBALS['__options'][ SN_WEBSUB_RESULT_OPT ] );
sn_websub_ping();
ok( count( $GLOBALS['__remote_post'] ) === 0, 'ping: SSRF-blocked hub → no POST (fail closed)' );
$res = get_option( SN_WEBSUB_RESULT_OPT );
ok( is_array( $res ) && '' !== (string) $res['error'], 'ping: blocked hub records an error' );
unset( $GLOBALS['__hub'], $GLOBALS['__blocked_hosts'] );

// ping: disabled → no POST.
$GLOBALS['__remote_post'] = array();
$GLOBALS['__enabled'] = false;
sn_websub_ping();
ok( count( $GLOBALS['__remote_post'] ) === 0, 'ping: disabled → no POST' );
unset( $GLOBALS['__enabled'] );

// ping: empty hub (filtered to '') → no POST.
$GLOBALS['__remote_post'] = array();
$GLOBALS['__hub'] = '';
sn_websub_ping();
ok( count( $GLOBALS['__remote_post'] ) === 0, 'ping: empty hub → no POST' );
unset( $GLOBALS['__hub'] );

// ── LIFECYCLE: insert (publish/update) ──────────────────────────────
$GLOBALS['__scheduled'] = array();
sn_websub_on_insert( 7, sn_ws_post( 'post', 'publish' ), true, null );
ok( count( $GLOBALS['__scheduled'] ) === 1, 'insert: published post → enqueue' );

$GLOBALS['__scheduled'] = array();
$GLOBALS['__is_revision'] = true;
sn_websub_on_insert( 7, sn_ws_post( 'post', 'publish' ), true, null );
ok( count( $GLOBALS['__scheduled'] ) === 0, 'insert: revision → skip' );
$GLOBALS['__is_revision'] = false;

$GLOBALS['__scheduled'] = array();
sn_websub_on_insert( 7, sn_ws_post( 'page', 'publish' ), true, null );
ok( count( $GLOBALS['__scheduled'] ) === 0, 'insert: non-post type (page) → skip (pages are not in the feed)' );

$GLOBALS['__scheduled'] = array();
sn_websub_on_insert( 7, sn_ws_post( 'post', 'draft' ), true, null );
ok( count( $GLOBALS['__scheduled'] ) === 0, 'insert: draft → skip' );

// ── LIFECYCLE: transition (unpublish) ───────────────────────────────
$GLOBALS['__scheduled'] = array();
sn_websub_on_transition( 'draft', 'publish', sn_ws_post( 'post', 'draft' ) );
ok( count( $GLOBALS['__scheduled'] ) === 1, 'transition: publish→draft → enqueue' );

$GLOBALS['__scheduled'] = array();
sn_websub_on_transition( 'publish', 'publish', sn_ws_post( 'post', 'publish' ) );
ok( count( $GLOBALS['__scheduled'] ) === 0, 'transition: publish→publish (plain edit) → skip (owned by insert)' );

$GLOBALS['__scheduled'] = array();
sn_websub_on_transition( 'publish', 'draft', sn_ws_post( 'post', 'publish' ) );
ok( count( $GLOBALS['__scheduled'] ) === 0, 'transition: draft→publish → skip (owned by insert)' );

// ── LIFECYCLE: delete ───────────────────────────────────────────────
$GLOBALS['__scheduled'] = array();
sn_websub_on_delete( 7, sn_ws_post( 'post', 'publish' ) );
ok( count( $GLOBALS['__scheduled'] ) === 1, 'delete: published post → enqueue' );

$GLOBALS['__scheduled'] = array();
sn_websub_on_delete( 7, sn_ws_post( 'post', 'draft' ) );
ok( count( $GLOBALS['__scheduled'] ) === 0, 'delete: non-published → skip' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
