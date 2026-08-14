<?php
/**
 * Standalone tests for sn_apply (MCP consolidation session 6b, v10.40.0):
 * signal-noise/sn-apply — PART 3: per-type coverage for the change types
 * the first two suites left untested (adversarial review MEDIUM 2).
 * Covers: surfaces (dry_run preview under the zero-writes recorder,
 * revision-mode staging through stage_revision + stage_meta with a mixed
 * content/meta payload, one live publish path through the REAL
 * snt_ability_update_post_surfaces including its throttle counter), and
 * og_card + anchor_sweep (revision-mode STRUCTURAL refusal at gate 3 with
 * the reason naming the unstageable mechanism, dry_run zero-side-effects
 * via dedicated sn_generate_og_card / sn_prov_run_sweep call recorders —
 * side effects the write-primitive recorder cannot see — plus
 * anchor_sweep's live publish delegation to snt_ability_anchor_sweep).
 * Same bootstrap/stub conventions as tests/abilities-sn-apply.php — see
 * that file's docblock for the full rationale.
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
function tf_add_filter( $hook, $cb ) { $GLOBALS['__filters'][ $hook ][] = $cb; }

$GLOBALS['__next_id']            = 1000;
$GLOBALS['__posts']              = array(); // id => ARRAY_A row
$GLOBALS['__post_meta']          = array();
$GLOBALS['__options']            = array();
$GLOBALS['__transients']         = array();
$GLOBALS['__write_calls']        = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'set_transient' => 0, 'wpdb_write' => 0 );
$GLOBALS['__audit_calls']        = array();
$GLOBALS['__bound_uuid']         = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$GLOBALS['__auth_uuid']          = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'; // = bound => owner, by default
$GLOBALS['__revisions_to_keep']  = -1; // unlimited

function tf_post( $id, $overrides = array() ) {
	$GLOBALS['__posts'][ $id ] = array_merge( array(
		'ID' => $id, 'post_title' => "Post $id", 'post_name' => "post-$id",
		'post_status' => 'publish', 'post_type' => 'post', 'post_parent' => 0,
		'post_date' => '2026-06-01 10:00:00', 'post_modified' => '2026-07-01 10:00:00',
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
if ( ! function_exists( 'post_type_exists' ) ) { function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'attachment' ), true ); } }
if ( ! function_exists( 'get_post_type_object' ) ) { function get_post_type_object( $t ) { $o = new stdClass(); $o->public = 'attachment' !== $t; return $o; } }
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$d = json_decode( (string) $content, true );
		if ( ! is_array( $d ) ) { return array(); }
		return array_key_exists( 'blockName', $d ) ? array( $d ) : $d;
	}
}
if ( ! function_exists( 'serialize_block' ) )  { function serialize_block( $b ) { return json_encode( $b ); } }
if ( ! function_exists( 'serialize_blocks' ) ) { function serialize_blocks( $t ) { return json_encode( $t ); } }
if ( ! function_exists( 'wp_kses_post' ) )     { function wp_kses_post( $h ) { return $h; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'esc_url' ) )          { function esc_url( $u ) { return $u; } }
if ( ! function_exists( 'sanitize_text_field' ) )     { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'home_url' ) )   { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t ) { $GLOBALS['__write_calls']['set_transient']++; $GLOBALS['__transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__write_calls']['update_option']++; $GLOBALS['__options'][ $k ] = $v; return true; } }
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
		return $id;
	}
}
if ( ! function_exists( 'post_type_supports' ) ) { function post_type_supports( $t, $f ) { return true; } }
if ( ! function_exists( 'wp_revisions_to_keep' ) ) { function wp_revisions_to_keep( $post ) { return $GLOBALS['__revisions_to_keep']; } }
// v10.41.2: snt_sn_apply_stage_revision() now overrides post_modified/post_modified_gmt via current_time() before staging (backdated-revision fix) — every fixture that loads inc/sn-apply-revision.php needs this stub.
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
if ( ! function_exists( 'wp_restore_post_revision' ) ) {
	function wp_restore_post_revision( $revision_id ) {
		$rev = $GLOBALS['__posts'][ (int) $revision_id ] ?? null;
		if ( ! $rev || 'revision' !== ( $rev['post_type'] ?? '' ) ) { return false; }
		$parent_id = (int) $rev['post_parent'];
		if ( ! isset( $GLOBALS['__posts'][ $parent_id ] ) ) { return false; }
		foreach ( array( 'post_content', 'post_title', 'post_excerpt' ) as $f ) {
			$GLOBALS['__posts'][ $parent_id ][ $f ] = $rev[ $f ];
		}
		return $parent_id;
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


// $wpdb — meta-description collision query only. Faithful to the REAL query
// shape (parses and applies the post_id exclusion clause) — same stub as
// tests/abilities-sn-validate.php's SN_Test_Wpdb_Validate.
class SN_Test_Wpdb_Apply {
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
$GLOBALS['wpdb'] = new SN_Test_Wpdb_Apply();

/* ════════════════════════════════════════════════════════════════════════
 * Load the SUT
 * ════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/block-fingerprint-engine.php';
require __DIR__ . '/../inc/block-migrations-apply.php';
require __DIR__ . '/../inc/pattern-adoption-apply.php';
require __DIR__ . '/../inc/ai-alt-text-suggest.php';
require __DIR__ . '/../inc/ai-drift-phrase-suggest.php';
require __DIR__ . '/../inc/ai-link-suggest.php';
require __DIR__ . '/../inc/abilities-permission-helpers.php';
require __DIR__ . '/../inc/abilities-update-post-surfaces.php';
require __DIR__ . '/../inc/abilities-content.php';
require __DIR__ . '/../inc/abilities-provenance.php';
require __DIR__ . '/../inc/sn-validate-checks.php';
require __DIR__ . '/../inc/sn-validate-checks-media.php';
require __DIR__ . '/../inc/sn-apply-revision.php';
require __DIR__ . '/../inc/sn-apply-gates.php';
require __DIR__ . '/../inc/sn-apply-validation.php';
require __DIR__ . '/../inc/sn-apply-delete-draft.php'; // v10.58.0 (audit item 6): gate 2 + write + preview for change.type delete_draft
require __DIR__ . '/../inc/sn-apply-link-reshape.php'; // v10.58.0 (audit item 5): pair validator + locator + identity-asserting splice for change.type link_reshape
require __DIR__ . '/../inc/sn-apply-executors.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';


/* ════════════════════════════════════════════════════════════════════════
 * Side-effect recorders for the two publish-only types (the write-primitive
 * recorder can't see a PNG regen or a worker HTTP dispatch — these can).
 * ════════════════════════════════════════════════════════════════════════ */
