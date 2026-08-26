<?php
/**
 * Standalone tests for sn_scan (MCP consolidation session 4, v10.29.0):
 * signal-noise/sn-scan. Absorbs block-migrations-scan, pattern-adoption-scan,
 * duplicate-body-scan, near-duplicate-scan, link-candidates, plus a new
 * orphan_media source — every absorbed ability stays live (verified
 * separately by tests/mcp-capabilities.php).
 *
 * This is an INTEGRATION suite: every adapter calls the REAL underlying
 * detector/pipeline function (never a re-implementation), so the fixtures
 * below stub the real WP surface those detectors touch (get_posts,
 * parse_blocks/serialize_block, the ML kernel's corpus walk, and — for
 * orphan_media only — a $wpdb stub modeling the exact query shapes
 * inc/health-check-orphaned-media.php and this file's sizing query use,
 * same substring-corpus pattern as tests/health-orphan-detection.php).
 *
 * Acceptance tests 1-5 from ~/.claude/session-data/SN-MCP-new/sn-scan-spec.md
 * are each pinned explicitly below (search "ACCEPTANCE TEST").
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A', 'ARRAY_A' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

/* ════════════════════════════════════════════════════════════════════════
 * WP stubs (BEFORE the SUT loads)
 * ════════════════════════════════════════════════════════════════════════ */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_data( $key = '' ) { return $this->data; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) {
		foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); }
		return $v;
	}
}

// Fixture post factory — WP_Post field names, real shapes. $content is a
// JSON-encoded parse_blocks() tree (mirrors tests/block-migrations-detect.php's
// convention) so the SAME string doubles as post_content AND the parse_blocks
// stub's input.
function tf_post( $id, $status, $content, $extra = array() ) {
	$p = new stdClass();
	$p->ID            = $id;
	$p->post_title    = $extra['title'] ?? "Post $id";
	$p->post_name     = $extra['slug'] ?? "post-$id";
	$p->post_status   = $status;
	$p->post_type     = $extra['post_type'] ?? 'post';
	$p->post_date     = $extra['date'] ?? '2026-06-01 10:00:00';
	$p->post_modified = $extra['modified'] ?? '2026-07-01 10:00:00';
	$p->post_content  = $content;
	$p->post_excerpt  = '';
	return $p;
}

$GLOBALS['__posts'] = array();
// Nondeterminism injection for the duplicate_body ordering regression
// (adversarial review MEDIUM 1): when set, these two post IDs (same
// post_date, the bulk-import case) SWAP relative position on every OTHER
// 'any'-status corpus walk — modeling exactly what an unindexed
// `ORDER BY post_date DESC` with no tie-break can do across two otherwise-
// identical queries. Unset (null) by default: every other fixture in this
// file gets the DB's real (stable-per-run) order, this flip is opt-in.
$GLOBALS['__dup_flip_ids']  = null;
$GLOBALS['__dup_flip_call'] = 0;

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$out = array();
		foreach ( $GLOBALS['__posts'] as $p ) {
			if ( $p->post_type !== ( $args['post_type'] ?? 'post' ) ) { continue; }
			if ( ! in_array( $p->post_status, (array) ( $args['post_status'] ?? array( 'publish' ) ), true ) ) { continue; }
			// v13.2.0: the pattern_adoption adapter now scopes IN the query —
			// the stub must honor post__in or the scope pins pass vacuously.
			if ( isset( $args['post__in'] ) && ! in_array( (int) $p->ID, array_map( 'intval', (array) $args['post__in'] ), true ) ) { continue; }
			$out[] = $p;
		}
		$cap = (int) ( $args['posts_per_page'] ?? -1 );
		$out = $cap > 0 ? array_slice( $out, 0, $cap ) : $out;
		if ( 'ids' === ( $args['fields'] ?? '' ) ) {
			return array_map( static function ( $p ) { return $p->ID; }, $out );
		}
		if ( is_array( $GLOBALS['__dup_flip_ids'] ) && 2 === count( $GLOBALS['__dup_flip_ids'] ) ) {
			$present = array_values( array_filter( $out, static function ( $p ) {
				return in_array( $p->ID, $GLOBALS['__dup_flip_ids'], true );
			} ) );
			if ( 2 === count( $present ) ) {
				$GLOBALS['__dup_flip_call']++;
				if ( 0 === $GLOBALS['__dup_flip_call'] % 2 ) {
					$idxs = array();
					foreach ( $out as $i => $p ) {
						if ( in_array( $p->ID, $GLOBALS['__dup_flip_ids'], true ) ) { $idxs[] = $i; }
					}
					$tmp              = $out[ $idxs[0] ];
					$out[ $idxs[0] ]  = $out[ $idxs[1] ];
					$out[ $idxs[1] ]  = $tmp;
				}
			}
		}
		return $out;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) { $p = $GLOBALS['__posts'][ (int) $id ] ?? null; return $p ? $p->post_status : false; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) {
		$p = $GLOBALS['__posts'][ (int) $id ] ?? null;
		return $p ? 'https://example.test/notes/' . $p->post_name . '/' : false;
	}
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page' ), true ); }
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $t ) {
		if ( ! post_type_exists( $t ) ) { return null; }
		$o = new stdClass(); $o->public = true; return $o;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) { function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); } }

