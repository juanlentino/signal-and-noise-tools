<?php
/**
 * Standalone tests for sn_apply change.types "block_insert" and
 * "block_replace" (v13.2.0) — the caller-composed block edit family. See
 * inc/sn-apply-block-edit.php's docblock for the design.
 *
 * Same bootstrap/stub conventions as the sibling sn_apply test files —
 * EXCEPT parse_blocks/serialize_blocks: the sibling files' JSON-shaped
 * stubs are structurally blind to the exact failure this type's markup
 * gate exists to catch (a malformed delimiter parsing as freeform and
 * round-tripping cleanly), so this file carries FAITHFUL mini-grammar
 * stubs that model core's delimiter parsing for the shapes tested:
 * openers/closers/void blocks, attrs JSON re-encoding (the round-trip
 * mismatch mechanism), the core/ namespace elision, and freeform fallback
 * for anything malformed. Stub-drift discipline: both directions modeled,
 * for exactly the shapes the assertions below exercise.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) )       { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'ARRAY_A' ) )       { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) )        { define( 'OBJECT', 'OBJECT' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg\n"; }
}
function eq( $expected, $actual, $msg ) {
	ok( $expected === $actual, $msg . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

/* ════════════════════════════════════════════════════════════════════════
 * WP + rails stubs (BEFORE the SUT loads)
 * ════════════════════════════════════════════════════════════════════════ */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_data( $key = '' ) { return $this->data; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) )  { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $opts = 0 ) { return json_encode( $d, $opts ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $t, $c, $p = 10, $a = 1 ) { return true; } }
if ( ! function_exists( 'apply_filters' ) ) {
	$GLOBALS['__filters'] = array();
	function apply_filters( $h, $v ) { foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); } return $v; }
}

$GLOBALS['__next_id']            = 1000;
$GLOBALS['__posts']              = array(); // id => ARRAY_A row
$GLOBALS['__post_meta']          = array();
$GLOBALS['__options']            = array();
$GLOBALS['__transients']         = array();
$GLOBALS['__write_calls']        = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'delete_option' => 0, 'set_transient' => 0 );
$GLOBALS['__audit_calls']        = array();
$GLOBALS['__bound_uuid']         = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$GLOBALS['__auth_uuid']          = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'; // = bound => owner, by default
$GLOBALS['__revisions_to_keep']  = -1; // unlimited
$GLOBALS['__clobber_schedule']   = false; // one-shot: forces the next wp_update_post to early-publish (drives the guard's red path)

