<?php
/**
 * Standalone test: what the per-post Cloudflare purge clears, and when.
 *
 * Measured live 2026-08-05: the edge-cached copy of a Note was 104,516 bytes
 * against 115,431 from origin, and the cached copy's embedded notes index was
 * missing an entry for a Note published later. Mechanism: every rendered page
 * carries the site-wide notes index (the `{"t":…,"u":…}` list feeding search and
 * related-notes), so a NEW publication invalidates every page — while the purge
 * list named only the saved post, the home page, /notes/, /provenance/ and the
 * feed. The freshness probe could not see it either: it checks three routes and
 * never an individual Note.
 *
 * So a first publication purges the zone; an ordinary edit keeps the narrow
 * list, because an edit changes that Note's page and its listings, not the index
 * every other page carries.
 *
 * The distinction under test is a transition INTO publish — not `$update`,
 * which is true for a draft that already existed as a row, i.e. exactly the case
 * that adds a new index entry.
 *
 * @since plugin v10.52.6
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs ─────────────────────────────────────────────────────────
// The publish handler is registered as a CLOSURE on wp_after_insert_post, so
// the harness captures it at load and invokes it directly.
$GLOBALS['__hooked'] = array();
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {
	$GLOBALS['__hooked'][ $hook ][] = $cb;
	return true;
}
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['__filters'][ $tag ][] = $cb;
	return true;
}
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );
	foreach ( $GLOBALS['__filters'][ $tag ] ?? array() as $cb ) {
		$value   = call_user_func_array( $cb, $args );
		$args[0] = $value;
	}
	return $value;
}
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_type = 'post';
		public $post_status = 'publish';
		public $post_parent = 0;
		public function __construct( $fields = array() ) {
			foreach ( $fields as $k => $v ) { $this->$k = $v; }
		}
	}
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_is_post_revision( $id ) { return ! empty( $GLOBALS['__is_revision'] ); }
function wp_is_post_autosave( $id ) { return ! empty( $GLOBALS['__is_autosave'] ); }
function get_permalink( $id ) { return 'https://example.test/notes/post-' . (int) $id . '/'; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }

$GLOBALS['__http'] = array();
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = array( 'url' => $url, 'args' => $args );
	return array( 'body' => json_encode( array( 'success' => true, 'errors' => array() ) ), 'response' => array( 'code' => 200 ) );
}
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }

$GLOBALS['__opts'] = array();
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; }
}

require_once __DIR__ . '/../inc/cloudflare-purge.php';

// Credentials, so sn_cf_is_configured() is true and requests actually fire.
// Via the OPTIONS the module reads, not invented constant names — the real
// constants are SN_CLOUDFLARE_API_TOKEN / SN_CLOUDFLARE_ZONE_ID and defining
// look-alikes would leave the module unconfigured and every scenario silently
// green-by-no-op.
$GLOBALS['__opts'][ SN_CF_TOKEN_OPT ] = 'TESTTOKEN';
$GLOBALS['__opts'][ SN_CF_ZONE_OPT ]  = 'TESTZONE';
ok( sn_cf_is_configured(), 'the harness is configured — scenarios below exercise real dispatch' );

$handler = null;
foreach ( $GLOBALS['__hooked']['wp_after_insert_post'] ?? array() as $cb ) {
	$handler = $cb;
}
echo "Group: handler registration\n";
ok( is_callable( $handler ), 'a wp_after_insert_post handler is registered' );

/**
 * Fire the publish handler and return what was sent.
 *
 * @param string     $status       Status of the post being saved.
 * @param mixed      $before_status Status before the save, or null for a brand-new post.
 * @param string     $type         Post type.
 * @return array{everything:bool,urls:array,calls:int}
 */
