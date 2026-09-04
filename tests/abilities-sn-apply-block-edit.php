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
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
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
$GLOBALS['__clobber_schedule']   = 0; // counter: each wp_update_post while > 0 early-publishes the row (drives the guard's red path; 2 defeats the restore too)

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
		// REAL core behavior (wp-includes/post.php, wp_insert_post's status
		// resolution): an explicitly-passed 'future' whose post_date_gmt is
		// within a minute of now silently resolves to 'publish'. Modeled
		// faithfully so the suite can see the failure class the adversarial
		// review named (an overdue scheduled post early-publishing — and the
		// restore being coerced by the same path).
		if ( 'future' === (string) ( $args['post_status'] ?? '' ) ) {
			$gmt = (string) ( $args['post_date_gmt'] ?? ( $GLOBALS['__posts'][ $id ]['post_date_gmt'] ?? '' ) );
			if ( '' !== $gmt && ( strtotime( $gmt ) - time() ) < 60 ) {
				$GLOBALS['__posts'][ $id ]['post_status'] = 'publish';
			}
		}
		// Real core: a DRAFT's post_date FLOATS to "now" on save (a draft's
		// date is last-touched, not a schedule) — the v13.5.0 guard-fix
		// driver, found live on a scratch draft. +8s keeps it deterministic.
		if ( 'draft' === (string) ( $args['post_status'] ?? '' ) ) {
			$GLOBALS['__posts'][ $id ]['post_date'] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $GLOBALS['__posts'][ $id ]['post_date'] ) + 8 );
		}
		// Counted early-publish clobber: models core deciding on its own that
		// this row should go live NOW (the exact disaster the SUT's schedule
		// guard exists to catch). A count of 2 defeats the guard's restore
		// attempt too, driving the restore-verification honesty path.
		if ( $GLOBALS['__clobber_schedule'] > 0 ) {
			$GLOBALS['__clobber_schedule']--;
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

$GLOBALS['__registered_blocks'] = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/separator', 'signal-noise/sidenote', 'signal-noise/pull-quote' );
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
require __DIR__ . '/../inc/provenance-core.php'; // v13.4.0: sn_prov_expand_block_text + normalize v2 — the prose delta must SEE dynamic-block attribute text
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
require __DIR__ . '/../inc/sn-apply-plan-changes.php'; // v13.94.0: block_edit_impl now shares its scheduled-post guard from here
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

// v13.4.0 (sn-normalize-v2): DYNAMIC-BLOCK attribute text is prose now.
// The two owner-specified pins: a sidenote insert carrying new text mints
// a version; a pure restructure with no new text still coalesces.
$side_markup = '<!-- wp:signal-noise/sidenote {"content":"A margin note the ledger must see and sign."} /-->';
$r = be_call( 'block_insert', array( 'dry_run' => true, 'change' => array( 'payload' => array( 'blocks' => $side_markup ) ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['gates']['fingerprint']['passed'] ?? null ), 'DELTA.12: a sidenote insert passes the markup gate (registered block, canonical void form)' );
eq( true, $r['diff']['prose_changed'] ?? null, 'DELTA.13 (owner pin): a sidenote carrying NEW TEXT reports prose_changed true — the attribute is prose under sn-normalize-v2' );
eq( 'new_version', $r['diff']['ledger_impact'] ?? null, 'DELTA.14 (owner pin): ...and ledger_impact "new_version" — no more text field the record cannot see' );
// The LCP/LCS trim absorbs text shared with the surroundings (the leading
// "A " matches the next block's "A heading…", the trailing "." its "."),
// so the asserted span is the delta's exact interior, not the full sentence.
ok( false !== strpos( (string) ( $r['diff']['prose_added'] ?? '' ), 'margin note the ledger must see and sign' ), 'DELTA.15: prose_added carries the sidenote\'s words themselves' );

// Pure restructure: the heading becomes a SIDENOTE with IDENTICAL text —
// same words, new housing (the DELTA.1 case with a dynamic block as the
// destination) — and the ledger correctly stays quiet.
$restr_side = '<!-- wp:signal-noise/sidenote {"content":"A heading anchoring the second section of the piece"} /-->';
$r = be_call( 'block_replace', array( 'dry_run' => true, 'change' => array( 'payload' => array( 'anchor' => 'A heading anchoring the second section', 'blocks' => $restr_side ) ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['gates']['fingerprint']['passed'] ?? null ), 'DELTA.16: heading→sidenote restructure passes the gates' );
eq( false, $r['diff']['prose_changed'] ?? null, 'DELTA.17 (owner pin): identical text in a new housing — prose_changed false' );
eq( 'coalesces', $r['diff']['ledger_impact'] ?? null, 'DELTA.18 (owner pin): ...and ledger_impact "coalesces" — restructure-only, no new signed version' );

// mb-safety (adversarial review, MEDIUM): "café"→"cafè" shares the 0xC3
// lead byte, so a byte-wise trim would emit lone continuation bytes that
// wp_json_encode() degrades to "?". The boundary must snap to whole
// characters.
$mb = snt_sn_apply_block_edit_prose_delta( '<p>The word café ends this.</p>', '<p>The word cafè ends this.</p>' );
eq( "\u{00E9}", $mb['prose_removed'], 'DELTA.9: multibyte boundary — prose_removed is the WHOLE character (é), never a lone continuation byte' );
eq( "\u{00E8}", $mb['prose_added'], 'DELTA.10: ...and prose_added likewise (è)' );
ok( false !== json_encode( $mb ), 'DELTA.11: the delta survives json_encode without the invalid-UTF-8 fallback' );

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
$sched_body   = '<!-- wp:paragraph --><p>A scheduled note whose sentence is long enough to act as the anchor here.</p><!-- /wp:paragraph -->';
// Dynamic date: a literal future date is a time bomb (once real time passes
// it, the stub's now-faithful coercion model flips every assertion).
$sched_future = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
tf_post( 910, array( 'post_status' => 'future', 'post_date' => $sched_future, 'post_date_gmt' => $sched_future, 'post_content' => $sched_body ) );
$sched_fp = snt_corpus_content_hash( $sched_body );

$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 910 ), 'mode' => 'publish', 'change' => array( 'fingerprint' => $sched_fp, 'payload' => array( 'anchor' => 'sentence is long enough to act as the anchor' ) ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'SCHED.1: a publish-mode block edit on a scheduled post succeeds' );
eq( 'future', $GLOBALS['__posts'][910]['post_status'], 'SCHED.2: post_status "future" preserved exactly' );
eq( $sched_future, $GLOBALS['__posts'][910]['post_date'], 'SCHED.3: post_date preserved exactly' );
ok( false !== strpos( (string) $GLOBALS['__posts'][910]['post_content'], 'freshly composed paragraph' ), 'SCHED.4: ...and the content edit landed' );

// The guard's red path: prove it can fail before trusting its green.
$sched_fp2 = snt_corpus_content_hash( (string) $GLOBALS['__posts'][910]['post_content'] );
$GLOBALS['__clobber_schedule'] = 1;
$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 910 ), 'mode' => 'publish', 'change' => array( 'fingerprint' => $sched_fp2, 'payload' => array( 'anchor' => 'sentence is long enough to act as the anchor', 'position' => 'before' ) ) ) );
ok( is_wp_error( $r ), 'SCHED.5: a write that early-publishes the row FAILS LOUDLY' );
eq( 'snt_sn_apply_schedule_violation', $r->get_error_code(), 'SCHED.6: ...with the schedule-violation code' );
eq( 500, (int) ( $r->get_error_data()['status'] ?? 0 ), 'SCHED.7: 500 — a tool bug, not a caller error' );
eq( 'future', $GLOBALS['__posts'][910]['post_status'], 'SCHED.8: the restore attempt put the schedule back' );
eq( $sched_future, $GLOBALS['__posts'][910]['post_date'], 'SCHED.9: ...date included' );
$msg = json_decode( $r->get_error_message(), true );
ok( false !== strpos( (string) json_encode( $msg ), 'verified restored' ) || false !== strpos( $r->get_error_message(), 'verified restored' ), 'SCHED.9b: ...and the outcome text says the restore was VERIFIED, not inferred from the return code' );
$GLOBALS['__clobber_schedule'] = 0;