function tf_post( $id, $overrides = array() ) {
	$GLOBALS['__posts'][ $id ] = array_merge( array(
		'ID' => $id, 'post_title' => "Post $id", 'post_name' => "post-$id",
		'post_status' => 'publish', 'post_type' => 'post', 'post_parent' => 0,
		'post_date' => '2026-06-01 10:00:00', 'post_date_gmt' => '2026-06-01 10:00:00',
		'post_modified' => '2026-07-01 10:00:00',
		'post_modified_gmt' => '2026-07-01 10:00:00', 'post_content' => '', 'post_excerpt' => '',
	), $overrides );
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id, $output = 'OBJECT' ) {
		$row = $GLOBALS['__posts'][ (int) $id ] ?? null;
		if ( null === $row ) { return null; }
		return 'ARRAY_A' === $output ? $row : (object) $row;
	}
}
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap, $id = null ) { return true; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'post_type_exists' ) ) { function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'attachment' ), true ); } }
if ( ! function_exists( 'get_post_type_object' ) ) { function get_post_type_object( $t ) { $o = new stdClass(); $o->public = 'attachment' !== $t; return $o; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__write_calls']['update_option']++; $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { $GLOBALS['__write_calls']['delete_option']++; unset( $GLOBALS['__options'][ $k ] ); return true; } }
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		if ( ! array_key_exists( $key, $GLOBALS['__post_meta'][ (int) $id ] ?? array() ) ) { return $single ? '' : array(); }
		$v = $GLOBALS['__post_meta'][ (int) $id ][ $key ];
		return $single ? $v : array( $v );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['__write_calls']['update_post_meta']++; $GLOBALS['__post_meta'][ (int) $id ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) { function delete_post_meta( $id, $key ) { unset( $GLOBALS['__post_meta'][ (int) $id ][ $key ] ); return true; } }

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) {
		$GLOBALS['__write_calls']['wp_update_post']++;
		$id = (int) ( $args['ID'] ?? 0 );
		if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) { return $wp_error ? new WP_Error( 'invalid_post', 'no such post' ) : 0; }
		foreach ( $args as $k => $v ) { if ( 'ID' !== $k ) { $GLOBALS['__posts'][ $id ][ $k ] = $v; } }
		// One-shot early-publish clobber: models core deciding on its own that
		// this row should go live NOW (the exact disaster the SUT's schedule
		// guard exists to catch). Auto-clears so the guard's restore attempt
		// runs against an honest store.
		if ( $GLOBALS['__clobber_schedule'] ) {
			$GLOBALS['__clobber_schedule'] = false;
			$GLOBALS['__posts'][ $id ]['post_status'] = 'publish';
			$GLOBALS['__posts'][ $id ]['post_date']   = '2026-08-25 00:00:00';
		}
		return $id;
	}
}
if ( ! function_exists( 'post_type_supports' ) ) { function post_type_supports( $t, $f ) { return true; } }
if ( ! function_exists( 'wp_revisions_to_keep' ) ) { function wp_revisions_to_keep( $post ) { return $GLOBALS['__revisions_to_keep']; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( '_wp_put_post_revision' ) ) {
	function _wp_put_post_revision( $post ) {
		$GLOBALS['__write_calls']['_wp_put_post_revision']++;
		$rid = $GLOBALS['__next_id']++;
		tf_post( $rid, array(
			'post_type' => 'revision', 'post_parent' => (int) ( $post['ID'] ?? 0 ),
			'post_content' => (string) ( $post['post_content'] ?? '' ),
			'post_title'   => (string) ( $post['post_title'] ?? '' ),
			'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ),
		) );
		return $rid;
	}
}

if ( ! function_exists( 'sn_mcp_rw_bound_uuid' ) )                      { function sn_mcp_rw_bound_uuid() { return $GLOBALS['__bound_uuid']; } }
if ( ! function_exists( 'sn_mcp_rw_authenticated_app_password_uuid' ) ) { function sn_mcp_rw_authenticated_app_password_uuid() { return $GLOBALS['__auth_uuid']; } }
if ( ! function_exists( 'sn_mcp_rw_audit_record' ) ) {
	function sn_mcp_rw_audit_record( $slug, $args, $outcome, $error_source = null ) {
		$row = array( 'slug' => $slug, 'args' => $args, 'outcome' => $outcome, 'error' => is_wp_error( $error_source ) ? $error_source->get_error_code() : $error_source );
		$GLOBALS['__audit_calls'][] = $row;
		return $row;
	}
}

/* ════════════════════════════════════════════════════════════════════════
 * FAITHFUL parse_blocks / serialize_blocks mini-grammar stubs.
 *
 * Modeled core behaviors (each load-bearing for an assertion below):
 *   - "wp:paragraph" parses to blockName "core/paragraph"; serialize elides
 *     the core/ namespace back to "wp:paragraph" (round-trip symmetry).
 *   - attrs JSON is DECODED on parse and RE-ENCODED on serialize — a
 *     non-canonical attrs string ({"level": 2} with a space) round-trips
 *     to canonical form and BYTE-MISMATCHES, core's real mechanism.
 *   - a malformed delimiter (no valid <!-- wp:... --> match) falls into a
 *     FREEFORM chunk (blockName null, innerHTML verbatim) that serializes
 *     back verbatim — i.e. it round-trips CLEANLY, which is exactly why
 *     the SUT's freeform check must exist alongside the round-trip check.
 *   - void blocks (<!-- wp:x /-->) parse with empty innerContent and
 *     serialize back to the void form.
 * Deliberately FLAT: nested blocks stay raw inside innerHTML (round-trips
 * verbatim); registry recursion over innerBlocks is driven directly with a
 * hand-built tree instead.
 * ════════════════════════════════════════════════════════════════════════ */

function tf_be_freeform( $s ) {
	return array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => (string) $s, 'innerContent' => array( (string) $s ) );
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$content = (string) $content;
		if ( '' === $content ) { return array(); }
		$re = '#<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)(\s+\{.*?\})?\s+?(/)?-->#s';
		if ( ! preg_match_all( $re, $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array( tf_be_freeform( $content ) );
		}
		$out = array(); $pos = 0; $i = 0; $n = count( $m );
		while ( $i < $n ) {
			$mt  = $m[ $i ];
			$off = (int) $mt[0][1];
			$len = strlen( $mt[0][0] );
			if ( $off > $pos ) { $out[] = tf_be_freeform( substr( $content, $pos, $off - $pos ) ); }
			$is_closer = '' !== ( $mt[1][0] ?? '' );
			$is_void   = '' !== ( $mt[4][0] ?? '' );
			$name      = (string) $mt[2][0];
			$full      = false === strpos( $name, '/' ) ? 'core/' . $name : $name;
			$attrs_raw = trim( (string) ( $mt[3][0] ?? '' ) );
			$attrs     = '' !== $attrs_raw ? (array) json_decode( $attrs_raw, true ) : array();
			if ( $is_closer ) { // stray closer: freeform, like core's recovery
				$out[] = tf_be_freeform( substr( $content, $off, $len ) );
				$pos = $off + $len; $i++;
				continue;
			}
			if ( $is_void ) {
				$out[] = array( 'blockName' => $full, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
				$pos = $off + $len; $i++;
				continue;
			}
			$depth = 1; $j = $i + 1; $close = null;
			while ( $j < $n ) {
				$jt = $m[ $j ];
				if ( '' !== ( $jt[1][0] ?? '' ) ) { $depth--; if ( 0 === $depth ) { $close = $jt; break; } }
				elseif ( '' === ( $jt[4][0] ?? '' ) ) { $depth++; }
				$j++;
			}
			if ( null === $close ) { // unbalanced opener: freeform
				$out[] = tf_be_freeform( substr( $content, $off, $len ) );
				$pos = $off + $len; $i++;
				continue;
			}
			$inner_start = $off + $len;
			$inner       = substr( $content, $inner_start, (int) $close[0][1] - $inner_start );
			$out[]       = array( 'blockName' => $full, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $inner, 'innerContent' => array( $inner ) );
			$pos = (int) $close[0][1] + strlen( $close[0][0] );
			$i   = $j + 1;
		}
		if ( $pos < strlen( $content ) ) { $out[] = tf_be_freeform( substr( $content, $pos ) ); }
		return $out;
	}
}
if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $blocks ) {
		$outp = '';
		foreach ( (array) $blocks as $b ) {
			$b = (array) $b;
			if ( null === ( $b['blockName'] ?? null ) ) { $outp .= (string) ( $b['innerHTML'] ?? '' ); continue; }
			$name  = (string) $b['blockName'];
			$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
			$attrs = ! empty( $b['attrs'] ) ? ' ' . json_encode( $b['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
			if ( empty( $b['innerContent'] ) && '' === (string) ( $b['innerHTML'] ?? '' ) ) {
				$outp .= '<!-- wp:' . $short . $attrs . ' /-->';
			} else {
				$outp .= '<!-- wp:' . $short . $attrs . ' -->' . (string) ( $b['innerHTML'] ?? '' ) . '<!-- /wp:' . $short . ' -->';
			}
		}
		return $outp;
	}
}
if ( ! function_exists( 'serialize_block' ) ) { function serialize_block( $b ) { return serialize_blocks( array( $b ) ); } }

$GLOBALS['__registered_blocks'] = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/separator' );
if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	class WP_Block_Type_Registry {
		private static $inst = null;
		public static function get_instance() { if ( null === self::$inst ) { self::$inst = new self(); } return self::$inst; }
		public function is_registered( $name ) { return in_array( (string) $name, $GLOBALS['__registered_blocks'], true ); }
	}
}

