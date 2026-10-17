<?php
/**
 * Standalone fixture tests for the provenance change class (inc/provenance-change-class.php):
 * the author's label beside a version, outside the signed payload.
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

require_once SNT_PATH . 'inc/provenance-core.php';

$pass = 0;
$fail = 0;
function cc_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  PASS: $msg\n";
	} else {
		++$fail;
		echo "  FAIL: $msg\n";
	}
}

echo "Provenance change class suite\n\n";

// --- The vocabulary.
cc_true( defined( 'SN_PROV_CHANGE_CLASSES' ) && array( 'reading_aid', 'correction', 'copy', 'claim', 'unclassified' ) === SN_PROV_CHANGE_CLASSES, 'the five classes, in order' );
cc_true( function_exists( 'sn_prov_change_class_label' ) && 'reading aid' === sn_prov_change_class_label( 'reading_aid' ), 'reading_aid reads "reading aid"' );
cc_true( function_exists( 'sn_prov_change_class_label' ) && '' === sn_prov_change_class_label( 'unclassified' ), 'unclassified shows nothing' );
cc_true( function_exists( 'sn_prov_change_class_label' ) && '' === sn_prov_change_class_label( 'wibble' ) && '' === sn_prov_change_class_label( null ), 'an unknown stored value shows nothing' );

// --- The commit: the class rides beside the payload, never inside it.
$p                = new stdClass();
$p->ID            = 910;
$p->post_title    = 'A classed note';
$p->post_content  = '<p>First body.</p>';
$p->post_date     = '2026-01-01 00:00:00';
$p->post_date_gmt = '2026-01-01 00:00:00';
$p->post_author   = 1;
$q                = clone $p;
$q->ID            = 911;
update_post_meta( 911, SN_PROV_UID_META, sn_prov_note_uid( 910 ) ); // same uid, so the two payloads can be compared byte for byte

$plain = sn_prov_record( $p, 'Juan Lentino' );
if ( function_exists( 'sn_prov_change_class_set_pending' ) ) {
	sn_prov_change_class_set_pending( 911, 'reading_aid' );
}
$classed = sn_prov_record( $q, 'Juan Lentino' );
cc_true( 'reading_aid' === ( $classed[0]['change_class'] ?? null ), 'a pending class lands on the commit' );
cc_true( ! array_key_exists( 'change_class', $plain[0] ), 'no pending class, no key: an unclassified commit carries nothing' );
cc_true( $plain[0]['content_hash'] === $classed[0]['content_hash'], 'the class does not move the content hash' );
cc_true( sn_prov_canonical_json( $plain[0]['payload'] ) === sn_prov_canonical_json( $classed[0]['payload'] ), 'the signed payload is byte-identical with or without a class' );
cc_true( ! array_key_exists( 'change_class', $classed[0]['payload'] ), 'the payload never carries the class' );
cc_true( SN_PROV_ALGO === $classed[0]['payload']['algo'], 'the algo is unchanged' );

// Consumed once: the next save on the same post is unclassified.
$q->post_content = '<p>Second body, materially different.</p>';
$next            = sn_prov_record( $q, 'Juan Lentino' );
cc_true( ! array_key_exists( 'change_class', end( $next ) ), 'the pending class is consumed by the commit it labels' );
cc_true( function_exists( 'sn_prov_change_class_pending' ) && '' === sn_prov_change_class_pending( 911 ), 'nothing left pending afterwards' );

// Keyed by post: a class set for one post never labels another.
if ( function_exists( 'sn_prov_change_class_set_pending' ) ) {
	sn_prov_change_class_set_pending( 999, 'claim' );
}
$p->post_content = '<p>Another body for 910.</p>';
$other           = sn_prov_record( $p, 'Juan Lentino' );
cc_true( ! array_key_exists( 'change_class', end( $other ) ), 'a class pending for another post does not land here' );
if ( function_exists( 'sn_prov_change_class_clear_pending' ) ) {
	sn_prov_change_class_clear_pending( 999 );
}
cc_true( function_exists( 'sn_prov_change_class_pending' ) && '' === sn_prov_change_class_pending( 999 ), 'clear drops it' );
if ( function_exists( 'sn_prov_change_class_set_pending' ) ) {
	sn_prov_change_class_set_pending( 912, 'unclassified' );
	sn_prov_change_class_set_pending( 913, 'wibble' );
}
cc_true( '' === sn_prov_change_class_pending( 912 ) && '' === sn_prov_change_class_pending( 913 ), 'unclassified and unknown values are never held' );

// --- The backfill primitive: label an existing version, leave its hash alone.
$before = sn_prov_get_chain( 910 );
$ok     = function_exists( 'sn_prov_set_change_class' ) ? sn_prov_set_change_class( 910, 1, 'reading_aid' ) : false;
$after  = sn_prov_get_chain( 910 );
cc_true( true === $ok && 'reading_aid' === ( $after[0]['change_class'] ?? null ), 'set_change_class labels a stored version' );
cc_true( $before[0]['content_hash'] === $after[0]['content_hash'] && $before[0]['payload'] === $after[0]['payload'], 'and leaves its hash and payload alone' );
cc_true( function_exists( 'sn_prov_set_change_class' ) && false === sn_prov_set_change_class( 910, 1, 'wibble' ), 'an unknown class is refused' );
cc_true( function_exists( 'sn_prov_set_change_class' ) && false === sn_prov_set_change_class( 910, 99, 'copy' ), 'a missing version is refused' );
cc_true( function_exists( 'sn_prov_set_change_class' ) && true === sn_prov_set_change_class( 910, 1, 'unclassified' ) && ! array_key_exists( 'change_class', sn_prov_get_chain( 910 )[0] ), 'unclassified removes the label' );

// --- The correction rule.
$body    = '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->';
$notice  = '<!-- wp:paragraph {"className":"sn-correction"} --><p class="sn-correction"><strong>Correction, October 16, 2026.</strong> An earlier version said X.</p><!-- /wp:paragraph -->';
$notice2 = '<!-- wp:paragraph {"className":"sn-correction"} --><p class="sn-correction"><strong>Correction, October 17, 2026.</strong> An earlier version said Y.</p><!-- /wp:paragraph -->';
$bare    = '<!-- wp:paragraph --><p><em>Correction, October 1, 2026: An earlier version said Z.</em></p><!-- /wp:paragraph -->';
$table   = '<!-- wp:table --><figure class="wp-block-table"><table><tr><td>a</td></tr></table></figure><!-- /wp:table -->';
$refuse  = function_exists( 'sn_prov_change_class_refusal' ) ? 'sn_prov_change_class_refusal' : static function () {
	return 'missing';
};
cc_true( '' === $refuse( 'reading_aid', $body, $body . $table ), 'a reading aid that adds a table passes' );
cc_true( '' !== $refuse( 'reading_aid', $body, $body . $notice ), 'reading_aid refused when a correction notice is added' );
cc_true( '' !== $refuse( 'copy', $body . $notice, $body . $notice2 ), 'copy refused when a correction notice is replaced' );
cc_true( '' !== $refuse( 'copy', $body, $body . $bare ), 'a notice without the class but reading "Correction," still counts' );
cc_true( '' === $refuse( 'correction', $body, $body . $notice ), 'correction may add one' );
cc_true( '' === $refuse( 'claim', $body . $notice, $body . $notice2 ), 'claim may replace one' );
cc_true( '' === $refuse( 'reading_aid', $body . $notice, $body . $notice . $table ), 'an untouched notice does not block a reading aid' );
cc_true( '' === $refuse( 'copy', $body . $notice, $body ), 'removing a notice is not adding or replacing one' );
cc_true( '' === $refuse( '', $body, $body . $notice ), 'no class, no rule' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