// Restore-verification honesty (adversarial review, HIGH): when the restore
// itself is defeated by the same mechanism (clobber count 2 — core's
// coercion fires on write AND restore), the error must say the post
// REMAINS published, never "succeeded" off a non-WP_Error return code.
$sched_fp3 = snt_corpus_content_hash( (string) $GLOBALS['__posts'][910]['post_content'] );
$GLOBALS['__clobber_schedule'] = 2;
$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 910 ), 'mode' => 'publish', 'change' => array( 'fingerprint' => $sched_fp3, 'payload' => array( 'anchor' => 'sentence is long enough to act as the anchor', 'position' => 'after' ) ) ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_schedule_violation' === $r->get_error_code(), 'SCHED.10: a defeated restore still errors as a schedule violation' );
// The response is wp_json_encode()d, which \u-escapes the em dash — decode
// and read the carried error.message rather than grepping raw JSON bytes.
$rep10   = json_decode( $r->get_error_message(), true );
$msg10   = (string) ( $rep10['error']['message'] ?? '' );
ok( false !== strpos( $msg10, 'the post remains publish' ) && false !== strpos( $msg10, 'FAILED' ), 'SCHED.10b: ...and the message reports the VERIFIED live state (remains publish), never a false "succeeded"' );
eq( 'publish', $GLOBALS['__posts'][910]['post_status'], 'SCHED.10c: (sanity: the store really is in the bad state the message describes)' );
$GLOBALS['__clobber_schedule'] = 0;
// Repair the fixture for anything downstream.
$GLOBALS['__posts'][910]['post_status'] = 'future';
$GLOBALS['__posts'][910]['post_date']   = $sched_future;