/* ════════════════════════════════════════════════════════════════════════
 * Load the SUT
 * ════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/health-check-drift-time-phrases.php';
require __DIR__ . '/../inc/ai-drift-phrase-suggest.php';
require __DIR__ . '/../inc/sn-validate-checks.php';
require __DIR__ . '/../inc/sn-validate-checks-media.php';
require __DIR__ . '/../inc/sn-apply-revision.php';
require __DIR__ . '/../inc/sn-apply-gates.php';
require __DIR__ . '/../inc/sn-apply-validation.php';
require __DIR__ . '/../inc/sn-apply-delete-draft.php';
require __DIR__ . '/../inc/sn-apply-link-reshape.php'; // snt_sn_apply_link_prose_normalize() — the prose-delta normalizer
require __DIR__ . '/../inc/sn-apply-sentence-replace.php';
require __DIR__ . '/../inc/sn-apply-block-edit.php';
require __DIR__ . '/../inc/sn-apply-executors.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';

echo "sn_apply block_insert + block_replace — the caller-composed block edit family\n\n";

function tf_reset_writes() {
	foreach ( $GLOBALS['__write_calls'] as $k => $_ ) { $GLOBALS['__write_calls'][ $k ] = 0; }
}
function tf_total_writes() {
	return array_sum( $GLOBALS['__write_calls'] );
}

/* ════════════════════════════════════════════════════════════════════════
 * Fixtures
 * ════════════════════════════════════════════════════════════════════════ */
