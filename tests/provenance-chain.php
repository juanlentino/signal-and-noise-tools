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
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ) {
		return is_object( $post ) ? $post->post_title : ''; }
}
$GLOBALS['__pv_actions'] = array();
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		$GLOBALS['__pv_actions'][] = array( $tag, $args );
		return null;
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

echo "\nTask 5: payload + hash + published_at\n";
$post              = new stdClass();
$post->ID          = 501;
$post->post_title  = 'On over-detection';
$post->post_content = '<p>Hello world.</p>';
$post->post_date    = '2026-06-12 09:30:00';
$post->post_date_gmt = '2026-06-12 09:30:00';
$post->post_author  = 1;

pv_eq( '2026-06-12T09:30:00Z', sn_prov_published_at( $post ), 'published_at is ISO-8601 UTC' );

$payload = sn_prov_build_payload( $post, 1, null, 'Juan Lentino' );
pv_eq( 'sn-normalize-v1', $payload['algo'], 'payload.algo' );
pv_eq( 'Hello world.', $payload['content'], 'payload.content normalized' );
pv_eq( 1, $payload['version'], 'payload.version' );
pv_eq( null, $payload['parent'], 'payload.parent null for genesis-less first commit' );

$hash = sn_prov_content_hash( sn_prov_canonical_json( $payload ) );
pv_true( 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ), 'content hash is 64-hex sha256' );
// Deterministic: same payload -> same hash.
pv_eq( $hash, sn_prov_content_hash( sn_prov_canonical_json( sn_prov_build_payload( $post, 1, null, 'Juan Lentino' ) ) ), 'hash is deterministic' );

echo "\nTask 6: chain CRUD + bearing hash\n";
pv_eq( array(), sn_prov_get_chain( 777 ), 'empty chain for unknown post' );
pv_eq( null, sn_prov_latest_hash( 777 ), 'latest hash null when empty' );

sn_prov_append_commit( 777, array( 'version' => 1, 'content_hash' => 'aa', 'bearing_hash' => 'b1' ) );
sn_prov_append_commit( 777, array( 'version' => 2, 'content_hash' => 'bb', 'bearing_hash' => 'b2' ) );
pv_eq( 2, count( sn_prov_get_chain( 777 ) ), 'append grows the chain' );
pv_eq( 'bb', sn_prov_latest_hash( 777 ), 'latest hash is the last commit content_hash' );

// bearing hash excludes version + parent (so an unchanged edit coalesces).
$b1 = sn_prov_bearing_hash( $post, 'Juan Lentino' );   // $post from Task 5
$b2 = sn_prov_bearing_hash( $post, 'Juan Lentino' );
pv_eq( $b1, $b2, 'bearing hash stable for identical content' );
$post->post_title = 'On over-detection (revised)';
pv_true( $b1 !== sn_prov_bearing_hash( $post, 'Juan Lentino' ), 'bearing hash changes when title changes' );
$post->post_title = 'On over-detection'; // restore

echo "\nTask 7: sn_prov_record (coalescing + seam)\n";
$rp               = new stdClass();
$rp->ID           = 900;
$rp->post_title   = 'A note';
$rp->post_content = '<p>First body.</p>';
$rp->post_date    = '2026-01-01 00:00:00';
$rp->post_date_gmt = '2026-01-01 00:00:00';
$rp->post_author  = 1;

$GLOBALS['__pv_actions'] = array();
$c1 = sn_prov_record( $rp, 'Juan Lentino' );
pv_eq( 1, count( sn_prov_get_chain( 900 ) ), 'first publish records a commit' );
pv_eq( 1, $c1[0]['version'], 'first commit is version 1' );
pv_eq( null, $c1[0]['parent'], 'first commit parent is null' );
pv_eq( 'unanchored', $c1[0]['status'], 'new commit starts unanchored' );
pv_true( isset( $c1[0]['payload'] ), 'commit stores its full payload' );
pv_eq( 'sn_prov_committed', $GLOBALS['__pv_actions'][0][0] ?? '', 'commit fires sn_prov_committed' );

// Re-save with only formatting/whitespace change -> coalesced (no new commit).
$GLOBALS['__pv_actions'] = array();
$rp->post_content = "<p>First    body.</p>\r\n"; // same words after normalization
$c2 = sn_prov_record( $rp, 'Juan Lentino' );
pv_eq( null, $c2, 'trivial diff coalesces (returns null)' );
pv_eq( 1, count( sn_prov_get_chain( 900 ) ), 'no new commit for trivial diff' );
pv_eq( 0, count( $GLOBALS['__pv_actions'] ), 'coalesced save fires no seam' );

// Real content change -> version 2 chained to version 1.
$rp->post_content = '<p>Second body, materially different.</p>';
$c3 = sn_prov_record( $rp, 'Juan Lentino' );
pv_eq( 2, count( $c3 ), 'material change records version 2' );
pv_eq( 2, $c3[1]['version'], 'second commit is version 2' );
pv_eq( $c3[0]['content_hash'], $c3[1]['parent'], 'version 2 parent = version 1 content_hash' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