// Block-migrations / pattern-adoption: content is a JSON parse_blocks() tree.
if ( ! function_exists( 'parse_blocks' ) ) {
	// Handles two shapes: a full post_content string (JSON array of blocks —
	// the detector-walk convention, e.g. json_encode(array($block1,$block2)))
	// and a SINGLE block's own markup (JSON object — what real serialize_block()
	// produces for one node, the suggest-impl-output convention). Real WP's
	// parse_blocks() always returns array<block>; a single-block markup string
	// parses to a ONE-element array, so a decoded object carrying its own
	// 'blockName' key (rather than being a list keyed 0..n) is wrapped.
	function parse_blocks( $content ) {
		$d = json_decode( (string) $content, true );
		if ( ! is_array( $d ) ) { return array(); }
		return array_key_exists( 'blockName', $d ) ? array( $d ) : $d;
	}
}
if ( ! function_exists( 'serialize_block' ) ) { function serialize_block( $block ) { return json_encode( $block ); } }
if ( ! function_exists( 'serialize_blocks' ) ) { function serialize_blocks( $tree ) { return json_encode( $tree ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $html ) { return $html; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap, $post_id = null ) { return true; } }
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) {
		$id = (int) ( $args['ID'] ?? 0 );
		if ( isset( $GLOBALS['__posts'][ $id ] ) ) { $GLOBALS['__posts'][ $id ]->post_content = $args['post_content']; }
		return $id;
	}
}
// Dismiss meta store (block-migrations / pattern-adoption).
$GLOBALS['__post_meta'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		// Real WP semantics: single=true returns the stored value AS-IS
		// (whatever shape it was update_post_meta()'d with — a scalar, or
		// an array the caller stored deliberately); single=false wraps it
		// as the one-row list our simplified store models (no multi-add
		// support needed here). Absent key: '' for single, array() for multi.
		if ( ! array_key_exists( $key, $GLOBALS['__post_meta'][ (int) $id ] ?? array() ) ) {
			return $single ? '' : array();
		}
		$val = $GLOBALS['__post_meta'][ (int) $id ][ $key ];
		return $single ? $val : array( $val );
	}
}
// Write-call RECORDER (adversarial review, v10.29.0): the guard test asserts
// ZERO writes across all six scan_type calls, structurally — not by
// re-reading state, but by counting every call into a write PRIMITIVE
// (set_transient/update_option/update_post_meta/$wpdb->insert-or-similar).
$GLOBALS['__write_calls'] = array( 'set_transient' => 0, 'update_option' => 0, 'update_post_meta' => 0, 'wpdb_write' => 0 );
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['__write_calls']['update_post_meta']++;
		$GLOBALS['__post_meta'][ (int) $id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
$GLOBALS['__transients'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $t ) {
		$GLOBALS['__write_calls']['set_transient']++;
		$GLOBALS['__transients'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; } }

// ML kernel / artifacts collaborators (near_duplicate + link_candidates).
// Post meta rides the SAME $GLOBALS['__post_meta'] store declared above.
$GLOBALS['__options'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $a = null ) {
		$GLOBALS['__write_calls']['update_option']++;
		$GLOBALS['__options'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook ) { return false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $ts, $hook ) { return true; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $ts, $r, $hook ) { return true; } }
if ( ! function_exists( 'wp_is_post_revision' ) ) { function wp_is_post_revision( $id ) { return false; } }
if ( ! function_exists( 'wp_is_post_autosave' ) ) { function wp_is_post_autosave( $id ) { return false; } }

// orphan_media: substring-corpus $wpdb, same pattern as
// tests/health-orphan-detection.php, extended with the sizing COUNT query
// this session's adapter adds and the scope resolver's modified_since query.
$GLOBALS['__attachments'] = array(); // get_results() rows: ID, post_title, guid, post_date_gmt
$GLOBALS['__featured']    = array(); // _thumbnail_id meta values
$GLOBALS['__post_bodies'] = array(); // post_content strings searched by LIKE
$GLOBALS['__meta_values'] = array(); // postmeta values searched by LIKE
$GLOBALS['__theme_mods']  = array();
// health-check-orphaned-media.php is loaded standalone (not via the full
// inc/health-checks.php orchestrator, which owns this shared helper) —
// mirrors its real shape: array{count,findings,label,fix_hint}.
if ( ! function_exists( 'sn_health_pack_check' ) ) {
	function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
		return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint );
	}
}