$p1   = '<!-- wp:paragraph --><p>First paragraph with the opening thoughts of the piece, long enough to anchor on.</p><!-- /wp:paragraph -->';
$h1   = '<!-- wp:heading {"level":2} --><h2>A heading anchoring the second section of the piece</h2><!-- /wp:heading -->';
$p2   = '<!-- wp:paragraph --><p>Second paragraph body text, also long enough to serve as an anchor for the tests.</p><!-- /wp:paragraph -->';
$body = $p1 . "\n\n" . $h1 . "\n\n" . $p2;
tf_post( 900, array( 'post_content' => $body ) );
$fp = snt_corpus_content_hash( $body );

$good_blocks = '<!-- wp:paragraph --><p>A freshly composed paragraph, inserted by the caller for this test run.</p><!-- /wp:paragraph -->';
$anchor1     = 'opening thoughts of the piece, long enough to anchor on';
$anchor2     = 'also long enough to serve as an anchor for the tests';

function be_call( $type, $overrides = array() ) {
	global $fp, $good_blocks, $anchor1;
	$base = array(
		'target'  => array( 'post_id' => 900 ),
		'mode'    => 'revision',
		'dry_run' => false,
		'change'  => array(
			'type'        => $type,
			'fingerprint' => $fp,
			'payload'     => array( 'blocks' => $good_blocks, 'anchor' => $anchor1 ),
		),
	);
	foreach ( $overrides as $k => $v ) {
		if ( 'change' === $k ) {
			foreach ( $v as $ck => $cv ) {
				if ( 'payload' === $ck ) {
					foreach ( $cv as $pk => $pv ) {
						if ( '__unset' === $pv ) { unset( $base['change']['payload'][ $pk ] ); }
						else { $base['change']['payload'][ $pk ] = $pv; }
					}
				} elseif ( '__unset_fingerprint' === $ck ) { unset( $base['change']['fingerprint'] ); }
				else { $base['change'][ $ck ] = $cv; }
			}
		} else { $base[ $k ] = $v; }
	}
	return snt_ability_sn_apply( $base );
}

/* ════════════════════════════════════════════════════════════════════════
 * 0. Stub sanity — the faithful stubs really model the trap: a malformed
 *    delimiter parses as freeform AND round-trips byte-identically, so a
 *    round-trip check alone can never catch it. (Negative-control the
 *    instrument before trusting what it shows.)
 * ════════════════════════════════════════════════════════════════════════ */
$malformed = '<!-- wp:paragraph -><p>The delimiter above is missing a dash and never closes properly.</p>';
$mparsed   = parse_blocks( $malformed );
ok( 1 === count( $mparsed ) && null === $mparsed[0]['blockName'], 'STUB.1: a malformed delimiter parses as ONE freeform chunk (null blockName)' );
eq( $malformed, serialize_blocks( $mparsed ), 'STUB.2: ...and that freeform chunk round-trips BYTE-IDENTICALLY — the blindness the freeform check exists for' );
eq( $good_blocks, serialize_blocks( parse_blocks( $good_blocks ) ), 'STUB.3: canonical markup round-trips byte-identically (namespace elision modeled)' );
ok( serialize_blocks( parse_blocks( '<!-- wp:heading {"level": 2} --><h2>x</h2><!-- /wp:heading -->' ) ) !== '<!-- wp:heading {"level": 2} --><h2>x</h2><!-- /wp:heading -->', 'STUB.4: non-canonical attrs JSON re-encodes canonically — the round-trip mismatch mechanism' );