$GLOBALS['__og_card_calls'] = 0;
$GLOBALS['__sweep_calls']   = 0;
if ( ! function_exists( 'sn_generate_og_card' ) ) {
	function sn_generate_og_card( $post_id ) { $GLOBALS['__og_card_calls']++; return true; }
}
if ( ! function_exists( 'sn_og_image_url_for_post' ) ) {
	function sn_og_image_url_for_post( $post ) { return 'https://example.test/wp-content/uploads/sn-og/post-' . ( is_object( $post ) ? $post->ID : (int) $post ) . '.png'; }
}
if ( ! function_exists( 'sn_prov_run_sweep' ) ) {
	function sn_prov_run_sweep() { $GLOBALS['__sweep_calls']++; return array( 'ok' => true, 'checked' => 3, 'upgraded' => 1, 'still_pending' => 2 ); }
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ) { $p = is_object( $post ) ? $post : get_post( $post ); return $p ? (string) $p->post_title : ''; }
}

function tf_reset_writes() {
	$GLOBALS['__write_calls'] = array( 'wp_update_post' => 0, 'update_post_meta' => 0, '_wp_put_post_revision' => 0, 'update_option' => 0, 'set_transient' => 0, 'wpdb_write' => 0 );
	$GLOBALS['__og_card_calls'] = 0;
	$GLOBALS['__sweep_calls']   = 0;
}
function tf_total_writes() {
	return array_sum( $GLOBALS['__write_calls'] ) + $GLOBALS['__og_card_calls'] + $GLOBALS['__sweep_calls'];
}

