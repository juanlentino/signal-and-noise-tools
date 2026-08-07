<?php
/**
 * Standalone tests for sn_validate (MCP consolidation session 5, v10.30.0):
 * signal-noise/sn-validate.
 *
 * Acceptance tests from ~/.claude/session-data/SN-MCP-new/sn-validate-spec.md,
 * pinned explicitly below (search "ACCEPTANCE TEST"). Tests 2 and 3's
 * sn_apply halves are DEFERRED — sn_apply does not exist yet (sessions
 * 6-7, docs/mcp-consolidation/FINDINGS.md session-5) — this suite pins
 * ready_to_apply SEMANTICS only.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

/* ════════════════════════════════════════════════════════════════════════
 * WP + collaborator stubs (BEFORE the SUT loads)
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
if ( ! function_exists( 'sprintf' ) ) { /* native */ }

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }

const SNT_CORPUS_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );
if ( ! function_exists( 'snt_corpus_post_type_allowed' ) ) {
	function snt_corpus_post_type_allowed( $t ) { return in_array( $t, array( 'post', 'page' ), true ); }
}

$GLOBALS['__posts'] = array();
function tf_post( $id, $status, $extra = array() ) {
	$p = new stdClass();
	$p->ID           = $id;
	$p->post_title   = $extra['title'] ?? "Post $id";
	$p->post_name    = $extra['slug'] ?? "post-$id";
	$p->post_status  = $status;
	$p->post_type    = $extra['post_type'] ?? 'post';
	$p->post_content = $extra['content'] ?? '';
	$p->post_excerpt = $extra['excerpt'] ?? '';
	return $p;
}
if ( ! function_exists( 'get_post' ) ) { function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; } }

$GLOBALS['__post_meta']   = array();
$GLOBALS['__write_calls'] = array( 'update_post_meta' => 0, 'set_transient' => 0, 'update_option' => 0, 'wpdb_write' => 0, 'wp_update_post' => 0 );
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		if ( ! array_key_exists( $key, $GLOBALS['__post_meta'][ (int) $id ] ?? array() ) ) {
			return $single ? '' : array();
		}
		$val = $GLOBALS['__post_meta'][ (int) $id ][ $key ];
		return $single ? $val : array( $val );
	}
}
// Zero-writes guard target — sn_validate must NEVER call this.
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['__write_calls']['update_post_meta']++; return true; }
}
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t ) { $GLOBALS['__write_calls']['set_transient']++; return true; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__write_calls']['update_option']++; return true; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a, $e = false ) { $GLOBALS['__write_calls']['wp_update_post']++; return (int) ( $a['ID'] ?? 0 ); } }

if ( ! function_exists( 'mb_strlen' ) ) { function mb_strlen( $s ) { return strlen( $s ); } } // real ext usually present
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( (string) $p ); } }
if ( ! function_exists( 'get_attached_file' ) ) { function get_attached_file( $id ) { return $GLOBALS['__attached_files'][ (int) $id ] ?? ''; } }
$GLOBALS['__attached_files'] = array();

// Tags: post_tag vocabulary.
$GLOBALS['__tags'] = array(); // list of {term_id,name,slug}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		return array_map( static function ( $t ) {
			$o = new stdClass(); $o->term_id = $t['term_id']; $o->name = $t['name']; $o->slug = $t['slug'];
			return $o;
		}, $GLOBALS['__tags'] );
	}
}
if ( ! function_exists( 'sn_tag_normalize_key' ) ) { function sn_tag_normalize_key( $n ) { return strtolower( trim( (string) $n ) ); } }
if ( ! function_exists( 'snt_corpus_term_names' ) ) {
	function snt_corpus_term_names( $post_id, $tax ) { return $GLOBALS['__post_tags'][ (int) $post_id ] ?? array(); }
}
$GLOBALS['__post_tags'] = array();