class SN_Test_Wpdb_Scan {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix = 'wp_';
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $a ) . "'", $sql, 1 );
		}
		return str_replace( '%%', '%', $sql );
	}
	private function last_quoted( $sql ) {
		preg_match_all( "/'([^']*)'/", $sql, $m );
		return $m[1] ? end( $m[1] ) : '';
	}
	// Write primitives — orphan_media's real queries never call these (it's a
	// pure-read detector), but the zero-writes guard test needs SOMETHING to
	// assert against structurally rather than trusting "no method exists".
	public function insert( $table, $data, $format = null ) { $GLOBALS['__write_calls']['wpdb_write']++; return 1; }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { $GLOBALS['__write_calls']['wpdb_write']++; return 1; }
	public function query( $sql ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql ) ) { $GLOBALS['__write_calls']['wpdb_write']++; }
		return 0;
	}
	public function get_results( $sql, $output = null ) {
		$cutoff = $this->last_quoted( $sql );
		return array_values( array_filter( $GLOBALS['__attachments'], static function ( $a ) use ( $cutoff ) {
			return $a['post_date_gmt'] < $cutoff;
		} ) );
	}
	public function get_col( $sql ) {
		if ( false !== strpos( $sql, '_thumbnail_id' ) ) {
			return $GLOBALS['__featured'];
		}
		// The scope resolver's modified_since query: post_date_gmt >= cutoff.
		$cutoff = $this->last_quoted( $sql );
		return array_values( array_map( static function ( $a ) { return $a['ID']; }, array_filter( $GLOBALS['__attachments'], static function ( $a ) use ( $cutoff ) {
			return $a['post_date_gmt'] >= $cutoff;
		} ) ) );
	}
	public function get_var( $sql ) {
		if ( false !== strpos( $sql, "post_mime_type LIKE 'image/%'" ) ) {
			// The sizing COUNT(*) query (health check's own or this adapter's).
			$cutoff = $this->last_quoted( $sql );
			return count( array_filter( $GLOBALS['__attachments'], static function ( $a ) use ( $cutoff ) {
				return $a['post_date_gmt'] < $cutoff;
			} ) );
		}
		// A referenced-check substring-existence query (block_ref/in_body/in_meta).
		$needle = trim( $this->last_quoted( $sql ), '%' );
		if ( '' === $needle ) { return 0; }
		$corpus = ( false !== strpos( $sql, 'postmeta' ) ) ? $GLOBALS['__meta_values'] : $GLOBALS['__post_bodies'];
		foreach ( $corpus as $hay ) {
			if ( false !== strpos( (string) $hay, $needle ) ) { return 1; }
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new SN_Test_Wpdb_Scan();
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( (string) $p ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) { function wp_get_attachment_metadata( $id ) { return false; } }
if ( ! function_exists( 'get_theme_mod' ) ) { function get_theme_mod( $n, $d = false ) { return $GLOBALS['__theme_mods'][ $n ] ?? $d; } }

/* ════════════════════════════════════════════════════════════════════════
 * Load the SUT
 * ════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../inc/block-fingerprint-engine.php';
require __DIR__ . '/../inc/block-migrations-detect.php';
require __DIR__ . '/../inc/block-migrations-suggest.php';
require __DIR__ . '/../inc/block-migrations-apply.php';
require __DIR__ . '/../inc/pattern-adoption-detect.php';
require __DIR__ . '/../inc/pattern-adoption-suggest.php';
require __DIR__ . '/../inc/pattern-adoption-apply.php';
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-pipelines.php';
require __DIR__ . '/../inc/ml-artifacts.php';
require __DIR__ . '/../inc/ml-cousins.php';
require __DIR__ . '/../inc/ml-candidates.php';
// SNT_PATH not defined in this fixture; health-check-orphaned-media.php has
// no dependency on it, load directly (mirrors tests/health-orphan-detection.php
// requiring health-checks.php, which in turn requires this file).
require __DIR__ . '/../inc/health-check-orphaned-media.php';
require __DIR__ . '/../inc/sn-scan-adapters.php';
require __DIR__ . '/../inc/abilities-sn-scan.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "sn_scan (consolidated, MCP consolidation session 4) — plugin v10.29.0\n\n";

/* ════════════════════════════════════════════════════════════════════════
 * Fixtures: block_migrations + pattern_adoption
 * ════════════════════════════════════════════════════════════════════════ */

$h3_no_h2 = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Subsection</h3>', 'innerContent' => array( '<h3>Subsection</h3>' ) );
$GLOBALS['__posts'][301] = tf_post( 301, 'publish', json_encode( array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Intro.</p>' ),
	$h3_no_h2,
) ), array( 'title' => 'Skip post', 'slug' => 'skip-post' ) );

$quote_block = array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<blockquote>Quoted.</blockquote>' );
$GLOBALS['__posts'][302] = tf_post( 302, 'publish', json_encode( array( $quote_block ) ), array( 'title' => 'Quote post', 'slug' => 'quote-post' ) );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 1: unchanged content, two runs -> byte-identical candidates
 * ════════════════════════════════════════════════════════════════════════ */
$r1 = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations' ) );
$r2 = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations' ) );
ok( is_array( $r1 ) && 1 === count( $r1['candidates'] ), 'block_migrations finds the one h3-no-h2 candidate' );
ok( json_encode( $r1['candidates'] ) === json_encode( $r2['candidates'] ), 'ACCEPTANCE TEST 1: two runs against unchanged content -> byte-identical candidates array' );
ok( $r1['corpus_state']['corpus_fingerprint'] === $r2['corpus_state']['corpus_fingerprint'], 'ACCEPTANCE TEST 1: corpus_fingerprint is byte-identical across runs' );
ok( $r1['scan_run_id'] === $r2['scan_run_id'], 'ACCEPTANCE TEST 1: scan_run_id is byte-identical across runs (content-derived, never time/random)' );
ok( 64 === strlen( $r1['candidates'][0]['candidate_id'] ) && ctype_xdigit( $r1['candidates'][0]['candidate_id'] ), 'candidate_id is a 64-char hex sha256' );
ok( false === $r1['candidates'][0]['dismissed'], 'a fresh candidate is not dismissed' );
ok( 'fresh' === $r1['freshness'], 'block_migrations always reports freshness=fresh (no cache to read from)' );
// block_path is load-bearing for multi-candidate apply (position-bound fingerprints).
// Surfaced on targets[] (apply identity) and evidence; same-post apply must be DESC by path.
ok( isset( $r1['candidates'][0]['targets'][0]['block_path'] ) && '' !== $r1['candidates'][0]['targets'][0]['block_path'], 'block_migrations surfaces block_path on targets[] (apply identity)' );
ok( isset( $r1['candidates'][0]['evidence']['block_path'] ) && '' !== $r1['candidates'][0]['evidence']['block_path'], 'block_migrations also keeps block_path on evidence' );
ok( $r1['candidates'][0]['targets'][0]['block_path'] === $r1['candidates'][0]['evidence']['block_path'], 'targets.block_path matches evidence.block_path' );
ok( '0/1' === $r1['candidates'][0]['targets'][0]['block_path'], 'block_path is the detector path (paragraph at 0/0, h3 at 0/1)' );

$bm_cid = $r1['candidates'][0]['candidate_id'];
$bm_fp  = $r1['candidates'][0]['targets'][0]['block_fingerprint'];

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 2: dismiss, rescan -> does not reappear
 * ════════════════════════════════════════════════════════════════════════ */
$GLOBALS['__post_meta'][301]['_snt_block_migrations_dismissed'] = array( 'heading-hierarchy-skip:' . $bm_fp );
$r3 = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations' ) );
ok( 0 === count( $r3['candidates'] ), 'ACCEPTANCE TEST 2: dismissing via the underlying store makes the candidate not reappear' );
unset( $GLOBALS['__post_meta'][301]['_snt_block_migrations_dismissed'] );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 3: edit the post, rescan -> new candidate_id
 * ════════════════════════════════════════════════════════════════════════ */
$h3_edited = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Edited subsection</h3>', 'innerContent' => array( '<h3>Edited subsection</h3>' ) );
$GLOBALS['__posts'][301]->post_content = json_encode( array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Intro.</p>' ),
	$h3_edited,
) );
$r4 = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations' ) );
ok( 1 === count( $r4['candidates'] ), 'edited post still yields exactly one candidate' );
ok( $r4['candidates'][0]['candidate_id'] !== $bm_cid, 'ACCEPTANCE TEST 3: editing the post content changes the candidate_id' );
ok( $r4['corpus_state']['corpus_fingerprint'] !== $r1['corpus_state']['corpus_fingerprint'], 'ACCEPTANCE TEST 3: corpus_fingerprint also changes on edit' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 5: apply_hint's fingerprint passes the REAL apply tool's
 * own validation — block_migrations AND pattern_adoption, end to end
 * (scan -> suggest -> apply), never 409.
 * ════════════════════════════════════════════════════════════════════════ */
$bm_target = $r4['candidates'][0]['targets'][0];
// v12.0.0: block-migrations-apply is RETIRED from the rw door, so naming it
// would hand a caller a tool the door refuses. sn-apply's block_migration
// change type performs the identical write and IS doored.
ok( 'signal-noise/sn-apply' === $r4['candidates'][0]['apply_hint']['tool'], 'block_migrations apply_hint names a REACHABLE apply tool' );
ok( in_array( 'change.type:block_migration', $r4['candidates'][0]['apply_hint']['required_args'], true ), 'and it names the change type that does the work' );
$suggest = snt_block_migrations_suggest_impl( $bm_target['post_id'], $bm_target['block_fingerprint'], 'heading-hierarchy-skip' );
ok( is_array( $suggest ) && true === ( $suggest['ok'] ?? false ), 'suggest impl accepts the scan-emitted fingerprint' );
$apply = snt_block_migrations_apply_impl( $bm_target['post_id'], $bm_target['block_fingerprint'], $suggest['suggestion_markup'], 'heading-hierarchy-skip' );
ok( is_array( $apply ) && true === ( $apply['ok'] ?? false ), 'ACCEPTANCE TEST 5 (block_migrations): apply on the scan-emitted fingerprint succeeds — NOT a 409, no scanner/applier drift' );
ok( ! is_wp_error( $apply ) || 409 !== ( is_wp_error( $apply ) ? ( $apply->get_error_data()['status'] ?? 0 ) : 0 ), 'apply did not return a fingerprint-conflict 409' );

$pa_scan = snt_ability_sn_scan( array( 'scan_type' => 'pattern_adoption' ) );
ok( 1 === count( $pa_scan['candidates'] ), 'pattern_adoption finds the one core/quote candidate' );
$pa_target = $pa_scan['candidates'][0]['targets'][0];
// v12.0.0: same as block_migrations above.
ok( 'signal-noise/sn-apply' === $pa_scan['candidates'][0]['apply_hint']['tool'], 'pattern_adoption apply_hint names a REACHABLE apply tool' );
ok( in_array( 'change.type:pattern_adoption', $pa_scan['candidates'][0]['apply_hint']['required_args'], true ), 'and it names the change type that does the work' );
// snt_ai_pattern_adoption_suggest_impl() hand-emits REAL WordPress block
// comment markup (<!-- wp:group ... -->), not serialize_block() output —
// unlike block-migrations' suggest, which stays inside this fixture's
// JSON parse_blocks()/serialize_block() convention throughout. Exercising
// suggest's real HTML output would need a genuine block-grammar parser
// stub, out of scope for this harness. What test 5 actually gates —
// fingerprint parity between scanner and applier — is proven directly:
// apply_impl is called with the SCAN-emitted block_fingerprint and any
// validly-parsing replacement markup; a fingerprint mismatch would 409
// regardless of the replacement's content.
$pa_replacement = json_encode( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Replaced.</p>', 'innerContent' => array( '<p>Replaced.</p>' ) ) );
$pa_apply = snt_ai_pattern_adoption_apply_impl( $pa_target['post_id'], $pa_target['block_fingerprint'], $pa_replacement, 'pull-quote' );
ok( is_array( $pa_apply ) && true === ( $pa_apply['ok'] ?? false ), 'ACCEPTANCE TEST 5 (pattern_adoption): apply on the scan-emitted fingerprint succeeds — no scanner/applier drift' );

/* ════════════════════════════════════════════════════════════════════════
 * v13.2.0 — the pattern_adoption adapter honors scope IN the query and
 * walks scheduled ('future') posts (pre-13.2.0: posts_examined counted ALL
 * published posts regardless of scope, and the walk was publish-only, so
 * 20 scheduled notes were invisible to the exact scan where adoption is
 * still free — no signed ledger version exists for them yet).
 * ════════════════════════════════════════════════════════════════════════ */
$GLOBALS['__posts'][303] = tf_post( 303, 'future', json_encode( array( $quote_block ) ), array( 'title' => 'Scheduled quote', 'slug' => 'scheduled-quote' ) );

$pa_unscoped = snt_sn_scan_adapter_pattern_adoption( null );
$pa_ids      = array_map( static function ( $c ) { return (int) $c['targets'][0]['post_id']; }, $pa_unscoped['candidates'] );
ok( in_array( 303, $pa_ids, true ), 'v13.2.0: a SCHEDULED post\'s candidate is visible to the adapter (status future walked)' );

$pa_scoped = snt_sn_scan_adapter_pattern_adoption( array( 303 ) );
ok( 1 === count( $pa_scoped['candidates'] ) && 303 === (int) $pa_scoped['candidates'][0]['targets'][0]['post_id'], 'v13.2.0: allowed_ids scopes the walk to the named post' );
ok( 1 === $pa_scoped['posts_examined'], 'v13.2.0: posts_examined reports the SCOPED walked count — never the whole corpus (corpus_fingerprint/scan_run_id derive from it, so they become scope-honest with it)' );

$pa_empty = snt_sn_scan_adapter_pattern_adoption( array() );
ok( 0 === count( $pa_empty['candidates'] ) && 0 === $pa_empty['posts_examined'], 'v13.2.0: an explicitly EMPTY scope walks nothing — never inverts to "all posts"' );
unset( $GLOBALS['__posts'][303] );

/* ════════════════════════════════════════════════════════════════════════
 * Scope / cursor / clamp — 4xx errors
 * ════════════════════════════════════════════════════════════════════════ */
$bad_type = snt_ability_sn_scan( array( 'scan_type' => 'nonsense' ) );
ok( is_wp_error( $bad_type ) && 'snt_scan_bad_type' === $bad_type->get_error_code() && 422 === ( $bad_type->get_error_data()['status'] ?? 0 ), 'unknown scan_type is rejected (422)' );

$missing_type = snt_ability_sn_scan( array() );
ok( is_wp_error( $missing_type ) && 'snt_scan_bad_type' === $missing_type->get_error_code(), 'missing scan_type is rejected (422)' );

$bad_scope = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'scope' => array( 'kind' => 'nonsense' ) ) );
ok( is_wp_error( $bad_scope ) && 'snt_scan_bad_scope' === $bad_scope->get_error_code(), 'unknown scope.kind is rejected (422)' );