function fire( $status = 'publish', $before_status = null, $type = 'post' ) {
	global $handler;
	$GLOBALS['__http'] = array();
	$post   = new WP_Post( array( 'ID' => 7, 'post_type' => $type, 'post_status' => $status ) );
	$before = ( null === $before_status ) ? null : new WP_Post( array( 'ID' => 7, 'post_status' => $before_status ) );
	call_user_func( $handler, 7, $post, null !== $before_status, $before );

	$everything = false;
	$urls       = array();
	foreach ( $GLOBALS['__http'] as $call ) {
		$body = json_decode( (string) ( $call['args']['body'] ?? '{}' ), true );
		if ( ! empty( $body['purge_everything'] ) ) { $everything = true; }
		if ( ! empty( $body['files'] ) ) { $urls = array_merge( $urls, (array) $body['files'] ); }
	}
	return array( 'everything' => $everything, 'urls' => $urls, 'calls' => count( $GLOBALS['__http'] ) );
}

// ─── A first publication clears the zone ──────────────────────────────
echo "\nGroup: a NEW publication invalidates every page's embedded index\n";
$new = fire( 'publish', null );
ok( $new['everything'], 'a brand-new published post purges the whole zone' );
ok( empty( $new['urls'] ), 'and does not also send the narrow URL list' );

// $update is TRUE here — the draft row already existed — which is exactly why
// the test is a transition into publish rather than a check on $update.
$from_draft = fire( 'publish', 'draft' );
ok( $from_draft['everything'], 'draft → publish purges the whole zone despite $update being true' );

$from_pending = fire( 'publish', 'pending' );
ok( $from_pending['everything'], 'pending → publish purges the whole zone' );

$from_future = fire( 'publish', 'future' );
ok( $from_future['everything'], 'a scheduled post going live purges the whole zone' );

// ─── An ordinary edit keeps the narrow list ───────────────────────────
echo "\nGroup: an ordinary edit stays narrow\n";
$edit = fire( 'publish', 'publish' );
ok( ! $edit['everything'], 'publish → publish does NOT purge the zone' );
ok( in_array( 'https://example.test/notes/post-7/', $edit['urls'], true ), 'it purges the post permalink' );
ok( in_array( 'https://example.test/', $edit['urls'], true ), 'and the home page' );
ok( in_array( 'https://example.test/notes/', $edit['urls'], true ), 'and /notes/' );
ok( in_array( 'https://example.test/notes/feed/', $edit['urls'], true ), 'and the feed' );

// ─── The gates that must still hold ───────────────────────────────────
echo "\nGroup: the existing gates are untouched\n";
$draft = fire( 'draft', 'draft' );
ok( 0 === $draft['calls'], 'saving a draft fires nothing' );

$unpublish = fire( 'draft', 'publish' );
ok( 0 === $unpublish['calls'], 'unpublishing fires nothing (the status gate runs first)' );

$GLOBALS['__is_revision'] = true;
$rev = fire( 'publish', null );
ok( 0 === $rev['calls'], 'a revision fires nothing' );
$GLOBALS['__is_revision'] = false;

$GLOBALS['__is_autosave'] = true;
$auto = fire( 'publish', null );
ok( 0 === $auto['calls'], 'an autosave fires nothing' );
$GLOBALS['__is_autosave'] = false;

$attachment = fire( 'publish', null, 'attachment' );
ok( 0 === $attachment['calls'], 'a non-post/page type fires nothing' );

// ─── The escape hatch ─────────────────────────────────────────────────
// A full-zone purge is the right default at roughly one publication a week,
// but it is the kind of decision a site with different cadence should be able
// to decline without editing the file.
echo "\nGroup: the full purge is filterable\n";
add_filter( 'sn_cf_purge_all_on_first_publish', static function () { return false; } );
$opted_out = fire( 'publish', null );
ok( ! $opted_out['everything'], 'returning false from the filter skips the zone purge' );
ok( in_array( 'https://example.test/notes/post-7/', $opted_out['urls'], true ), 'and falls back to the narrow URL list' );
$GLOBALS['__filters'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