// Drift lexicon + unlinked-mentions — REAL functions from the health-check
// files, standalone-loaded (mirrors tests/abilities-sn-scan.php's own
// standalone-load convention for health-check-orphaned-media.php).
require __DIR__ . '/../inc/health-check-drift-time-phrases.php';
if ( ! defined( 'SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST' ) ) { define( 'SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST', 25 ); }
if ( ! function_exists( 'strip_shortcodes' ) ) { function strip_shortcodes( $s ) { return $s; } }
if ( ! function_exists( 'sn_health_pack_check' ) ) { function sn_health_pack_check( $l, $f, $h = '' ) { return array( 'count' => count( $f ), 'findings' => $f, 'label' => $l, 'fix_hint' => $h ); } }
if ( ! function_exists( 'snt_ai_is_available' ) ) { function snt_ai_is_available() { return false; } } // unused by the extract/pattern fns this file needs
require __DIR__ . '/../inc/health-check-unlinked-mentions.php';

// Block-pattern registry.
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$d = json_decode( (string) $content, true );
		return is_array( $d ) ? $d : array();
	}
}
class WP_Block_Patterns_Registry {
	private static $instance;
	public $registered = array();
	public static function get_instance() { if ( ! self::$instance ) { self::$instance = new self(); } return self::$instance; }
	public function is_registered( $slug ) { return in_array( $slug, $this->registered, true ); }
}

// $wpdb — meta-description collision query only.
class SN_Test_Wpdb_Validate {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix = 'wp_';
	public $rows = array(); // list of {post_id, meta_value} for _sn_meta_description
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . str_replace( "'", "''", (string) $a ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_col( $sql ) {
		preg_match( "/meta_value = '((?:[^'\\\\]|\\\\.)*)'/", $sql, $mv );
		preg_match( '/post_id != (\d+)/', $sql, $pid );
		$value    = isset( $mv[1] ) ? stripcslashes( $mv[1] ) : '';
		$exclude  = isset( $pid[1] ) ? (int) $pid[1] : 0;
		return array_values( array_map( 'strval', array_column(
			array_filter( $this->rows, static function ( $r ) use ( $value, $exclude ) {
				return $r['meta_value'] === $value && $r['post_id'] !== $exclude;
			} ), 'post_id'
		) ) );
	}
	public function insert( $t, $d, $f = null ) { $GLOBALS['__write_calls']['wpdb_write']++; return 1; }
	public function update( $t, $d, $w, $f = null, $wf = null ) { $GLOBALS['__write_calls']['wpdb_write']++; return 1; }
	public function query( $sql ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql ) ) { $GLOBALS['__write_calls']['wpdb_write']++; }
		return 0;
	}
}
$GLOBALS['wpdb'] = new SN_Test_Wpdb_Validate();

require __DIR__ . '/../inc/word-count.php';
require __DIR__ . '/../inc/sn-validate-checks.php';
require __DIR__ . '/../inc/sn-validate-checks-media.php';
require __DIR__ . '/../inc/abilities-sn-validate.php';