// The OVERDUE refusal (adversarial review, HIGH — the prevention half):
// a 'future' post whose post_date_gmt has passed would be silently
// early-published by core's own status resolution on ANY wp_update_post —
// the write AND the restore alike — so the impl refuses UP FRONT, before
// touching the row.
$overdue_body = '<!-- wp:paragraph --><p>An overdue scheduled note whose sentence is long enough to anchor on.</p><!-- /wp:paragraph -->';
$overdue_past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
tf_post( 915, array( 'post_status' => 'future', 'post_date' => $overdue_past, 'post_date_gmt' => $overdue_past, 'post_content' => $overdue_body ) );
$overdue_fp = snt_corpus_content_hash( $overdue_body );
tf_reset_writes();
$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 915 ), 'mode' => 'publish', 'change' => array( 'fingerprint' => $overdue_fp, 'payload' => array( 'anchor' => 'sentence is long enough to anchor on' ) ) ) );
ok( is_wp_error( $r ), 'SCHED.11: a publish-mode edit on an OVERDUE scheduled post refuses up front' );
eq( 'snt_sn_apply_schedule_overdue', $r->get_error_code(), 'SCHED.11b: ...with its own named code' );
eq( 409, (int) ( $r->get_error_data()['status'] ?? 0 ), 'SCHED.11c: 409 — a state conflict, resolved by letting cron publish first' );
eq( 0, $GLOBALS['__write_calls']['wp_update_post'], 'SCHED.11d: the live row was never written (core\'s coercion never got a chance)' );
eq( 'future', $GLOBALS['__posts'][915]['post_status'], 'SCHED.11e: the overdue post is untouched' );