$empty_ids = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array() ) ) );
ok( is_wp_error( $empty_ids ) && 'snt_scan_bad_scope' === $empty_ids->get_error_code(), 'empty scope.post_ids is rejected (422)' );

$bad_since = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'scope' => array( 'kind' => 'modified_since', 'modified_since' => 'not-a-date' ) ) );
ok( is_wp_error( $bad_since ) && 'snt_scan_bad_scope' === $bad_since->get_error_code(), 'unparseable modified_since is rejected (422)' );

$bad_cursor = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'cursor' => '***' ) );
ok( is_wp_error( $bad_cursor ) && 'snt_scan_bad_cursor' === $bad_cursor->get_error_code(), 'malformed cursor is rejected (422)' );

$bad_fresh = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'freshness' => 'stale' ) );
ok( is_wp_error( $bad_fresh ) && 'snt_scan_bad_freshness' === $bad_fresh->get_error_code(), 'unknown freshness is rejected (422)' );

$clamped = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'max_candidates' => 99999 ) );
ok( ! is_wp_error( $clamped ), 'max_candidates above the cap clamps rather than rejects' );

// scope.post_ids restricts to just post 303 (a FRESH h3-no-h2 fixture — post
// 301's own candidate was already consumed by ACCEPTANCE TEST 5's real apply
// call above, so scope tests get their own post rather than racing that state).
$GLOBALS['__posts'][303] = tf_post( 303, 'publish', json_encode( array( $h3_no_h2 ) ), array( 'title' => 'Scope fixture', 'slug' => 'scope-fixture' ) );
$scoped = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 302 ) ) ) );
ok( 0 === count( $scoped['candidates'] ), 'scope.post_ids restricted away from the candidate post finds nothing' );
$scoped_in = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 303 ) ) ) );
ok( 1 === count( $scoped_in['candidates'] ), 'scope.post_ids including the candidate post finds it' );

