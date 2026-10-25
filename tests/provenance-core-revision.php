<?php
/**
 * Standalone fixture tests for the core revision id beside each provenance
 * commit (inc/provenance-core-revision.php): stored next to the signed
 * payload, never in it, so no hash, signature or Worker body moves.
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
// Core's slash contract: update_post_meta() unslashes, so writers hand it slashed data.
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $v ) {
		return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) {
		return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['__pv_meta'][ $id ][ $key ] = wp_unslash( $value );
		return true;
	}
}
// v11.11.8: sn_prov_append_commit()/sn_prov_replace_head_commit() now stamp the
// denormalized freshness clock, and clearing it on an unstampable chain calls
// delete_post_meta(). Real WordPress always defines it; this stub set did not,
// so the harness fataled on a function the callee genuinely uses.
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key ) {
		unset( $GLOBALS['__pv_meta'][ $id ][ $key ] );
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

// Core's wp_get_latest_revision_id_and_total_count() (revision.php:716): an
// array, or a WP_Error when revisions are off for the post.
class WP_Error {
	public $code;
	public function __construct( $code = '' ) {
		$this->code = $code; }
}
$GLOBALS['__rev'] = array( 'latest_id' => 0, 'count' => 0 );
function wp_get_latest_revision_id_and_total_count( $post = 0 ) {
	return $GLOBALS['__rev'];
}

require_once SNT_PATH . 'inc/provenance-core.php';

$pass = 0;
$fail = 0;
function cr_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  PASS: $msg\n";
	} else {
		++$fail;
		echo "  FAIL: $msg\n";
	}
}

echo "Provenance core revision suite\n\n";

function cr_post( $id, $content ) {
	$p                = new stdClass();
	$p->ID            = $id;
	$p->post_title    = 'A note with revisions';
	$p->post_content  = $content;
	$p->post_date     = '2026-01-01 00:00:00';
	$p->post_date_gmt = '2026-01-01 00:00:00';
	$p->post_author   = 1;
	return $p;
}
// Two posts sharing one uid, so the two payloads compare byte for byte.
update_post_meta( 921, SN_PROV_UID_META, sn_prov_note_uid( 920 ) );

$GLOBALS['__rev'] = array( 'latest_id' => 0, 'count' => 0 );
$without          = sn_prov_record( cr_post( 920, '<p>Same body.</p>' ), 'Juan Lentino' );
$canon_without    = end( $GLOBALS['__pv_actions'] )[1][2] ?? null;
$GLOBALS['__rev'] = array( 'latest_id' => 4242, 'count' => 7 );
$with             = sn_prov_record( cr_post( 921, '<p>Same body.</p>' ), 'Juan Lentino' );
$canon_with       = end( $GLOBALS['__pv_actions'] )[1][2] ?? null;
$hook_commit      = end( $GLOBALS['__pv_actions'] )[1][1] ?? array();

cr_true( 4242 === ( $with[0]['core_revision_id'] ?? null ), 'the commit carries the newest core revision id' );
cr_true( ! array_key_exists( 'core_revision_id', $without[0] ), 'no revision, no key' );
cr_true( $without[0]['content_hash'] === $with[0]['content_hash'], 'the payload hash is identical with and without the revision id' );
cr_true( sn_prov_canonical_json( $without[0]['payload'] ) === sn_prov_canonical_json( $with[0]['payload'] ), 'the signed payload is byte-identical with and without it' );
cr_true( is_string( $canon_with ) && $canon_without === $canon_with, 'the canonical bytes handed to sn_prov_committed are identical too' );
cr_true( ! array_key_exists( 'core_revision_id', $with[0]['payload'] ), 'the payload never carries it' );
cr_true( 4242 === ( $hook_commit['core_revision_id'] ?? null ), 'sn_prov_committed sees it beside the payload' );

// Revisions off for the post: core answers WP_Error, and nothing is stored.
$GLOBALS['__rev'] = new WP_Error( 'revisions_not_enabled' );
$off              = sn_prov_record( cr_post( 920, '<p>A second body, revisions off.</p>' ), 'Juan Lentino' );
cr_true( is_array( $off ) && ! array_key_exists( 'core_revision_id', end( $off ) ), 'revisions disabled: no key, no fatal' );

// The read side: present only when stored, and a dangling id still reads.
cr_true( function_exists( 'sn_prov_core_revision_field' ) && array( 'core_revision_id' => 4242 ) === sn_prov_core_revision_field( $with[0] ), 'the read field carries a stored id' );
cr_true( function_exists( 'sn_prov_core_revision_field' ) && array() === sn_prov_core_revision_field( $without[0] ) && array() === sn_prov_core_revision_field( null ), 'and nothing for an entry without one' );

// The Worker body is key-picked, so the id never reaches the ledger.
$webhook = (string) file_get_contents( SNT_PATH . 'inc/provenance-webhook.php' );
preg_match( '/\$body = wp_json_encode\( array\((.*?)\) \);/s', $webhook, $m );
preg_match_all( "/'([a-z_]+)'\s*=>/", $m[1] ?? '', $keys );
cr_true( array( 'canonical', 'content_hash', 'note_uid', 'version', 'kind' ) === $keys[1], 'the Worker body names its five keys, and core_revision_id is not one' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