// Revision mode on the same overdue post still WORKS — staging never
// touches the live row, so there is nothing for core to coerce.
tf_reset_writes();
$r = be_call( 'block_insert', array( 'target' => array( 'post_id' => 915 ), 'mode' => 'revision', 'change' => array( 'fingerprint' => $overdue_fp, 'payload' => array( 'anchor' => 'sentence is long enough to anchor on' ) ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'SCHED.12: revision mode on the overdue post still stages fine' );
eq( 'future', $GLOBALS['__posts'][915]['post_status'], 'SCHED.12b: ...live row untouched, schedule intact' );

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

/* ════════════════════════════════════════════════════════════════════════
 * v13.5.0 — the non-anchor locator (block_path) + block_delete + block_move.
 * Fixture: p1, sidenote, p2 as REAL markup — parse indices 0=p1, 1=ws,
 * 2=sidenote, 3=ws, 4=p2 (whitespace separators COUNT, block_migrations'
 * own enumeration).
 * ════════════════════════════════════════════════════════════════════════ */
$bp_p1   = '<!-- wp:paragraph --><p>Opening paragraph of the locator fixture, long enough to anchor on.</p><!-- /wp:paragraph -->';
$bp_side = '<!-- wp:signal-noise/sidenote {"content":"The original margin note, reachable only by path."} /-->';
$bp_p2   = '<!-- wp:paragraph --><p>Closing paragraph of the locator fixture, long enough to anchor on.</p><!-- /wp:paragraph -->';
$bp_body = $bp_p1 . "\n\n" . $bp_side . "\n\n" . $bp_p2;
tf_post( 940, array( 'post_content' => $bp_body ) );
$bp_fp = snt_corpus_content_hash( $bp_body );

function bp_call( $type, $payload, $post_id = 940, $fp = null, $dry = true ) {
	$fp = null === $fp ? $GLOBALS['__bp_fp_current'] : $fp;
	return snt_ability_sn_apply( array(
		'target'  => array( 'post_id' => $post_id ),
		'mode'    => 'revision',
		'dry_run' => $dry,
		'change'  => array( 'type' => $type, 'fingerprint' => $fp, 'payload' => $payload ),
	) );
}
$GLOBALS['__bp_fp_current'] = $bp_fp;
tf_reset_writes();

// ── OWNER TEST 1: reword a sidenote IN PLACE (write-once no more) ──
$bp_new_side = '<!-- wp:signal-noise/sidenote {"content":"The reworded margin note, corrected through the tool that signs it."} /-->';
$r = bp_call( 'block_replace', array( 'block_path' => '0/2', 'blocks' => $bp_new_side ) );
ok( ! is_wp_error( $r ) && true === ( $r['gates']['fingerprint']['passed'] ?? null ), 'PATH.1 (owner): a sidenote reword by block_path passes all gates — no visible text needed' );
eq( $bp_p1 . "\n\n" . $bp_new_side . "\n\n" . $bp_p2, $r['diff']['after'] ?? null, 'PATH.2: the splice replaces exactly the pathed block' );
eq( $bp_side, $r['diff']['replaced_block'] ?? null, 'PATH.3: replaced_block reports the old sidenote\'s serialized form' );
eq( true, $r['diff']['prose_changed'] ?? null, 'PATH.4: the reword is a prose change (the attribute text signs since v13.4.0)' );
eq( 'new_version', $r['diff']['ledger_impact'] ?? null, 'PATH.5: ...and mints a version — corrected through the tool that signs it' );

// ── OWNER TEST 2: move the sidenote up one position (a SINGLE call) ──
$r = bp_call( 'block_move', array( 'block_path' => '0/2', 'position' => 'before', 'to_block_path' => '0/0' ) );
ok( ! is_wp_error( $r ) && true === ( $r['gates']['fingerprint']['passed'] ?? null ), 'MOVE.1 (owner): move-up-one is ONE call through all four gates — never two replaces that can strand mid-swap' );
eq( $bp_side . "\n\n" . $bp_p1 . "\n\n" . $bp_p2, $r['diff']['after'] ?? null, 'MOVE.2: the sidenote now precedes the opening paragraph; separators stay canonical' );
eq( $bp_side, $r['diff']['moved_block'] ?? null, 'MOVE.3: moved_block reports the block\'s serialized form' );
eq( true, $r['diff']['prose_changed'] ?? null, 'MOVE.4: a move REORDERS prose — the ledger honestly mints a version' );
eq( 0, tf_total_writes(), 'MOVE.5: dry runs wrote nothing' );

// Destination by ANCHOR (the destination block has visible text).
$r = bp_call( 'block_move', array( 'block_path' => '0/2', 'position' => 'after', 'anchor' => 'Closing paragraph of the locator fixture' ) );
ok( ! is_wp_error( $r ) && ( $bp_p1 . "\n\n" . $bp_p2 . "\n\n" . $bp_side ) === ( $r['diff']['after'] ?? null ), 'MOVE.6: destination may be an anchor into a text-bearing block' );

// A no-op move refuses (the sidenote already sits after p1).
$r = bp_call( 'block_move', array( 'block_path' => '0/2', 'position' => 'after', 'to_block_path' => '0/0' ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_move_noop' === $r->get_error_code(), 'MOVE.7: a move to where the block already sits refuses as a no-op' );
$r = bp_call( 'block_move', array( 'block_path' => '0/2', 'position' => 'before', 'to_block_path' => '0/2' ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_move_source_is_destination' === $r->get_error_code(), 'MOVE.8: source == destination refuses by name' );

// ── OWNER TEST 3: a stale path FAILS rather than mutating a neighbour ──
// The guarantee is the fingerprint: a path minted against yesterday's
// content arrives with yesterday's content_hash, and gate 1 409s BEFORE
// the path is ever dereferenced.
$bp_mut = '<!-- wp:paragraph --><p>A concurrently prepended paragraph that shifts every index down.</p><!-- /wp:paragraph -->' . "\n\n" . $bp_body;
$GLOBALS['__posts'][940]['post_content'] = $bp_mut; // concurrent edit lands
tf_reset_writes();
$bp_snap = $GLOBALS['__posts'];
$r = bp_call( 'block_replace', array( 'block_path' => '0/2', 'blocks' => $bp_new_side ), 940, $bp_fp, false ); // OLD fingerprint, OLD path, REAL write requested
ok( is_wp_error( $r ) && 'snt_sn_apply_fingerprint_stale' === $r->get_error_code(), 'STALEPATH.1 (owner): the stale view 409s at gate 1 — the path is never dereferenced' );
eq( 0, tf_total_writes(), 'STALEPATH.2: zero writes' );
eq( $bp_snap, $GLOBALS['__posts'], 'STALEPATH.3: no neighbour mutated — store byte-identical' );

// Under a FRESH hash, a path that misses is caller arithmetic, named:
$bp_fp2 = snt_corpus_content_hash( $bp_mut );
$GLOBALS['__bp_fp_current'] = $bp_fp2;
$r = bp_call( 'block_replace', array( 'block_path' => '0/99', 'blocks' => $bp_new_side ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_block_path_out_of_range' === $r->get_error_code(), 'STALEPATH.4: out-of-range under a fresh hash refuses naming the node count' );
$r = bp_call( 'block_replace', array( 'block_path' => '0/1', 'blocks' => $bp_new_side ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_block_path_not_a_block' === $r->get_error_code(), 'STALEPATH.5: a whitespace-separator index refuses naming what sits there' );
$r = bp_call( 'block_replace', array( 'block_path' => '0/1/innerBlocks/0', 'blocks' => $bp_new_side ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_block_path_not_top_level' === $r->get_error_code(), 'STALEPATH.6: nested paths refuse — this family splices top-level blocks only' );
$r = bp_call( 'block_replace', array( 'block_path' => '2', 'blocks' => $bp_new_side ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_bad_block_path' === $r->get_error_code(), 'STALEPATH.7: a path without the 0 seed refuses — the syntax is block_migrations\' exactly' );

// ── Exactly one locator, never a silent precedence rule ──
$r = bp_call( 'block_replace', array( 'block_path' => '0/4', 'anchor' => 'Closing paragraph of the locator fixture', 'blocks' => $bp_new_side ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_locator_conflict' === $r->get_error_code(), 'LOC.1: anchor AND block_path together refuse by name' );
$r = bp_call( 'block_replace', array( 'blocks' => $bp_new_side ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_locator_required' === $r->get_error_code(), 'LOC.2: neither locator refuses by name — and the message teaches block_path' );

// ── block_delete ──
$r = bp_call( 'block_delete', array( 'block_path' => '0/4' ) ); // the ORIGINAL sidenote, shifted by the prepend (0=new,1=ws,2=p1,3=ws,4=side)
ok( ! is_wp_error( $r ) && true === ( $r['gates']['fingerprint']['passed'] ?? null ), 'DEL.1: delete by path passes the gates' );
ok( false === strpos( (string) ( $r['diff']['after'] ?? '' ), 'wp:signal-noise/sidenote' ), 'DEL.2: the sidenote is gone from the preview' );
eq( $bp_side, $r['diff']['removed_block'] ?? null, 'DEL.3: removed_block reports the deleted block\'s serialized form (the block_replace convention)' );
ok( false === strpos( (string) ( $r['diff']['after'] ?? '' ), "\n\n\n" ), 'DEL.4: one adjacent separator went with it — no tripled newlines left behind' );
eq( true, $r['diff']['prose_changed'] ?? null, 'DEL.5: deleting signed text is a prose change' );
$r = bp_call( 'block_delete', array( 'anchor' => 'Closing paragraph of the locator fixture' ) );
ok( ! is_wp_error( $r ) && false === strpos( (string) ( $r['diff']['after'] ?? '' ), 'Closing paragraph' ), 'DEL.6: delete locates by anchor too (exactly one locator, either kind)' );
$r = bp_call( 'block_delete', array( 'block_path' => '0/2', 'blocks' => $bp_new_side ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_blocks_not_accepted' === $r->get_error_code(), 'DEL.7: payload.blocks on a delete refuses — a reword is block_replace' );

// Refuse to empty a post.
$bp_solo = '<!-- wp:paragraph --><p>The only block this post has, long enough to anchor on.</p><!-- /wp:paragraph -->';
tf_post( 945, array( 'post_content' => $bp_solo ) );
$r = bp_call( 'block_delete', array( 'block_path' => '0/0' ), 945, snt_corpus_content_hash( $bp_solo ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_delete_would_empty' === $r->get_error_code(), 'DEL.8: deleting the only block refuses — an empty post is never a block edit\'s intent' );

// ── Review-round pins: trailing whitespace never stacks separators; a
// context_snippet with a path refuses (no silent no-op inputs).
$bp_trail = $bp_p1 . "\n\n" . $bp_p2 . "\n";
$computed = snt_sn_apply_block_edit_compute_move( $bp_trail, array( 'block_path' => '0/0', 'position' => 'end' ) );
eq( $bp_p2 . "\n\n" . $bp_p1, $computed['new_content'] ?? null, 'TRAIL.1: move-to-end over trailing whitespace stays canonical — no \n\n\n run' );
$computed = snt_sn_apply_block_edit_compute( $bp_trail, 'block_insert', array( 'blocks' => $bp_side, 'position' => 'end' ) );
eq( $bp_p1 . "\n\n" . $bp_p2 . "\n\n" . $bp_side, $computed['new_content'] ?? null, 'TRAIL.2: insert-at-end likewise rtrims before appending' );
$r = bp_call( 'block_replace', array( 'block_path' => '0/0', 'context_snippet' => 'anything at all here', 'blocks' => $bp_new_side ) );
ok( is_wp_error( $r ) && 'snt_sn_apply_locator_conflict' === $r->get_error_code(), 'TRAIL.3: context_snippet alongside block_path refuses by name — a path is already exact' );

// ── The guard fix, red-provable: a DRAFT's floating post_date no longer
// trips the schedule violation (found LIVE on a scratch draft: the write
// landed, then a false 500 said restore-manually-NOW over core behavior).
$bp_draft = $bp_p1 . "\n\n" . $bp_p2;
tf_post( 950, array( 'post_status' => 'draft', 'post_content' => $bp_draft ) );
tf_reset_writes();
$r = snt_ability_sn_apply( array(
	'target'  => array( 'post_id' => 950 ),
	'mode'    => 'publish',
	'dry_run' => false,
	'change'  => array( 'type' => 'block_insert', 'fingerprint' => snt_corpus_content_hash( $bp_draft ), 'payload' => array( 'blocks' => $bp_side, 'anchor' => 'Opening paragraph of the locator fixture', 'position' => 'after' ) ),
) );
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'DRAFT.1 (guard fix): a draft edit SUCCEEDS while core floats its post_date — dates bind strictly for status future only' );
eq( 'draft', $GLOBALS['__posts'][950]['post_status'], 'DRAFT.2: the status assertion still holds for every status — a draft stays a draft' );
ok( false !== strpos( (string) $GLOBALS['__posts'][950]['post_content'], 'wp:signal-noise/sidenote' ), 'DRAFT.3: the edit landed' );


/* ════════════════════════════════════════════════════════════════════════
 * v13.94.0 — change.type "batch": THE CLAIM IS THE WRITE COUNT
 *
 * The batch is worth building only if N changes cost ONE wp_update_post(),
 * because the provenance ledger mints a version per write. Asserting that
 * the content came out right would pass just as well on three writes, which
 * is the bug. So this measures the WRITES, and pins the contrast against the
 * unbatched path in the same breath.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nBATCH: one editorial act, one write\n";

$sr_sentence = 'First paragraph with the opening thoughts of the piece, long enough to anchor on.';
$batch_changes = array(
	array( 'type' => 'sentence_replace', 'payload' => array( 'phrase' => $sr_sentence, 'replacement' => 'First paragraph, with its figure corrected to sixty-eight percent exactly.' ) ),
	array( 'type' => 'block_insert', 'payload' => array( 'anchor' => $anchor1, 'position' => 'after', 'blocks' => $good_blocks ) ),
	array( 'type' => 'block_insert', 'payload' => array( 'position' => 'end', 'blocks' => '<!-- wp:paragraph --><p>A correction notice appended at the very end of the note.</p><!-- /wp:paragraph -->' ) ),
);

$GLOBALS['__posts'][900]['post_content'] = $body;
tf_reset_writes();
$r = be_call( 'batch', array( 'mode' => 'publish', 'change' => array( 'payload' => array( 'blocks' => '__unset', 'anchor' => '__unset', 'changes' => $batch_changes ) ) ) );
ok( empty( $r['error'] ), 'BATCH.1: a three-change batch executes (' . ( $r['error'] ?? 'ok' ) . ')' );
eq( 1, $GLOBALS['__write_calls']['wp_update_post'], 'BATCH.2: THE CLAIM — three changes cost exactly ONE wp_update_post(), so the ledger mints ONE version' );

$live = $GLOBALS['__posts'][900]['post_content'];
ok( false !== strpos( $live, 'sixty-eight percent' ), 'BATCH.3: the prose change landed' );
ok( false === strpos( $live, $sr_sentence ), 'BATCH.4: ...replacing the original sentence' );
ok( false !== strpos( $live, 'freshly composed paragraph' ), 'BATCH.5: the mid-post insert landed' );
ok( false !== strpos( $live, 'correction notice appended' ), 'BATCH.6: the end insert landed' );
ok( 1 === substr_count( $live, 'correction notice appended' ), 'BATCH.7: ...exactly once (no double splice)' );

// THE CONTRAST, measured rather than asserted: the same three edits as three
// separate calls cost three writes. This is the number the batch removes.
$GLOBALS['__posts'][900]['post_content'] = $body;
tf_reset_writes();
$fp_now = snt_corpus_content_hash( $GLOBALS['__posts'][900]['post_content'] );
be_call( 'block_insert', array( 'mode' => 'publish', 'change' => array( 'fingerprint' => $fp_now, 'payload' => array( 'anchor' => $anchor1, 'position' => 'after', 'blocks' => $good_blocks ) ) ) );
$fp_now = snt_corpus_content_hash( $GLOBALS['__posts'][900]['post_content'] );
be_call( 'block_insert', array( 'mode' => 'publish', 'change' => array( 'fingerprint' => $fp_now, 'payload' => array( 'anchor' => $anchor2, 'position' => 'after', 'blocks' => $good_blocks ) ) ) );
eq( 2, $GLOBALS['__write_calls']['wp_update_post'], 'BATCH.8: THE CONTRAST — the same edits unbatched cost one write EACH (two here), which is two ledger versions' );

// A conflicting batch must write NOTHING: all-or-nothing at the door.
$GLOBALS['__posts'][900]['post_content'] = $body;
$fp_now = snt_corpus_content_hash( $body );
tf_reset_writes();
$r = be_call( 'batch', array( 'mode' => 'publish', 'change' => array( 'fingerprint' => $fp_now, 'payload' => array( 'blocks' => '__unset', 'anchor' => '__unset', 'changes' => array(
	array( 'type' => 'block_insert', 'payload' => array( 'anchor' => $anchor1, 'position' => 'after', 'blocks' => $good_blocks ) ),
	array( 'type' => 'block_insert', 'payload' => array( 'anchor' => $anchor1, 'position' => 'after', 'blocks' => $good_blocks ) ),
) ) ) ) );
eq( 'snt_sn_apply_changes_conflict', $r->get_error_code(), 'BATCH.9: a conflicting batch refuses as the named conflict, like every other refusal in this family' );
eq( 0, tf_total_writes(), 'BATCH.10: ...and writes NOTHING — all-or-nothing holds at the door, not after a partial splice' );
eq( $body, $GLOBALS['__posts'][900]['post_content'], 'BATCH.11: the live row is byte-identical to before the refused batch' );

/* ── v13.95.1: THE SHAPE OF THE REFUSAL, not just its code ──────────────
   Found by driving the live door, not by the suite: the refusal envelope
   said fingerprint.passed:false with expected and observed IDENTICAL, and
   carried a diff reporting changes_applied:2 with ledger_impact
   "coalesces" — the reading that means "applied, no new version". Nothing
   was ever written; the READOUT could not be told apart from a benign
   restructure. Both are pinned here. */
$env = json_decode( $r->get_error_message(), true );

eq( 'snt_sn_apply_changes_conflict', $r->get_error_code(), 'BATCH.12: the refusal still carries the conflict code' );
eq( true, $env['gates']['fingerprint']['passed'] ?? null, 'BATCH.13: the FINGERPRINT gate passes — the hash matched; only the plan was refused' );
eq(
	$env['gates']['fingerprint']['expected'] ?? 'x',
	$env['gates']['fingerprint']['observed'] ?? 'y',
	'BATCH.14: ...and expected === observed, which is exactly why reporting it as failed was a contradiction'
);
eq( false, $env['gates']['validation']['passed'] ?? null, 'BATCH.15: the VALIDATION gate carries the refusal — a payload conflict is a validation failure' );
ok( in_array( 'plan', (array) ( $env['gates']['validation']['checks'] ?? array() ), true ), 'BATCH.16: ...and names a "plan" check' );
$named = false;
foreach ( (array) ( $env['gates']['validation']['findings'] ?? array() ) as $f ) {
	if ( 'plan' === ( $f['surface'] ?? '' ) && 'error' === ( $f['severity'] ?? '' ) && false !== strpos( (string) ( $f['message'] ?? '' ), 'undefined' ) ) { $named = true; }
}
ok( $named, 'BATCH.17: ...with a severity-error finding carrying the planner reason' );

eq( 0, $env['diff']['changes_applied'] ?? -1, 'BATCH.18: diff.changes_applied is ZERO — a refused batch applied nothing' );
eq( 2, $env['diff']['changes_requested'] ?? -1, 'BATCH.19: ...while changes_requested names what was ASKED for, kept distinct' );
ok( array_key_exists( 'ledger_impact', (array) ( $env['diff'] ?? array() ) ) && null === $env['diff']['ledger_impact'], 'BATCH.20: ledger_impact is NULL, never "coalesces" — there is no plan, so no ledger consequence to report' );
ok( array_key_exists( 'after', (array) ( $env['diff'] ?? array() ) ) && null === $env['diff']['after'], 'BATCH.21: diff.after is NULL — a refused plan has no resulting content to show' );
ok( ! empty( $env['diff']['before'] ), 'BATCH.22: diff.before is still carried (roadmap_board reads it from a refusal to bootstrap its fingerprint)' );


echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