/* ════════════════════════════════════════════════════════════════════════
 * duplicate_body: N-ary groups, targets holds every member.
 * ════════════════════════════════════════════════════════════════════════ */
// 5 distinct groups (2 members each, distinct bodies -> distinct hashes).
for ( $g = 1; $g <= 5; $g++ ) {
	$body = json_encode( array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => "<p>Duplicate group $g body.</p>" ) ) );
	$GLOBALS['__posts'][ 4100 + $g * 2 ]     = tf_post( 4100 + $g * 2, 'publish', $body, array( 'title' => "Group $g A" ) );
	$GLOBALS['__posts'][ 4100 + $g * 2 + 1 ] = tf_post( 4100 + $g * 2 + 1, 'draft', $body, array( 'title' => "Group $g B" ) );
}

$dup_full = snt_ability_sn_scan( array( 'scan_type' => 'duplicate_body', 'max_candidates' => 200 ) );
ok( is_array( $dup_full ) && 5 === count( $dup_full['candidates'] ), 'duplicate_body finds exactly the 5 designed groups' );
ok( 2 === count( $dup_full['candidates'][0]['targets'] ), 'a 2-member group carries exactly 2 targets' );
ok( null === $dup_full['candidates'][0]['apply_hint'], 'duplicate_body has no apply path (apply_hint null)' );
ok( 1.0 === $dup_full['candidates'][0]['confidence'], 'duplicate_body confidence is the documented constant 1.0 (exact hash match)' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 4: cursor pages cleanly, no duplicates, no gaps.
 * ════════════════════════════════════════════════════════════════════════ */
$seen = array();
$cursor = null;
$pages  = 0;
do {
	$args = array( 'scan_type' => 'duplicate_body', 'max_candidates' => 1 );
	if ( null !== $cursor ) { $args['cursor'] = $cursor; }
	$page = snt_ability_sn_scan( $args );
	ok( ! is_wp_error( $page ), 'ACCEPTANCE TEST 4: each page is a valid envelope' );
	ok( count( $page['candidates'] ) <= 1, 'ACCEPTANCE TEST 4: page respects max_candidates=1' );
	foreach ( $page['candidates'] as $c ) {
		ok( ! in_array( $c['candidate_id'], $seen, true ), 'ACCEPTANCE TEST 4: no candidate_id repeats across pages (no duplicates)' );
		$seen[] = $c['candidate_id'];
	}
	$cursor = $page['nextCursor'];
	$pages++;
} while ( null !== $cursor && $pages < 20 );
ok( 5 === count( $seen ), 'ACCEPTANCE TEST 4: paging max_candidates=1 across 5 candidates visits all 5, no gaps' );
ok( 5 === $pages, 'ACCEPTANCE TEST 4: exactly 5 pages for 5 candidates at page size 1' );

/* ════════════════════════════════════════════════════════════════════════
 * Ordering: confidence DESC, candidate_id ASC.
 * ════════════════════════════════════════════════════════════════════════ */
$sorted_ids = array_column( $dup_full['candidates'], 'candidate_id' );
$expected_sorted = $sorted_ids; // duplicate_body candidates all share confidence 1.0 -> pure candidate_id ASC
sort( $expected_sorted );
ok( $sorted_ids === $expected_sorted, 'ordering: equal confidence ties break candidate_id ASC' );

/* ════════════════════════════════════════════════════════════════════════
 * near_duplicate
 * ════════════════════════════════════════════════════════════════════════ */
// near_duplicate walks the WHOLE 'post' corpus, and cosine depends on
// corpus-wide idf — isolate with a CLEAN registry (the earlier
// block-migrations/pattern-adoption/duplicate-body fixtures would otherwise
// shift idf and make the hand-tuned pair boundary unpredictable).
$GLOBALS['__posts'] = array();
$GLOBALS['__posts'][5001] = tf_post( 5001, 'publish', '<p>Kettle pour bloom filter scale timer.</p>', array( 'title' => 'Cousin A' ) );
$GLOBALS['__posts'][5002] = tf_post( 5002, 'draft', '<p>Kettle pour bloom filter scale thermometer.</p>', array( 'title' => 'Cousin B' ) );
$GLOBALS['__posts'][5003] = tf_post( 5003, 'publish', '<p>Zeppelin cartography whalesong meridian unrelated.</p>' );

$nd = snt_ability_sn_scan( array( 'scan_type' => 'near_duplicate' ) );
ok( is_array( $nd ) && 1 === count( $nd['candidates'] ), 'near_duplicate finds exactly the one designed cousin pair' );
ok( 2 === count( $nd['candidates'][0]['targets'] ), 'near_duplicate emits a 2-target pair candidate' );
ok( null === $nd['candidates'][0]['apply_hint'], 'near_duplicate has no apply path (apply_hint null)' );
ok( $nd['candidates'][0]['confidence'] >= 0.3 && $nd['candidates'][0]['confidence'] <= 1.0, 'near_duplicate confidence is the cosine, mapped directly into [0,1]' );
ok( 'fresh' === $nd['freshness'], 'near_duplicate always reports freshness=fresh (no cache)' );

/* ════════════════════════════════════════════════════════════════════════
 * link_candidates: not-built 503, then fan-out over 2 source posts.
 * ════════════════════════════════════════════════════════════════════════ */
$GLOBALS['__posts'][6001] = tf_post( 6001, 'publish', '<p>Source one.</p>', array( 'title' => 'Source one', 'slug' => 'source-one' ) );
$GLOBALS['__posts'][6002] = tf_post( 6002, 'publish', '<p>Source two.</p>', array( 'title' => 'Source two', 'slug' => 'source-two' ) );
$GLOBALS['__posts'][6010] = tf_post( 6010, 'publish', '<p>Target.</p>', array( 'title' => 'Target', 'slug' => 'link-target' ) );

$not_built = snt_ability_sn_scan( array( 'scan_type' => 'link_candidates', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 6001 ) ) ) );
ok( is_wp_error( $not_built ) && 'snt_ml_not_built' === $not_built->get_error_code() && 503 === ( $not_built->get_error_data()['status'] ?? 0 ), 'link_candidates propagates the real 503 when ML artifacts are unbuilt' );