/* ════════════════════════════════════════════════════════════════════════
 * 1. Markup gate — freeform, round-trip, unknown block. Each refusal by
 *    name, each a 422 caller error, each with zero writes.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'blocks' => $malformed ) ) ) );
ok( is_wp_error( $r ), 'MK.1: malformed-delimiter markup refuses' );
eq( 'snt_sn_apply_invalid_blocks', $r->get_error_code(), 'MK.2: ...as the freeform refusal (the round-trip check alone would have passed it)' );
eq( 422, (int) ( $r->get_error_data()['status'] ?? 0 ), 'MK.3: 422 caller error' );

$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'blocks' => '<p>Plain HTML with no block delimiters at all, which is not serialized block markup.</p>' ) ) ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_invalid_blocks' === $r->get_error_code(), 'MK.4: delimiter-free HTML refuses as freeform too' );

$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'blocks' => '<!-- wp:heading {"level": 2} --><h2>Non-canonical attrs spacing</h2><!-- /wp:heading -->' ) ) ) );
ok( is_wp_error( $r ), 'MK.5: non-canonical markup refuses' );
eq( 'snt_sn_apply_markup_roundtrip', $r->get_error_code(), 'MK.6: ...as the round-trip refusal' );

$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'blocks' => '<!-- wp:madeup/block --><div>Nobody registered this block on the site.</div><!-- /wp:madeup/block -->' ) ) ) );
ok( is_wp_error( $r ), 'MK.7: unregistered block refuses' );
eq( 'snt_sn_apply_unknown_block', $r->get_error_code(), 'MK.8: ...as the unknown-block refusal' );
ok( false !== strpos( json_decode( $r->get_error_message(), true )['gates']['fingerprint']['detail'] ?? '', 'madeup/block' ), 'MK.9: the refusal NAMES the unknown block' );

$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'blocks' => '   ' ) ) ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_invalid_blocks' === $r->get_error_code(), 'MK.10: whitespace-only blocks refuses as empty' );

// Registry recursion over innerBlocks: driven directly with a hand-built
// tree (the flat parse stub folds nesting into innerHTML, so the recursive
// walk is exercised at the function seam it actually lives in).
$tree = array( array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array(
	array( 'blockName' => 'acme/unregistered-inner', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ),
) ) );
eq( 'acme/unregistered-inner', snt_sn_apply_block_edit_unknown_block( $tree ), 'MK.11: the registry walk recurses into innerBlocks and names the nested unknown' );
$tree[0]['innerBlocks'][0]['blockName'] = 'core/paragraph';
eq( null, snt_sn_apply_block_edit_unknown_block( $tree ), 'MK.12: an all-registered tree passes the walk' );
eq( 0, tf_total_writes(), 'MK.13: zero writes across every markup refusal' );

/* ════════════════════════════════════════════════════════════════════════
 * 2. Anchor contract — length floor, not-found (409, named), ambiguity
 *    (422, named), context_snippet disambiguation, boundary, delimiter.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'anchor' => 'too short' ) ) ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_invalid_anchor' === $r->get_error_code(), 'AN.1: sub-sentence anchor refuses (first-occurrence hazard)' );

$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'anchor' => 'this span appears nowhere in the stored content at all' ) ) ) );
ok( is_wp_error( $r ), 'AN.2: unlocatable anchor refuses' );
eq( 'snt_sn_apply_anchor_not_found', $r->get_error_code(), 'AN.3: ...as the 409 not-found conflict' );
eq( 409, (int) ( $r->get_error_data()['status'] ?? 0 ), 'AN.4: 409' );
ok( false !== strpos( json_decode( $r->get_error_message(), true )['gates']['fingerprint']['detail'] ?? '', 'this span appears nowhere' ), 'AN.5: the refusal NAMES the anchor' );

// Ambiguity fixture: the same sentence in two paragraphs — pushed far
// enough apart in bytes that the 250-byte context window can actually
// discriminate (in a tiny fixture, one window covers the whole post and
// the snippet filter keeps both matches — correctly, but uselessly).
$dup_sentence = 'This exact repeated sentence appears twice in the post body.';
$dup_filler   = str_repeat( 'Padding copy that pushes the two occurrences far apart in raw bytes. ', 8 );
$dup_body     = '<!-- wp:paragraph --><p>' . $dup_sentence . ' Alpha context marker here. ' . $dup_filler . '</p><!-- /wp:paragraph -->' . "\n\n" . '<!-- wp:paragraph --><p>' . $dup_filler . $dup_sentence . ' Beta context marker here.</p><!-- /wp:paragraph -->';
tf_post( 920, array( 'post_content' => $dup_body ) );
$dup_fp = snt_corpus_content_hash( $dup_body );

$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 920 ), 'change' => array( 'fingerprint' => $dup_fp, 'payload' => array( 'anchor' => $dup_sentence ) ) ) );
ok( is_wp_error( $r ), 'AN.6: ambiguous anchor refuses' );
eq( 'snt_sn_apply_anchor_ambiguous', $r->get_error_code(), 'AN.7: ...naming ambiguity, never guessing' );
eq( 422, (int) ( $r->get_error_data()['status'] ?? 0 ), 'AN.8: 422' );

$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 920 ), 'dry_run' => true, 'change' => array( 'fingerprint' => $dup_fp, 'payload' => array( 'anchor' => $dup_sentence, 'context_snippet' => 'Beta context marker' ) ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['gates']['fingerprint']['passed'] ?? null ), 'AN.9: context_snippet disambiguates to one match' );
$after_beta = (string) ( $r['diff']['after'] ?? '' );
ok( strpos( $after_beta, 'freshly composed paragraph' ) > strpos( $after_beta, 'Beta context marker' ), 'AN.10: ...and the insert lands after the BETA paragraph, not the alpha one' );

// Boundary: an anchor living in a freeform gap between blocks has no
// containing top-level block.
$gap_body = $p1 . "\nA raw freeform sentence sitting between two blocks entirely outside any delimiter.\n" . $p2;
tf_post( 930, array( 'post_content' => $gap_body ) );
$gap_fp = snt_corpus_content_hash( $gap_body );
$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 930 ), 'change' => array( 'fingerprint' => $gap_fp, 'payload' => array( 'anchor' => 'A raw freeform sentence sitting between two blocks' ) ) ) );
ok( is_wp_error( $r ), 'AN.11: freeform-gap anchor refuses' );
eq( 'snt_sn_apply_anchor_boundary', $r->get_error_code(), 'AN.12: ...as the boundary refusal (no containing top-level block)' );

// Delimiter intersection: an anchor overlapping the closing comment.
$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'anchor' => 'long enough to anchor on.</p><!-- /wp:paragraph' ) ) ) );
ok( is_wp_error( $r ), 'AN.13: delimiter-overlapping anchor refuses' );
eq( 'snt_sn_apply_anchor_in_delimiter', $r->get_error_code(), 'AN.14: ...as the in-delimiter refusal' );
eq( 0, tf_total_writes(), 'AN.15: zero writes across every anchor refusal' );

/* ════════════════════════════════════════════════════════════════════════
 * 3. Fingerprint contract — REQUIRED (422) vs stale (409), the
 *    sentence_replace binding exactly. payload.edits refuses.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$r = be_call( 'block_insert', array( 'change' => array( '__unset_fingerprint' => true ) ) );
ok( is_wp_error( $r ), 'FP.1: missing fingerprint refuses' );
eq( 'snt_sn_apply_missing_fingerprint', $r->get_error_code(), 'FP.2: missing is the 422 caller error, distinct from stale' );
eq( 422, (int) ( $r->get_error_data()['status'] ?? 0 ), 'FP.3: 422' );

$r = be_call( 'block_insert', array( 'change' => array( 'fingerprint' => str_repeat( 'd', 32 ) ) ) );
ok( is_wp_error( $r ), 'FP.4: stale fingerprint refuses' );
eq( 'snt_sn_apply_fingerprint_stale', $r->get_error_code(), 'FP.5: stale is the 409 merge conflict' );
eq( 409, (int) ( $r->get_error_data()['status'] ?? 0 ), 'FP.6: 409' );
$rep = json_decode( $r->get_error_message(), true );
eq( $fp, $rep['gates']['fingerprint']['observed'] ?? null, 'FP.7: the gate reports the observed live content_hash for re-sync' );

$r = be_call( 'block_replace', array( 'change' => array( 'payload' => array( 'edits' => array() ) ) ) );
ok( is_wp_error( $r ), 'FP.8: payload.edits refuses' );
eq( 'snt_sn_apply_edits_not_supported', $r->get_error_code(), 'FP.9: ...naming the no-batch contract for block edits' );
eq( 0, tf_total_writes(), 'FP.10: zero writes' );

/* ════════════════════════════════════════════════════════════════════════
 * 4. block_insert positions — before / after (default) / end; splice
 *    geometry exact; "end" refuses a supplied anchor; bad position refuses.
 * ════════════════════════════════════════════════════════════════════════ */