/* ════════════════════════════════════════════════════════════════════════
 * Fixtures
 * ════════════════════════════════════════════════════════════════════════ */

// Post 700: surfaces target.
tf_post( 700, array( 'post_excerpt' => 'The original published excerpt text.' ) );
$GLOBALS['__post_meta'][700]['_sn_meta_description'] = 'Old meta description.';

// Post 710: pattern_adoption target — a quote-ish block to replace.
$pa_block = array( 'blockName' => 'core/quote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<blockquote>Q</blockquote>', 'innerContent' => array( '<blockquote>Q</blockquote>' ) );
tf_post( 710, array( 'post_content' => json_encode( array( $pa_block ) ) ) );
$pa_fp          = snt_block_fp_fingerprint( $pa_block, 710, '0/0' );
$pa_replacement = json_encode( array( 'blockName' => 'core/pullquote', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<figure>PQ</figure>', 'innerContent' => array( '<figure>PQ</figure>' ) ) );

// Posts 720 (source) + 721 (published link target): link_insert.
tf_post( 720, array( 'post_content' => 'A body mentioning the target note title somewhere inside prose.' ) );
tf_post( 721, array( 'post_status' => 'publish' ) );
$li_anchor = 'target note title';
$li_pos    = strpos( $GLOBALS['__posts'][720]['post_content'], $li_anchor );
$li_fp     = snt_ai_drift_fingerprint( $GLOBALS['__posts'][720]['post_content'], $li_anchor, $li_pos );

// Post 730: og_card / anchor_sweep-adjacent generic post.
tf_post( 730 );

// Attachment 740: alt_text (for the all-types sweep).
tf_post( 740, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );

// Post 750: block_migration (for the all-types sweep).
$bm_block = array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'innerBlocks' => array(), 'innerHTML' => '<h3>H</h3>', 'innerContent' => array( '<h3>H</h3>' ) );
tf_post( 750, array( 'post_content' => json_encode( array( $bm_block ) ) ) );
$bm_fp          = snt_block_fp_fingerprint( $bm_block, 750, '0/0' );
$bm_replacement = json_encode( array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 2 ), 'innerBlocks' => array(), 'innerHTML' => '<h2>H</h2>', 'innerContent' => array( '<h2>H</h2>' ) ) );

// Post 760: drift_replace (for the all-types sweep).
tf_post( 760, array( 'post_content' => 'Written recently, will drift.' ) );
$dr_phrase = 'recently';
$dr_pos    = strpos( $GLOBALS['__posts'][760]['post_content'], $dr_phrase );
$dr_fp     = snt_ai_drift_fingerprint( $GLOBALS['__posts'][760]['post_content'], $dr_phrase, $dr_pos );