// Fire wp_abilities_api_init so the ability registration closure runs (not
// asserted directly here — mcp-capabilities.php's allowlist test owns the
// door-exposure pin; this file drives snt_ability_sn_validate() directly,
// same convention as tests/abilities-sn-scan.php).
if ( ! function_exists( 'wp_register_ability' ) ) { function wp_register_ability( $slug, $args ) { return true; } }
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { call_user_func( $cb ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "sn_validate — plugin v10.30.0\n\n";

/* ════════════════════════════════════════════════════════════════════════
 * Fixtures
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['__posts'][100] = tf_post( 100, 'publish', array(
	'title'   => 'On Provenance and Attribution',
	'content' => 'Plain body text mentioning nothing special. Currently the argument holds.',
	'excerpt' => '',
) );
$GLOBALS['__posts'][200] = tf_post( 200, 'publish', array( 'title' => 'Target Note', 'slug' => 'target-note', 'content' => 'Target body.' ) );
$GLOBALS['__posts'][300] = tf_post( 300, 'draft', array( 'title' => 'Unpublished Target' ) );
$GLOBALS['__tags'] = array(
	array( 'term_id' => 1, 'name' => 'Provenance', 'slug' => 'provenance' ),
	array( 'term_id' => 2, 'name' => 'Signatures', 'slug' => 'signatures' ),
);

/* ════════════════════════════════════════════════════════════════════════
 * 404 / 422 coverage
 * ════════════════════════════════════════════════════════════════════════ */

$r = snt_ability_sn_validate( array( 'post_id' => 99999 ) );
ok( is_wp_error( $r ) && 404 === $r->get_error_data()['status'], '4xx: unknown post_id -> 404' );

$r = snt_ability_sn_validate( array( 'post_id' => 300 ) );
ok( ! is_wp_error( $r ), 'draft post_id is a valid corpus target (SNT_CORPUS_STATUSES includes draft) — not 404' );

$r = snt_ability_sn_validate( array( 'post_id' => 100, 'checks' => array( 'not_a_real_check' ) ) );
ok( is_wp_error( $r ) && 'snt_validate_bad_checks' === $r->get_error_code() && 422 === $r->get_error_data()['status'], '4xx: invalid checks token -> 422' );

$r = snt_ability_sn_validate( array( 'post_id' => 100, 'checks' => array() ) );
ok( is_wp_error( $r ) && 422 === $r->get_error_data()['status'], '4xx: empty checks array -> 422' );

$r = snt_ability_sn_validate( array( 'post_id' => 100, 'compare_against' => 'bogus' ) );
ok( is_wp_error( $r ) && 422 === $r->get_error_data()['status'], '4xx: invalid compare_against -> 422' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 1 — same proposal twice -> byte-identical findings +
 * finding_id values.
 * ════════════════════════════════════════════════════════════════════════ */

$proposal = array(
	'post_id'  => 100,
	'proposed' => array(
		'meta_description' => 'Short.',
		'excerpt'          => 'Too short an excerpt to pass the guideline word count on its own.',
	),
	'checks' => array( 'meta_description', 'excerpt' ),
);
$run1 = snt_ability_sn_validate( $proposal );
$run2 = snt_ability_sn_validate( $proposal );
ok( ! is_wp_error( $run1 ) && ! is_wp_error( $run2 ), 'ACCEPTANCE TEST 1: setup — both runs succeed' );
$ids1 = array_column( $run1['findings'], 'finding_id' );
$ids2 = array_column( $run2['findings'], 'finding_id' );
ok( $ids1 === $ids2 && ! empty( $ids1 ), 'ACCEPTANCE TEST 1: finding_id values are byte-identical across two runs of the SAME proposal' );
ok( json_encode( $run1['findings'] ) === json_encode( $run2['findings'] ), 'ACCEPTANCE TEST 1: the full findings array is byte-identical' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TESTS 2/3 — ready_to_apply semantics (sn_apply half DEFERRED;
 * see file header + FINDINGS.md session-5).
 * ════════════════════════════════════════════════════════════════════════ */

$err_case = snt_ability_sn_validate( array(
	'post_id' => 100,
	'proposed' => array( 'meta_description' => '' ), // empty -> hard error
	'checks'  => array( 'meta_description' ),
) );
ok( ! is_wp_error( $err_case ), 'ACCEPTANCE TEST 2 setup: call succeeds (validation failure is not a transport error)' );
ok( 'fail' === $err_case['status'] && false === $err_case['ready_to_apply'], 'ACCEPTANCE TEST 2: a known ERROR -> status fail, ready_to_apply false' );

$warn_case = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'excerpt' => 'Too short.' ), // warning-only (word count)
	'checks'   => array( 'excerpt' ),
) );
ok( 'pass_with_warnings' === $warn_case['status'] && true === $warn_case['ready_to_apply'], 'ACCEPTANCE TEST 3: only WARNINGS -> status pass_with_warnings, ready_to_apply true' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 4 — meta_description corpus_collision detects a PLANTED
 * duplicate.
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['wpdb']->rows = array(
	array( 'post_id' => 640, 'meta_value' => 'A duplicated meta description string.' ),
	array( 'post_id' => 701, 'meta_value' => 'A duplicated meta description string.' ),
	// The post being validated ITSELF carries the same stored meta — the
	// query's `post_id != %d` self-exclusion must keep it out of the
	// collision evidence (re-validating an unchanged published value must
	// not self-collide). The stub's get_col() parses and applies the real
	// SQL's exclusion clause, so this row is a live probe of that clause.
	array( 'post_id' => 100, 'meta_value' => 'A duplicated meta description string.' ),
);
$collision = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'meta_description' => 'A duplicated meta description string.' ),
	'checks'   => array( 'meta_description' ),
) );
$collision_findings = array_values( array_filter( $collision['findings'], static function ( $f ) { return 'corpus_collision' === $f['check']; } ) );
ok( 1 === count( $collision_findings ), 'ACCEPTANCE TEST 4: a planted duplicate meta_description produces exactly one corpus_collision finding' );
ok( ! empty( $collision_findings ) && 'error' === $collision_findings[0]['severity'], 'ACCEPTANCE TEST 4: corpus_collision severity is error' );
ok( ! empty( $collision_findings ) && array( 640, 701 ) === $collision_findings[0]['evidence']['colliding_post_ids'], 'ACCEPTANCE TEST 4: colliding_post_ids evidence names both planted posts — and NOT post 100 itself (self-exclusion clause live-probed via the planted self-row)' );
$GLOBALS['wpdb']->rows = array(); // reset for later tests

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 5 — checks:"all" on a post with NO proposal validates
 * PUBLISHED surfaces, never errors on the missing proposal itself.
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['__post_meta'][100]['_sn_meta_description'] = 'A perfectly reasonable published meta description that sits inside the guideline window nicely.';
$GLOBALS['__post_tags'][100] = array( 'Provenance' );