$computed = snt_sn_apply_block_edit_compute( $body, 'block_insert', array( 'blocks' => $good_blocks, 'anchor' => $anchor1, 'position' => 'before' ) );
eq( $good_blocks . "\n\n" . $body, $computed['new_content'] ?? null, 'POS.1: position "before" splices immediately before the anchored block' );

$computed = snt_sn_apply_block_edit_compute( $body, 'block_insert', array( 'blocks' => $good_blocks, 'anchor' => $anchor1 ) );
eq( $p1 . "\n\n" . $good_blocks . "\n\n" . $h1 . "\n\n" . $p2, $computed['new_content'] ?? null, 'POS.2: position defaults to "after" — splices immediately after the anchored block' );

$computed = snt_sn_apply_block_edit_compute( $body, 'block_insert', array( 'blocks' => $good_blocks, 'position' => 'end' ) );
eq( $body . "\n\n" . $good_blocks, $computed['new_content'] ?? null, 'POS.3: position "end" appends' );

$computed = snt_sn_apply_block_edit_compute( '', 'block_insert', array( 'blocks' => $good_blocks, 'position' => 'end' ) );
eq( $good_blocks, $computed['new_content'] ?? null, 'POS.4: "end" on empty content emits the blocks alone, no separator' );

$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'position' => 'end' ) ) ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_invalid_anchor' === $r->get_error_code(), 'POS.5: a supplied anchor with position "end" refuses (it anchors nothing)' );