$GLOBALS['__options'][ SNT_ML_CORPUS_META_OPT ] = array( 'fingerprint' => 'f', 'built_at' => 1753800000, 'posts' => 3 );
// NOTE: get_post_meta()/update_post_meta() above are bound to $GLOBALS['__post_meta']
// (shared with the block-migrations/pattern-adoption dismiss store), not a
// separate '__meta' array — the ML artifact reader rides the SAME meta store.
$GLOBALS['__post_meta'][6001][ SNT_ML_RELATED_META ] = array( array( 'post_id' => 6010, 'score' => 0.8 ) );
$GLOBALS['__post_meta'][6002][ SNT_ML_RELATED_META ] = array( array( 'post_id' => 6010, 'score' => 0.5 ) );

$lc = snt_ability_sn_scan( array( 'scan_type' => 'link_candidates', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 6001, 6002 ) ) ) );
ok( is_array( $lc ) && 2 === count( $lc['candidates'] ), 'link_candidates fans out over both scoped source posts' );
ok( 2 === $lc['corpus_state']['posts_examined'], 'link_candidates corpus_state counts each source post examined' );
ok( null === $lc['candidates'][0]['apply_hint'], 'DEVIATION: link_candidates apply_hint is null — ai-link-apply needs an AI-derived positional fingerprint this deterministic scan cannot produce' );
ok( 'cached' === $lc['freshness'], 'link_candidates always reports freshness=cached (reads the prebuilt artifact; sn_scan never writes, so it can never force a rebuild)' );
ok( 2 === count( $lc['candidates'][0]['targets'] ), 'link_candidates emits a 2-target (source, target) candidate' );

/* ════════════════════════════════════════════════════════════════════════
 * orphan_media
 * ════════════════════════════════════════════════════════════════════════ */
$old_date = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
$GLOBALS['__attachments'] = array(
	array( 'ID' => 701, 'post_title' => 'Referenced', 'guid' => 'https://example.test/uploads/ref.jpg', 'post_date_gmt' => $old_date ),
	array( 'ID' => 702, 'post_title' => 'Orphan',      'guid' => 'https://example.test/uploads/orphan.jpg', 'post_date_gmt' => $old_date ),
);
$GLOBALS['__post_bodies'] = array( '<img class="wp-image-701">' ); // 701 referenced, 702 not.
$GLOBALS['__featured']    = array();

$om = snt_ability_sn_scan( array( 'scan_type' => 'orphan_media' ) );
ok( is_array( $om ) && 1 === count( $om['candidates'] ), 'orphan_media wraps the pure-SQL detector and finds exactly the one true orphan' );
ok( 702 === $om['candidates'][0]['targets'][0]['attachment_id'], 'the flagged candidate is the unreferenced attachment' );
// v12.0.0: NULL, joining duplicate_body / near_duplicate / link_candidates.
// ai-orphan-apply is on NEITHER door and never was — this hint was a dead
// pointer before the v12.0.0 retirements, not because of them. There is no
// sn-apply equivalent on purpose: its change types are post-content
// operations, and force-deleting an attachment is not one.
ok( null === $om['candidates'][0]['apply_hint'], 'orphan_media apply_hint is NULL — it will not name a tool no door exposes' );
ok( 2 === $om['corpus_state']['posts_examined'], 'orphan_media corpus_state reports the total attachments sized (sizing-only COUNT, no detection logic)' );

$om_scoped = snt_ability_sn_scan( array( 'scan_type' => 'orphan_media', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 701 ) ) ) );
ok( 0 === count( $om_scoped['candidates'] ), 'orphan_media scope.post_ids (attachment IDs) filters the finding away' );

/* ════════════════════════════════════════════════════════════════════════
 * emdash — ENVELOPE parity (AUDIT FIX 2026-08-08). v10.51.0's adapter
 * emitted raw scanner rows without target_identity/content_fingerprint/
 * targets/confidence; confirmed live: every candidate collapsed to ONE
 * candidate_id (sha256 of "emdash||") with empty targets/evidence and
 * confidence 0 — and the determinism test could never catch it, because
 * identical garbage IS byte-identical across runs. This block pins the
 * envelope keys with real per-candidate values.
 * ════════════════════════════════════════════════════════════════════════ */
function snt_emdash_scan_content( $content ) {
	// Two prose em-dashes + one structural, positions content-derived.
	return array(
		array( 'classification' => 'prose', 'phrase' => 'alpha—beta', 'position' => 10, 'replacement' => 'alpha, beta', 'context_snippet' => 'x alpha—beta y', 'pair' => false ),
		array( 'classification' => 'prose', 'phrase' => 'gamma—delta', 'position' => 40, 'replacement' => 'gamma, delta', 'context_snippet' => 'x gamma—delta y', 'pair' => false ),
		array( 'classification' => 'attribution', 'phrase' => '—Someone', 'position' => 90, 'replacement' => '', 'context_snippet' => '', 'pair' => false ),
	);
}
function snt_ai_drift_fingerprint( $content, $phrase, $position ) {
	return md5( 'fp|' . $phrase . '|' . $position );
}
$GLOBALS['__posts'][801] = tf_post( 801, 'publish', json_encode( array() ), array( 'slug' => 'emdash-host' ) );