$all_no_proposal = snt_ability_sn_validate( array( 'post_id' => 100, 'checks' => 'all' ) );
ok( ! is_wp_error( $all_no_proposal ), 'ACCEPTANCE TEST 5: checks:"all" with no proposal never 4xx/5xx' );
ok( in_array( 'meta_description', $all_no_proposal['surfaces_checked'], true ), 'ACCEPTANCE TEST 5: published meta_description was validated' );
ok( in_array( 'tags', $all_no_proposal['surfaces_checked'], true ), 'ACCEPTANCE TEST 5: published tags were validated' );
ok( ! in_array( 'links', $all_no_proposal['surfaces_checked'], true ) && ! in_array( 'alt_text', $all_no_proposal['surfaces_checked'], true ), 'ACCEPTANCE TEST 5: proposal-only surfaces (links/alt_text) are silently skipped, not errors' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 5b (v10.41.1): `checks` arrives as a JSON-encoded STRING
 * — the same live transport class this session's sn_apply `target` fix
 * addressed (found by that fix's projection sweep: `checks` had the
 * identical untyped-oneOf shape). "all" as a bare string keeps working
 * unchanged (it is not run through the decode path at all).
 * ════════════════════════════════════════════════════════════════════════ */
$checks_as_json_string = snt_ability_sn_validate( array(
	'post_id' => 100,
	'proposed' => array( 'excerpt' => 'Too short.' ),
	'checks'  => json_encode( array( 'excerpt' ) ),
) );
ok( ! is_wp_error( $checks_as_json_string ), 'ACCEPTANCE TEST 5b.1: a JSON-string checks value decodes and does not refuse' );
ok( in_array( 'excerpt', $checks_as_json_string['surfaces_checked'], true ), 'ACCEPTANCE TEST 5b.2: the decoded checks value actually ran (excerpt was checked)' );

$checks_all_still_bare_string = snt_ability_sn_validate( array( 'post_id' => 100, 'checks' => 'all' ) );
ok( ! is_wp_error( $checks_all_still_bare_string ), 'ACCEPTANCE TEST 5b.3: checks:"all" as a bare string is unaffected by the decode path' );

$checks_bad_string = snt_ability_sn_validate( array( 'post_id' => 100, 'checks' => 'not valid json and not "all"' ) );
ok( is_wp_error( $checks_bad_string ), 'ACCEPTANCE TEST 5b.4: an undecodable, non-"all" string checks value still refuses (unchanged 422 path)' );

/* ════════════════════════════════════════════════════════════════════════
 * ACCEPTANCE TEST 6 — ZERO MODEL CALLS. Structural source scan: neither
 * sn-validate file may reference an AI transport entry point. Names pulled
 * directly from inc/ai-bootstrap.php (the real client entry points).
 * ════════════════════════════════════════════════════════════════════════ */

// Strip comments/docblocks BEFORE scanning — this file's own header
// deliberately NAMES the forbidden entry points in prose ("must never
// reference wp_ai_client_prompt()...") to document the guarantee; a raw
// substring scan would false-positive on its own documentation. token_get_all
// gives an exact, non-regex comment strip (no risk of a paren-balance miss —
// the multi-line grep trap this program's own memory flags — since PHP's
// own tokenizer draws the comment boundary, not a hand-written pattern).
function snt_test_strip_php_comments( $src ) {
	$out = '';
	foreach ( token_get_all( $src ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$out .= is_array( $token ) ? $token[1] : $token;
	}
	return $out;
}

$forbidden = array( 'wp_ai_client_prompt', 'snt_ai_generate_with_constraints', 'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'snt_ai_can_text_generate' );
$scanned_files = array(
	__DIR__ . '/../inc/abilities-sn-validate.php',
	__DIR__ . '/../inc/sn-validate-checks.php',
	__DIR__ . '/../inc/sn-validate-checks-media.php',
);
$violations = array();
foreach ( $scanned_files as $file ) {
	$src = snt_test_strip_php_comments( file_get_contents( $file ) );
	foreach ( $forbidden as $needle ) {
		if ( false !== strpos( $src, $needle ) ) {
			$violations[] = basename( $file ) . ':' . $needle;
		}
	}
}
ok( empty( $violations ), 'ACCEPTANCE TEST 6: zero model-call references in sn-validate source, code only, comments stripped (' . implode( ', ', $violations ) . ')' );

/* Zero-writes guard moved to the END of this file (after every check family
 * has executed) — an adversarial-review fix: asserting here covered only the
 * surfaces exercised so far, which is a snapshot, not the structural
 * full-surface sweep the guard claims to be. */

/* ════════════════════════════════════════════════════════════════════════
 * alt_text — char_range / filename_pattern / redundant_prefix
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['__attached_files'][900] = '/uploads/2026/07/IMG_1234.jpg';
$alt = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'alt_text' => array(
		array( 'attachment_id' => 900, 'text' => 'IMG_1234.jpg' ),
		array( 'attachment_id' => 901, 'text' => 'Image of a mixing console at night' ),
		array( 'attachment_id' => 902, 'text' => 'A vintage tube mixing console glowing under warm amber studio light, captured late at night in the control room.' ),
	) ),
	'checks' => array( 'alt_text' ),
) );
$alt_checks = array_column( $alt['findings'], 'check' );
ok( in_array( 'filename_pattern', $alt_checks, true ), 'alt_text: filename-shaped text is rejected (filename_pattern)' );
ok( in_array( 'redundant_prefix', $alt_checks, true ), 'alt_text: "Image of ..." preamble is rejected (redundant_prefix)' );
$clean_item_findings = array_filter( $alt['findings'], static function ( $f ) { return 'attachment:902' === ( $f['evidence']['item'] ?? '' ); } );
ok( empty( $clean_item_findings ), 'alt_text: a well-formed description in the soft length window produces zero findings' );

/* ════════════════════════════════════════════════════════════════════════
 * links — target_exists / not_self / not_already_linked / anchor_present
 * ════════════════════════════════════════════════════════════════════════ */

$links = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array(
		'body'  => 'This mentions the target note by an anchor phrase found here.',
		'links' => array(
			array( 'anchor_text' => 'anchor phrase found here', 'target_post_id' => 200 ),
			array( 'anchor_text' => 'nowhere text', 'target_post_id' => 200 ),
			array( 'anchor_text' => 'x', 'target_post_id' => 100 ),
			array( 'anchor_text' => 'x', 'target_post_id' => 300 ),
			array( 'anchor_text' => 'x', 'target_post_id' => 88888 ),
		),
	),
	'checks' => array( 'links' ),
) );
$by_check = array();
foreach ( $links['findings'] as $f ) { $by_check[ $f['check'] ][] = $f; }
ok( isset( $by_check['anchor_present'] ), 'links: a missing anchor produces an anchor_present error' );
ok( isset( $by_check['not_self'] ), 'links: self-target produces a not_self error' );
ok( isset( $by_check['target_exists'] ), 'links: a draft OR nonexistent target produces a target_exists error' );

// Already-linked case, standalone.
$already = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array(
		'body'  => 'See /notes/target-note/ for more.',
		'links' => array( array( 'anchor_text' => 'more', 'target_post_id' => 200 ) ),
	),
	'checks' => array( 'links' ),
) );
$already_checks = array_column( $already['findings'], 'check' );
ok( in_array( 'not_already_linked', $already_checks, true ), 'links: an existing /notes/ link to the target produces not_already_linked' );