$r = be_call( 'block_insert', array( 'change' => array( 'payload' => array( 'position' => 'inside' ) ) ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_invalid_position' === $r->get_error_code(), 'POS.6: an unknown position refuses' );

/* ════════════════════════════════════════════════════════════════════════
 * 5. block_replace — the WHOLE top-level block goes; replaced_block
 *    reports its serialized form in the diff, dry run and real write both.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$repl_heading = '<!-- wp:heading {"level":2} --><h2>A heading anchoring the second section of the piece</h2><!-- /wp:heading -->';
$r = be_call( 'block_replace', array( 'dry_run' => true, 'change' => array( 'payload' => array( 'anchor' => 'A heading anchoring the second section', 'blocks' => $good_blocks ) ) ) );
ok( ! is_wp_error( $r ) && false === ( $r['applied'] ?? null ), 'REP.1: dry run previews without applying' );
eq( $repl_heading, $r['diff']['replaced_block'] ?? null, 'REP.2: dry-run diff reports the replaced block\'s serialized form' );
eq( $p1 . "\n\n" . $good_blocks . "\n\n" . $p2, $r['diff']['after'] ?? null, 'REP.3: the WHOLE heading block is replaced — surrounding blocks and separators untouched' );
eq( 0, tf_total_writes(), 'REP.4: zero writes on the dry run' );

/* ════════════════════════════════════════════════════════════════════════
 * 6. Prose delta — the ledger consequence, visible in dry run AND write.
 *    Restructure-only coalesces; new text mints a version.
 * ════════════════════════════════════════════════════════════════════════ */
// Restructure-only: the heading becomes a paragraph with IDENTICAL text.
$same_text_para = '<!-- wp:paragraph --><p>A heading anchoring the second section of the piece</p><!-- /wp:paragraph -->';
$r = be_call( 'block_replace', array( 'dry_run' => true, 'change' => array( 'payload' => array( 'anchor' => 'A heading anchoring the second section', 'blocks' => $same_text_para ) ) ) );
eq( false, $r['diff']['prose_changed'] ?? null, 'DELTA.1: restructure-only replace — prose_changed false' );
eq( 'coalesces', $r['diff']['ledger_impact'] ?? null, 'DELTA.2: ...and ledger_impact "coalesces" (no new signed version)' );
eq( '', $r['diff']['prose_added'] ?? null, 'DELTA.3: nothing added' );
eq( '', $r['diff']['prose_removed'] ?? null, 'DELTA.4: nothing removed' );

// New text: the insert brings a sentence the post did not have.
$r = be_call( 'block_insert', array( 'dry_run' => true ) );
eq( true, $r['diff']['prose_changed'] ?? null, 'DELTA.5: an insert with new text — prose_changed true' );
eq( 'new_version', $r['diff']['ledger_impact'] ?? null, 'DELTA.6: ...and ledger_impact "new_version" — visible BEFORE the write' );
ok( false !== strpos( (string) ( $r['diff']['prose_added'] ?? '' ), 'freshly composed paragraph' ), 'DELTA.7: prose_added carries the new normalized text' );
eq( '', $r['diff']['prose_removed'] ?? null, 'DELTA.8: an insert removes nothing' );

/* ════════════════════════════════════════════════════════════════════════
 * 7. mode:"revision" stages, live row untouched; mode:"publish" writes
 *    live — and the write's diff carries the SAME delta fields.
 * ════════════════════════════════════════════════════════════════════════ */
tf_reset_writes();
$live_before = $GLOBALS['__posts'][900]['post_content'];
$r = be_call( 'block_insert' );
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'REV.1: revision-mode apply succeeds' );
ok( is_int( $r['revision_id'] ?? null ) && $r['revision_id'] > 0, 'REV.2: a revision ID comes back' );
eq( $live_before, $GLOBALS['__posts'][900]['post_content'], 'REV.3: live post content UNTOUCHED' );
$rev_row = $GLOBALS['__posts'][ $r['revision_id'] ] ?? null;
ok( is_array( $rev_row ) && false !== strpos( (string) $rev_row['post_content'], 'freshly composed paragraph' ), 'REV.4: the staged revision carries the inserted block' );
eq( 'new_version', $r['diff']['ledger_impact'] ?? null, 'REV.5: the REAL write\'s diff carries the same prose-delta fields as the dry run' );

tf_reset_writes();
$r = be_call( 'block_replace', array( 'mode' => 'publish', 'change' => array( 'payload' => array( 'anchor' => 'A heading anchoring the second section', 'blocks' => $good_blocks ) ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'PUB.1: publish-mode replace applies live' );
eq( $p1 . "\n\n" . $good_blocks . "\n\n" . $p2, $GLOBALS['__posts'][900]['post_content'], 'PUB.2: the live row carries the exact spliced content' );
eq( $repl_heading, $r['diff']['replaced_block'] ?? null, 'PUB.3: the write\'s diff reports the replaced block too' );
eq( 'publish', $GLOBALS['__posts'][900]['post_status'], 'PUB.4: a published post stays published' );

/* ════════════════════════════════════════════════════════════════════════
 * 8. Scheduled posts — status + date preserved EXACTLY through a publish-
 *    mode write; and the guard is proven able to FAIL (the clobber stub
 *    models core early-publishing the row; the SUT must catch it, restore,
 *    and error loudly — never a silent early publish).
 * ════════════════════════════════════════════════════════════════════════ */
$sched_body = '<!-- wp:paragraph --><p>A scheduled note whose sentence is long enough to act as the anchor here.</p><!-- /wp:paragraph -->';
tf_post( 910, array( 'post_status' => 'future', 'post_date' => '2026-09-15 09:00:00', 'post_date_gmt' => '2026-09-15 09:00:00', 'post_content' => $sched_body ) );
$sched_fp = snt_corpus_content_hash( $sched_body );

$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 910 ), 'mode' => 'publish', 'change' => array( 'fingerprint' => $sched_fp, 'payload' => array( 'anchor' => 'sentence is long enough to act as the anchor' ) ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'SCHED.1: a publish-mode block edit on a scheduled post succeeds' );
eq( 'future', $GLOBALS['__posts'][910]['post_status'], 'SCHED.2: post_status "future" preserved exactly' );
eq( '2026-09-15 09:00:00', $GLOBALS['__posts'][910]['post_date'], 'SCHED.3: post_date preserved exactly' );
ok( false !== strpos( (string) $GLOBALS['__posts'][910]['post_content'], 'freshly composed paragraph' ), 'SCHED.4: ...and the content edit landed' );

// The guard's red path: prove it can fail before trusting its green.
$sched_fp2 = snt_corpus_content_hash( (string) $GLOBALS['__posts'][910]['post_content'] );
$GLOBALS['__clobber_schedule'] = true;
$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 910 ), 'mode' => 'publish', 'change' => array( 'fingerprint' => $sched_fp2, 'payload' => array( 'anchor' => 'sentence is long enough to act as the anchor', 'position' => 'before' ) ) ) );
ok( is_wp_error( $r ), 'SCHED.5: a write that early-publishes the row FAILS LOUDLY' );
eq( 'snt_sn_apply_schedule_violation', $r->get_error_code(), 'SCHED.6: ...with the schedule-violation code' );
eq( 500, (int) ( $r->get_error_data()['status'] ?? 0 ), 'SCHED.7: 500 — a tool bug, not a caller error' );
eq( 'future', $GLOBALS['__posts'][910]['post_status'], 'SCHED.8: the restore attempt put the schedule back' );
eq( '2026-09-15 09:00:00', $GLOBALS['__posts'][910]['post_date'], 'SCHED.9: ...date included' );
$GLOBALS['__clobber_schedule'] = false;