$em = snt_ability_sn_scan( array( 'scan_type' => 'emdash', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 801 ) ) ) );
ok( is_array( $em ) && 2 === count( $em['candidates'] ), 'emdash: prose rows become candidates, structural rows are skipped' );
$em_ids = array_column( $em['candidates'], 'candidate_id' );
ok( 2 === count( array_unique( $em_ids ) ), 'emdash: candidates get DISTINCT candidate_ids (the live-confirmed collapse bug)' );
$em_c = $em['candidates'][0];
ok( 801 === ( $em_c['targets'][0]['post_id'] ?? 0 ), 'emdash: targets carry the post identity (was empty live)' );
ok( SNT_SN_SCAN_CONF_EMDASH === $em_c['confidence'], 'emdash: documented-constant confidence (was 0 live)' );
ok( isset( $em_c['evidence']['phrase'], $em_c['evidence']['position'], $em_c['evidence']['replacement'], $em_c['evidence']['context_snippet'], $em_c['evidence']['fingerprint'] ), 'emdash: evidence carries everything emdash_replace needs (was silently dropped by the assembler)' );
ok( 'signal-noise/sn-apply' === ( $em_c['apply_hint']['tool'] ?? '' ), 'emdash: apply_hint names sn-apply change.type emdash_replace' );

// The near_duplicate section above reset $GLOBALS['__posts'] to isolate its
// own idf-sensitive fixture — restore the duplicate_body group-1 pair (same
// post_date, one publish + one draft) so the extended-acceptance-test-1
// loop below has a real duplicate_body candidate to run the flip stub
// against; every other scan_type's fixtures (301/303, 5001-5003, 6001/6002/
// 6010) are unaffected since they use their own IDs.
$dup_g1_body = json_encode( array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Duplicate group 1 body.</p>' ) ) );
$GLOBALS['__posts'][4102] = tf_post( 4102, 'publish', $dup_g1_body, array( 'title' => 'Group 1 A' ) );
$GLOBALS['__posts'][4103] = tf_post( 4103, 'draft', $dup_g1_body, array( 'title' => 'Group 1 B' ) );

/* ════════════════════════════════════════════════════════════════════════
 * ADVERSARIAL REVIEW FIX — HIGH: sn_scan must write NOTHING, structurally.
 *
 * Runs all 6 scan_types through the REAL ability and asserts the write-call
 * RECORDER (wrapping set_transient/update_option/update_post_meta/$wpdb's
 * write primitives — see the stubs above) stayed at zero. This is what
 * caught snt_block_migrations_run_scan()/snt_pattern_adoption_run_scan()
 * clobbering the per-user admin-tab transient on every sn_scan call before
 * the compute()/run_scan() split — pins the readOnlyHint:true contract
 * STRUCTURALLY (any future scan_type that starts writing fails this guard
 * automatically), not per-adapter.
 * ════════════════════════════════════════════════════════════════════════ */
$GLOBALS['__write_calls'] = array( 'set_transient' => 0, 'update_option' => 0, 'update_post_meta' => 0, 'wpdb_write' => 0 );
foreach ( SNT_SN_SCAN_TYPES as $t ) {
	snt_ability_sn_scan( array( 'scan_type' => $t, 'max_candidates' => 200 ) );
}
ok( 0 === $GLOBALS['__write_calls']['set_transient'], 'ZERO-WRITES GUARD: no set_transient() call across all 6 scan_types (the HIGH the review caught)' );
ok( 0 === $GLOBALS['__write_calls']['update_option'], 'ZERO-WRITES GUARD: no update_option() call across all 6 scan_types' );
ok( 0 === $GLOBALS['__write_calls']['update_post_meta'], 'ZERO-WRITES GUARD: no update_post_meta() call across all 6 scan_types' );
ok( 0 === $GLOBALS['__write_calls']['wpdb_write'], 'ZERO-WRITES GUARD: no $wpdb insert/update/write-query across all 6 scan_types' );

// Companion assertion: the LEGACY callers (block-migrations-scan ability /
// admin tab) must still get the exact same write they always did — the
// compute()/run_scan() split must be byte-identical behavior, not a
// behavior change smuggled in under the "fix".
$GLOBALS['__transients'] = array();
$legacy_bm = snt_block_migrations_run_scan();
$bm_key    = 'snt_block_migrations_candidates_' . (int) get_current_user_id();
ok( array_key_exists( $bm_key, $GLOBALS['__transients'] ), 'legacy caller: snt_block_migrations_run_scan() still writes the per-user transient (unchanged for the admin tab)' );
ok( json_encode( $GLOBALS['__transients'][ $bm_key ] ) === json_encode( $legacy_bm ), 'legacy caller: the written transient is byte-identical to run_scan()\'s own return value' );
// scanned_at excluded: compute() here is a SEPARATE call from the run_scan()
// above, each stamping its own time() — comparing the rest of the envelope
// (candidates + counts) is the real claim; a 1-second time() drift between
// two calls is not a behavior difference.
$compute_again = snt_block_migrations_compute();
ok( $compute_again['candidates'] === $legacy_bm['candidates'] && $compute_again['counts'] === $legacy_bm['counts'], 'compute() and run_scan() return the same candidates+counts (minus the write side effect)' );

$GLOBALS['__transients'] = array();
$legacy_pa = snt_pattern_adoption_run_scan();
$pa_key    = 'snt_pattern_adoption_candidates_' . (int) get_current_user_id();
ok( array_key_exists( $pa_key, $GLOBALS['__transients'] ), 'legacy caller: snt_pattern_adoption_run_scan() still writes the per-user transient (unchanged for the admin tab)' );
ok( json_encode( $GLOBALS['__transients'][ $pa_key ] ) === json_encode( $legacy_pa ), 'legacy caller: the written transient is byte-identical to run_scan()\'s own return value' );

/* ════════════════════════════════════════════════════════════════════════
 * ADVERSARIAL REVIEW FIX — MEDIUM 1: ACCEPTANCE TEST 1 extended to ALL SIX
 * scan_types. duplicate_body's fixture deliberately returns its same-
 * post_date pair (4102, 4103 — the bulk-import case) in a DIFFERENT member
 * order across the two runs (see the get_posts() flip stub above); if the
 * adapter's targets[] canonicalization were missing, THIS assertion is the
 * one that would fail — not a vacuous rerun of already-sorted data.
 * ════════════════════════════════════════════════════════════════════════ */
foreach ( SNT_SN_SCAN_TYPES as $t ) {
	if ( 'duplicate_body' === $t ) {
		$GLOBALS['__dup_flip_ids']  = array( 4102, 4103 );
		$GLOBALS['__dup_flip_call'] = 0;
	} else {
		$GLOBALS['__dup_flip_ids'] = null;
	}
	$run1 = snt_ability_sn_scan( array( 'scan_type' => $t, 'max_candidates' => 200 ) );
	$run2 = snt_ability_sn_scan( array( 'scan_type' => $t, 'max_candidates' => 200 ) );
	if ( is_wp_error( $run1 ) || is_wp_error( $run2 ) ) {
		ok( is_wp_error( $run1 ) && is_wp_error( $run2 ) && $run1->get_error_code() === $run2->get_error_code(), "ACCEPTANCE TEST 1 ($t): both runs error identically (deterministic failure)" );
		continue;
	}
	ok( json_encode( $run1['candidates'] ) === json_encode( $run2['candidates'] ), "ACCEPTANCE TEST 1 ($t): two runs against unchanged content -> byte-identical candidates array" );
	if ( 'duplicate_body' === $t ) {
		ok( $GLOBALS['__dup_flip_call'] >= 2, 'duplicate_body double-run: the flip stub actually fired at least twice (the nondeterminism was genuinely exercised, not a no-op)' );
	}
}
$GLOBALS['__dup_flip_ids'] = null;

/* ════════════════════════════════════════════════════════════════════════
 * block_migrations: same-post multi-candidate surfaces distinct block_paths.
 * List order is confidence DESC / candidate_id ASC (pinned elsewhere) — NOT
 * position order. Callers applying multiple same-post candidates MUST re-sort
 * DESC by block_path (mirrors sn_apply payload.edits descending splice).
 * ════════════════════════════════════════════════════════════════════════ */
$h3_a = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>First skip</h3>', 'innerContent' => array( '<h3>First skip</h3>' ) );
$h3_b = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>Second skip</h3>', 'innerContent' => array( '<h3>Second skip</h3>' ) );
$GLOBALS['__posts'][310] = tf_post( 310, 'publish', json_encode( array( $h3_a, $h3_b ) ), array( 'title' => 'Two skips', 'slug' => 'two-skips' ) );
// Scope to this post so we do not pick up leftover candidates from other fixtures.
$multi = snt_ability_sn_scan( array( 'scan_type' => 'block_migrations', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 310 ) ) ) );
ok( is_array( $multi ) && 2 === count( $multi['candidates'] ), 'same-post multi: two h3-before-h2 candidates' );
$paths = array_map( static function ( $c ) {
	return (string) ( $c['targets'][0]['block_path'] ?? '' );
}, $multi['candidates'] );
sort( $paths );
ok( array( '0/0', '0/1' ) === $paths, 'same-post multi: distinct block_paths 0/0 and 0/1 (position-bound identity)' );