/* ════════════════════════════════════════════════════════════════════════
 * tags — tag_vocabulary
 * ════════════════════════════════════════════════════════════════════════ */

$tags = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'tags' => array( 'Provenance', 'Not A Real Tag' ) ),
	'checks'   => array( 'tags' ),
) );
$tag_findings = array_column( $tags['findings'], 'observed' );
ok( in_array( 'Not A Real Tag', $tag_findings, true ) && ! in_array( 'Provenance', $tag_findings, true ), 'tags: only the out-of-vocabulary tag is flagged' );

/* ════════════════════════════════════════════════════════════════════════
 * body — drift_lexicon (reused sn_health_drift_time_patterns()) +
 * block_pattern_registered
 * ════════════════════════════════════════════════════════════════════════ */

$body = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'body' => 'This is currently true and was updated recently.' ),
	'checks'   => array( 'body' ),
) );
$body_checks = array_column( $body['findings'], 'check' );
ok( in_array( 'drift_lexicon', $body_checks, true ), 'body: drift_lexicon flags a relative-time phrase via the REUSED health-check lexicon' );

WP_Block_Patterns_Registry::get_instance()->registered = array( 'sn/known-pattern' );
$pattern_body = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'body' => json_encode( array(
		array( 'blockName' => 'core/pattern', 'attrs' => array( 'slug' => 'sn/unknown-pattern' ), 'innerBlocks' => array(), 'innerHTML' => '' ),
	) ) ),
	'checks' => array( 'body' ),
) );
$pattern_checks = array_column( $pattern_body['findings'], 'check' );
ok( in_array( 'block_pattern_registered', $pattern_checks, true ), 'body: an unregistered core/pattern slug is flagged' );