/* ════════════════════════════════════════════════════════════════════════
 * surfaces — dry_run preview under the zero-writes recorder
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nsurfaces: dry_run preview (mixed content+meta payload) writes nothing\n";
tf_reset_writes();
$posts_before = $GLOBALS['__posts'];
$meta_before  = $GLOBALS['__post_meta'];
$rs1 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 700 ),
	'change' => array( 'type' => 'surfaces', 'payload' => array(
		'excerpt'          => 'This is a completely rewritten excerpt with plenty of words to describe the argument being made in this note today.',
		'meta_description' => 'A brand-new meta description for post seven hundred, sized comfortably within its structural cap for validation purposes.',
	) ),
	'mode'   => 'revision', // dry_run defaults true
) );
ok( ! is_wp_error( $rs1 ), 'S1.1: dry_run surfaces call does not refuse' );
eq( false, $rs1['applied'], 'S1.2: applied:false' );
eq( 'The original published excerpt text.', $rs1['diff']['before']['excerpt'] ?? null, 'S1.3: diff.before.excerpt = the CURRENT live excerpt' );
eq( 'Old meta description.', $rs1['diff']['before']['meta_description'] ?? null, 'S1.4: diff.before.meta_description = the CURRENT stored meta' );
ok( false !== strpos( (string) ( $rs1['diff']['after']['excerpt'] ?? '' ), 'rewritten excerpt' ), 'S1.5: diff.after.excerpt = the proposed value' );
eq( 0, tf_total_writes(), 'S1.6: ZERO writes across every write primitive AND both side-effect recorders (no PNG regen, no worker call)' );
eq( $posts_before, $GLOBALS['__posts'], 'S1.7: post store byte-identical' );
eq( $meta_before, $GLOBALS['__post_meta'], 'S1.8: postmeta store byte-identical' );

/* ════════════════════════════════════════════════════════════════════════
 * surfaces — revision-mode staging (mixed content/meta payload)
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nsurfaces: mode:revision stages excerpt as a revision + meta via stage_meta, live post untouched, no PNG regen\n";
tf_reset_writes();
$live_excerpt_before = $GLOBALS['__posts'][700]['post_excerpt'];
$rs2 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 700 ),
	'change' => array( 'type' => 'surfaces', 'payload' => array(
		'excerpt'          => 'Staged replacement excerpt, long enough to read as a real one for the validation gate to accept without complaint.',
		'meta_description' => 'Staged replacement meta description, also sized well within the structural cap so gate two passes it cleanly.',
		'og_card_title'    => 'A staged OG card title',
	) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( ! is_wp_error( $rs2 ), 'S2.1: revision-mode surfaces apply does not refuse' );
eq( true, $rs2['applied'], 'S2.2: applied:true' );
eq( $live_excerpt_before, $GLOBALS['__posts'][700]['post_excerpt'], 'S2.3: live post_excerpt UNTOUCHED (staged as a revision instead)' );
ok( is_int( $rs2['revision_id'] ) && $rs2['revision_id'] > 0, 'S2.4: a real revision was created for the excerpt' );
eq( 'Staged replacement excerpt, long enough to read as a real one for the validation gate to accept without complaint.', $GLOBALS['__posts'][ $rs2['revision_id'] ]['post_excerpt'] ?? null, 'S2.5: the revision row carries the STAGED excerpt' );
eq( 'Old meta description.', get_post_meta( 700, '_sn_meta_description', true ), 'S2.6: live postmeta UNTOUCHED — get_post_meta still returns the old value (6a staged-meta contract)' );
$staged_md = snt_sn_apply_get_staged_meta( 700, '_sn_meta_description' );
ok( is_array( $staged_md ) && false !== strpos( (string) $staged_md['proposed_value'], 'Staged replacement meta' ), 'S2.7: the staged-meta draft-queue row holds the proposed meta_description (delegation target: snt_sn_apply_stage_meta)' );
$staged_og = snt_sn_apply_get_staged_meta( 700, '_sn_og_card_title' );
ok( is_array( $staged_og ) && 'A staged OG card title' === $staged_og['proposed_value'], 'S2.8: og_card_title staged too' );
eq( 0, $GLOBALS['__og_card_calls'], 'S2.9: the OG card PNG was NEVER regenerated in revision mode (publish-only side effect)' );
eq( 0, $GLOBALS['__write_calls']['update_post_meta'], 'S2.10: zero update_post_meta calls — staged meta is an OPTION row, never postmeta' );

/* ════════════════════════════════════════════════════════════════════════
 * surfaces — live publish path (delegates to the real
 * snt_ability_update_post_surfaces, throttle and all)
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nsurfaces: mode:publish writes live via the real update-post-surfaces impl\n";
tf_reset_writes();
$rs3 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 700 ),
	'change' => array( 'type' => 'surfaces', 'payload' => array(
		'meta_description' => 'Published live meta description, written by the real absorbed impl with its throttle machinery running.',
	) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rs3 ), 'S3.1: publish surfaces apply does not refuse' );
eq( true, $rs3['applied'], 'S3.2: applied:true' );
ok( false !== strpos( get_post_meta( 700, '_sn_meta_description', true ), 'Published live meta' ), 'S3.3: live postmeta NOW updated (delegation target: snt_ability_update_post_surfaces)' );
ok( ( $GLOBALS['__transients']['snt_surfaces_writes_700'] ?? 0 ) >= 1, 'S3.4: the absorbed impl\'s own per-post throttle counter advanced — proof the REAL impl ran, not a re-implementation' );

/* ════════════════════════════════════════════════════════════════════════
 * og_card + anchor_sweep — revision-mode structural refusal + dry_run zero-writes
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nog_card: mode:revision refuses structurally at gate 3; dry_run(publish) writes nothing\n";
tf_reset_writes();
$rog1 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 730 ),
	'change' => array( 'type' => 'og_card', 'payload' => array() ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( is_wp_error( $rog1 ), 'OG1.1: refuses' );
eq( 403, (int) ( $rog1->get_error_data()['status'] ?? 0 ), 'OG1.2: status 403 (gate 3)' );
$dog1 = json_decode( $rog1->get_error_message(), true );
eq( false, $dog1['gates']['capability']['passed'] ?? null, 'OG1.3: gate 3 failed' );
eq( false, $dog1['gates']['capability']['mode_supported'] ?? null, 'OG1.4: mode_supported:false — STRUCTURAL, not an identity denial' );
ok( false !== stripos( (string) ( $dog1['gates']['capability']['reason'] ?? '' ), 'PNG' ), 'OG1.5: the reason names the PNG-file mechanism (actionable isError content)' );
ok( isset( $dog1['gates']['fingerprint'] ) && isset( $dog1['gates']['validation'] ) && isset( $dog1['gates']['idempotency'] ), 'OG1.6: every other gate still reports' );
eq( 0, tf_total_writes(), 'OG1.7: the refusal wrote nothing (and never regenerated a PNG)' );

tf_reset_writes();
$rog2 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 730 ),
	'change' => array( 'type' => 'og_card', 'payload' => array() ),
	'mode'   => 'publish', // dry_run defaults true
) );
ok( ! is_wp_error( $rog2 ) && false === $rog2['applied'], 'OG2.1: publish+dry_run previews without refusing' );
eq( 0, $GLOBALS['__og_card_calls'], 'OG2.2: dry_run NEVER calls sn_generate_og_card — the side effect the write recorder cannot see' );
eq( 0, tf_total_writes(), 'OG2.3: zero writes overall' );

echo "\nanchor_sweep: mode:revision refuses structurally at gate 3; dry_run(publish) never dials the worker\n";
tf_reset_writes();
$ras1 = snt_ability_sn_apply( array(
	'target' => array( 'scope' => 'provenance_anchors' ),
	'change' => array( 'type' => 'anchor_sweep', 'payload' => array() ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( is_wp_error( $ras1 ), 'AS1.1: refuses' );
eq( 403, (int) ( $ras1->get_error_data()['status'] ?? 0 ), 'AS1.2: status 403 (gate 3)' );
$das1 = json_decode( $ras1->get_error_message(), true );
eq( false, $das1['gates']['capability']['mode_supported'] ?? null, 'AS1.3: mode_supported:false — structural' );
ok( false !== stripos( (string) ( $das1['gates']['capability']['reason'] ?? '' ), 'Worker' ), 'AS1.4: the reason names the worker HTTP dispatch' );
eq( 0, $GLOBALS['__sweep_calls'], 'AS1.5: the worker was never dialed' );

tf_reset_writes();
$ras2 = snt_ability_sn_apply( array(
	'target' => array( 'scope' => 'provenance_anchors' ),
	'change' => array( 'type' => 'anchor_sweep', 'payload' => array() ),
	'mode'   => 'publish', // dry_run defaults true
) );
ok( ! is_wp_error( $ras2 ) && false === $ras2['applied'], 'AS2.1: publish+dry_run previews without refusing' );
eq( 0, $GLOBALS['__sweep_calls'], 'AS2.2: dry_run NEVER dispatches the worker HTTP call' );

echo "\nanchor_sweep: mode:publish live path delegates to the real snt_ability_anchor_sweep\n";
tf_reset_writes();
$ras3 = snt_ability_sn_apply( array(
	'target' => array( 'scope' => 'provenance_anchors' ),
	'change' => array( 'type' => 'anchor_sweep', 'payload' => array() ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $ras3 ) && true === $ras3['applied'], 'AS3.1: live publish applies' );
eq( 1, $GLOBALS['__sweep_calls'], 'AS3.2: exactly one worker dispatch (delegation target: snt_ability_anchor_sweep -> sn_prov_run_sweep)' );
eq( 1, $ras3['diff']['after']['upgraded'] ?? null, 'AS3.3: the sweep result rides the response' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