/* ════════════════════════════════════════════════════════════════════════
 * candidate_id derivation: same target/content, different scan_type -> different ID.
 * ════════════════════════════════════════════════════════════════════════ */
$id_a = snt_sn_scan_candidate_id( 'block_migrations', '301', 'deadbeef' );
$id_b = snt_sn_scan_candidate_id( 'pattern_adoption', '301', 'deadbeef' );
ok( $id_a !== $id_b, 'candidate_id is scan_type-scoped: identical target+fingerprint under a different scan_type differs' );
ok( $id_a === hash( 'sha256', 'block_migrations|301|deadbeef' ), 'candidate_id formula matches sha256(scan_type|target_identity|content_fingerprint)' );

/* ════════════════════════════════════════════════════════════════════════
 * Ability registration
 * ════════════════════════════════════════════════════════════════════════ */
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$a = $GLOBALS['__abilities']['signal-noise/sn-scan'] ?? null;
ok( is_array( $a ), 'signal-noise/sn-scan is registered' );
ok( 'snt_ability_perm_read_corpus' === ( $a['permission_callback'] ?? '' ), 'sn-scan gates on edit_others_posts (corpus READ tier)' );
ok( true === ( $a['meta']['annotations']['readonly'] ?? false ) && false === ( $a['meta']['annotations']['destructive'] ?? true ) && true === ( $a['meta']['annotations']['idempotent'] ?? false ), 'sn-scan is annotated readonly + non-destructive + idempotent' );
ok( array( 'scan_type' ) === ( $a['input_schema']['required'] ?? array() ), 'scan_type is the only required field' );
// Relationship, not a literal: the published enum must equal SNT_SN_SCAN_TYPES
// exactly. A hard-coded count only says "someone updated a number"; this says
// "the schema and the registry still agree", which is the property that broke
// in v10.51.0 when emdash reached the adapter map but not the constant.
ok( ( $a['input_schema']['properties']['scan_type']['enum'] ?? array() ) === SNT_SN_SCAN_TYPES, 'the published scan_type enum IS SNT_SN_SCAN_TYPES (not a hand-kept copy)' );
ok( false === ( $a['input_schema']['additionalProperties'] ?? true ), 'input schema rejects unknown properties' );
// Tool description documents the DESC-by-path apply contract for same-post
// block_migrations candidates (not enforced server-side — sn_scan is read-only).
// Pin so a rewrite cannot drop the guidance or the sn_apply payload.edits cite.
$desc = (string) ( $a['description'] ?? '' );
ok( false !== strpos( $desc, 'DESCENDING position order' ), 'sn_scan description documents same-post block_migrations DESCENDING position-order apply' );
ok( false !== strpos( $desc, 'payload.edits' ) || false !== strpos( $desc, 'descending-splice' ), 'sn_scan description cites sn_apply payload.edits descending-splice precedent' );

echo "\nGroup: no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings/deprecations raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\nGroup: enum ⇄ adapter parity — a scan type must be BOTH declared and dispatchable\n";
// v10.51.0 shipped the emdash adapter registered in the dispatch map but ABSENT
// from SNT_SN_SCAN_TYPES, so the ability rejected scan_type:"emdash" before it
// could ever reach the adapter: the feature was unreachable. sn-apply has an
// ALL-TYPES delegation sweep that REDs the moment a type joins its enum without
// joining the sweep, which is exactly why its half was registered correctly.
// This is that guard for sn-scan, in both directions.
$sn_scan_map = snt_sn_scan_adapters();
$declared    = SNT_SN_SCAN_TYPES;
$dispatched  = array_keys( $sn_scan_map );
sort( $declared ); sort( $dispatched );

foreach ( $dispatched as $t ) {
	ok( in_array( $t, $declared, true ), "adapter '$t' is DECLARED in SNT_SN_SCAN_TYPES (otherwise the ability rejects it before dispatch)" );
}
foreach ( $declared as $t ) {
	ok( in_array( $t, $dispatched, true ), "declared type '$t' has a DISPATCHABLE adapter (otherwise the ability accepts it and then 500s)" );
}
ok( $declared === $dispatched, 'the two lists are identical, so neither can drift ahead of the other' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