$pattern_body_ok = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'body' => json_encode( array(
		array( 'blockName' => 'core/pattern', 'attrs' => array( 'slug' => 'sn/known-pattern' ), 'innerBlocks' => array(), 'innerHTML' => '' ),
	) ) ),
	'checks' => array( 'body' ),
) );
$pattern_ok_checks = array_column( $pattern_body_ok['findings'], 'check' );
ok( ! in_array( 'block_pattern_registered', $pattern_ok_checks, true ), 'body: a REGISTERED core/pattern slug produces no finding' );

/* ════════════════════════════════════════════════════════════════════════
 * note_summary — single_sentence
 * ════════════════════════════════════════════════════════════════════════ */

$ns_bad = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'note_summary' => 'This is one sentence. This is a second sentence.' ),
	'checks'   => array( 'note_summary' ),
) );
ok( 'fail' === $ns_bad['status'], 'note_summary: two sentences -> error -> status fail' );

$ns_good = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'note_summary' => 'This is a single sentence summary' ),
	'checks'   => array( 'note_summary' ),
) );
ok( 'fail' !== $ns_good['status'], 'note_summary: one sentence -> no single_sentence error' );

/* ════════════════════════════════════════════════════════════════════════
 * brand_voice — INFO ONLY, never blocks, never a score.
 * ════════════════════════════════════════════════════════════════════════ */

$voice = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'excerpt' => 'This piece explores the idea — it delves into a landscape of ideas — really.' ),
	'checks'   => array( 'brand_voice' ),
) );
ok( ! empty( $voice['findings'] ), 'brand_voice: banned-phrase hits produce findings' );
$voice_severities = array_unique( array_column( $voice['findings'], 'severity' ) );
ok( array( 'info' ) === $voice_severities, 'brand_voice: EVERY finding is severity info — never error, never warning' );
ok( true === $voice['ready_to_apply'], 'brand_voice: info findings never affect ready_to_apply' );
$em_dash_finding = array_values( array_filter( $voice['findings'], static function ( $f ) { return 'em_dash_count' === $f['check']; } ) );
ok( ! empty( $em_dash_finding ) && 2 === $em_dash_finding[0]['observed'], 'brand_voice: em_dash_count counts literal U+2014 occurrences' );

// AUDIT FIX (2026-08-08): brand_voice execution must be OBSERVABLE. Pre-fix,
// the token never entered surfaces_checked and a cleanly-evaluated surface
// left no trace — indistinguishable from the check never running.
ok( in_array( 'brand_voice', $voice['surfaces_checked'], true ), 'brand_voice: the literal token appears in surfaces_checked when the pass ran' );
ok( in_array( 'excerpt', $voice['surfaces_checked'], true ), 'brand_voice: every text surface it evaluated is recorded in surfaces_checked' );