/* ════════════════════════════════════════════════════════════════════════
 * 9. Gate 2 — the body check runs, and the brand-voice evidence pass over
 *    the payload's own prose surfaces em-dash counts WITHOUT refusing.
 * ════════════════════════════════════════════════════════════════════════ */
$emdash_blocks = '<!-- wp:paragraph --><p>A sentence with an em dash' . "\xE2\x80\x94" . 'right in the middle of the composed copy here.</p><!-- /wp:paragraph -->';
// PUB.2 live-wrote post 900 above, so the original $fp is stale by now —
// which is the fingerprint gate doing its job. Re-observe the live hash.
$fp_live = snt_corpus_content_hash( (string) $GLOBALS['__posts'][900]['post_content'] );
$r = be_call( 'block_insert', array( 'dry_run' => true, 'change' => array( 'fingerprint' => $fp_live, 'payload' => array( 'blocks' => $emdash_blocks ) ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['gates']['validation']['passed'] ?? null ), 'BV.1: an em dash in the payload never REFUSES (evidence, not a verdict)' );
ok( in_array( 'brand_voice', $r['gates']['validation']['checks'] ?? array(), true ), 'BV.2: the brand_voice check ran against the payload\'s text' );
$em_finding = null;
foreach ( ( $r['gates']['validation']['findings'] ?? array() ) as $f ) {
	if ( 'em_dash_count' === ( $f['check'] ?? '' ) ) { $em_finding = $f; }
}
ok( is_array( $em_finding ) && 'info' === ( $em_finding['severity'] ?? '' ), 'BV.3: the em-dash count surfaces as a severity-info finding' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