// A clean surface (zero brand_voice findings would need empty text — use a
// short clean body with no banned phrases: sentence_length still fires, but
// the surface must be recorded regardless of finding count).
$voice_with_body = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'body' => 'Plain words here.' ),
	'checks'   => array( 'body', 'brand_voice' ),
) );
ok( in_array( 'brand_voice', $voice_with_body['surfaces_checked'], true ), 'brand_voice: token present when requested alongside structural checks (the audit repro shape)' );
ok( in_array( 'body', $voice_with_body['surfaces_checked'], true ), 'brand_voice: the evaluated body surface is recorded' );

// Requested but NOTHING to evaluate -> loud WARNING, never a silent no-op.
// Post 300 is draft with empty surfaces (see fixtures above) — resolve every
// text surface to nothing by proposing none and using a post with no
// published excerpt/meta/body.
$voice_nothing = snt_ability_sn_validate( array(
	'post_id'  => 300,
	'proposed' => array( 'tags' => array( 'Provenance' ) ),
	'checks'   => array( 'brand_voice' ),
) );
$nothing_checks = array_column( $voice_nothing['findings'], 'check' );
ok( in_array( 'not_evaluated', $nothing_checks, true ), 'brand_voice: requested with no resolvable text surface -> not_evaluated WARNING finding' );
$not_eval = array_values( array_filter( $voice_nothing['findings'], static function ( $f ) { return 'not_evaluated' === $f['check']; } ) );
ok( ! empty( $not_eval ) && 'warning' === $not_eval[0]['severity'], 'brand_voice: not_evaluated is severity warning (loud, never blocking)' );
ok( true === $voice_nothing['ready_to_apply'], 'brand_voice: not_evaluated never blocks ready_to_apply' );
ok( ! in_array( 'brand_voice', $voice_nothing['surfaces_checked'], true ), 'brand_voice: token absent from surfaces_checked when the pass could not run' );

/* ════════════════════════════════════════════════════════════════════════
 * diff — compare_against:"published" simple presence diff
 * ════════════════════════════════════════════════════════════════════════ */

$diffed = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'excerpt' => 'A brand new excerpt entirely.' ),
	'checks'   => array( 'excerpt' ),
	'compare_against' => 'published',
) );
ok( is_array( $diffed['diff'] ) && array_key_exists( 'excerpt', $diffed['diff'] ), 'diff: present for a proposed surface when compare_against is published' );
ok( 'A brand new excerpt entirely.' === $diffed['diff']['excerpt']['proposed'], 'diff: proposed value is carried through verbatim' );

$no_diff = snt_ability_sn_validate( array(
	'post_id'  => 100,
	'proposed' => array( 'excerpt' => 'A brand new excerpt entirely.' ),
	'checks'   => array( 'excerpt' ),
	'compare_against' => 'none',
) );
ok( null === $no_diff['diff'], 'diff: compare_against:"none" omits the diff entirely' );

/* ════════════════════════════════════════════════════════════════════════
 * Zero-writes guard — LAST, deliberately: every check family above has now
 * executed under the write recorders, so a zero here is a structural
 * full-surface claim (the sn_scan guard pattern), not a mid-file snapshot.
 * A caching set_transient() added to ANY check — including the six families
 * that run late in this file — reds this block.
 * ════════════════════════════════════════════════════════════════════════ */

foreach ( array( 'update_post_meta', 'set_transient', 'update_option', 'wpdb_write', 'wp_update_post' ) as $k ) {
	ok( 0 === $GLOBALS['__write_calls'][ $k ], "ZERO-WRITES GUARD (end-of-suite, all check families exercised): no $k() call across every sn_validate call in this file" );
}

/* ════════════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════════════ */

if ( ! empty( $GLOBALS['__php_errors'] ) ) {
	echo "\nPHP ERRORS/WARNINGS/NOTICES:\n";
	foreach ( $GLOBALS['__php_errors'] as $e ) { echo "  $e\n"; }
	$fail += count( $GLOBALS['__php_errors'] );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
