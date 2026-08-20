<?php
/**
 * Standalone tests for sn_apply (MCP consolidation session 6b, v10.40.0):
 * signal-noise/sn-apply — PART 4: pattern_adoption + link_insert coverage
 * and the ALL-EIGHT-types structural dry_run zero-writes sweep
 * (adversarial review MEDIUM 2). Delegation to the real absorbed impls is
 * pinned by each surface's OWN error-code map (an invalid pattern_type
 * comes back as snt_pattern_adoption_invalid_pattern_type; an
 * already-linked anchor trips the link surface's own rule) — codes only
 * the real impls produce. The sweep loops SNT_SN_APPLY_CHANGE_TYPES
 * itself (session-4 recorder pattern), so a future ninth change type
 * fails the count assertion until it joins the sweep table.
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
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $c ) { return true; } }
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
// Session 7 (restore_revision) — real 6.9 contract: wp_get_post_revision()
// returns null for BOTH "no such post" and "found, but not a revision"
// (verified against the real source, wp-includes/revision.php — see
// inc/sn-apply-restore-revision.php's docblock).
if ( ! function_exists( 'wp_get_post_revision' ) ) {
	function wp_get_post_revision( $id ) {
		$row = $GLOBALS['__posts'][ (int) $id ] ?? null;
		if ( ! $row || 'revision' !== ( $row['post_type'] ?? '' ) ) { return null; }
		return (object) $row;
	}
}
if ( ! function_exists( 'wp_get_post_revisions' ) ) {
	function wp_get_post_revisions( $post_id, $args = null ) {
		$post_id = (int) $post_id;
		$out     = array();
		foreach ( $GLOBALS['__posts'] as $row ) {
			if ( 'revision' === ( $row['post_type'] ?? '' ) && (int) ( $row['post_parent'] ?? 0 ) === $post_id ) {
				$out[] = (object) $row;
			}
		}
		// Real core orders 'date ID' DESC — this fixture's revision IDs are
		// assigned sequentially at creation time, so ID DESC is a faithful
		// proxy for "newest first" without needing real timestamps.
		usort( $out, function ( $a, $b ) { return $b->ID <=> $a->ID; } );
		return $out;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
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
require __DIR__ . '/../inc/emdash-scan.php';
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
require __DIR__ . '/../inc/sn-apply-create-draft.php';
require __DIR__ . '/../inc/sn-apply-restore-revision.php';
require __DIR__ . '/../inc/sn-apply-sentence-replace.php';
require __DIR__ . '/../inc/maturity-roadmap-merge.php'; // sn_maturity_roadmap_effective_board() now reads through the three-way merge
require __DIR__ . '/../inc/maturity-roadmap-shortcode.php'; // roadmap_board's board/validator/fingerprint helpers — the REAL impl, never restubbed here.
require __DIR__ . '/../inc/sn-apply-roadmap-board.php';
require __DIR__ . '/../inc/sn-apply-executors.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';

if ( ! function_exists( 'get_edit_post_link' ) ) { function get_edit_post_link( $id, $ctx = 'display' ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; } }
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $args, $wp_error = false ) {
		$GLOBALS['__write_calls']['wp_insert_post'] = ( $GLOBALS['__write_calls']['wp_insert_post'] ?? 0 ) + 1;
		$id = $GLOBALS['__next_id']++;
		tf_post( $id, array_merge( array( 'post_status' => 'draft', 'post_type' => 'post' ), $args, array( 'ID' => $id ) ) );
		return $id;
	}
}
if ( ! function_exists( 'wp_set_post_tags' ) ) { function wp_set_post_tags( $id, $tags, $append = false ) { $GLOBALS['__write_calls']['wp_set_post_tags'] = ( $GLOBALS['__write_calls']['wp_set_post_tags'] ?? 0 ) + 1; return true; } }


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

// Post 761: emdash_replace (for the all-types sweep). The phrase/position come
// from the real scanner, so this fixture cannot drift from the classifier: if
// snt_emdash_scan_content() stops classifying this sentence as prose, the sweep
// entry loses its candidate and REDs here rather than silently testing nothing.
tf_post( 761, array( 'post_content' => '<p>The kit I reach for — the studio and the instruments.</p>' ) );
$em_cands = snt_emdash_scan_content( $GLOBALS['__posts'][761]['post_content'] );
$em_cands = array_values( array_filter( $em_cands, function ( $c ) { return 'prose' === $c['classification']; } ) );
$em_phrase = $em_cands ? $em_cands[0]['phrase'] : '';
$em_pos    = $em_cands ? $em_cands[0]['position'] : -1;
$em_repl   = $em_cands ? $em_cands[0]['replacement'] : '';
$em_fp     = ( $em_cands && function_exists( 'snt_ai_drift_fingerprint' ) )
	? snt_ai_drift_fingerprint( $GLOBALS['__posts'][761]['post_content'], $em_phrase, $em_pos )
	: '';

// create_draft (for the all-types sweep) — no fixture post: the target IS
// the not-yet-created post (session 6c).
$cd_block   = array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Sweep draft body.</p>', 'innerContent' => array( '<p>Sweep draft body.</p>' ) );
$cd_content = json_encode( array( $cd_block ) );

// Post 770 + revision 771 (for the all-types sweep) — restore_revision
// (session 7). Fingerprint binds to post 770's LIVE content_hash.
tf_post( 770, array( 'post_content' => 'Sweep restore target content.', 'post_excerpt' => 'Sweep excerpt.' ) );
tf_post( 771, array( 'post_type' => 'revision', 'post_parent' => 770, 'post_content' => 'Sweep restore proposed content.', 'post_title' => 'Post 770', 'post_excerpt' => 'Sweep excerpt.' ) );
$rr_fp = snt_corpus_content_hash( $GLOBALS['__posts'][770]['post_content'] );

/* ════════════════════════════════════════════════════════════════════════
 * pattern_adoption — dry_run + revision (delegation pinned by the
 * surface's OWN error-code map: snt_pattern_adoption_invalid_pattern_type
 * is produced only by the real snt_ai_pattern_adoption_apply_impl)
 * ════════════════════════════════════════════════════════════════════════ */
echo "\npattern_adoption: dry_run preview + revision staging + real-impl error-code pin\n";
tf_reset_writes();
$rpa1 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 710 ),
	'change' => array( 'type' => 'pattern_adoption', 'fingerprint' => $pa_fp, 'payload' => array( 'pattern_type' => 'pull-quote', 'replacement_markup' => $pa_replacement ) ),
	'mode'   => 'revision', // dry_run defaults true
) );
ok( ! is_wp_error( $rpa1 ) && false === $rpa1['applied'], 'PA1.1: dry_run previews' );
ok( false !== strpos( (string) ( $rpa1['diff']['after'] ?? '' ), 'pullquote' ), 'PA1.2: the diff.after holds the REPLACED tree' );
eq( 0, tf_total_writes(), 'PA1.3: zero writes' );

$pa_live_before = $GLOBALS['__posts'][710]['post_content'];
tf_reset_writes();
$rpa2 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 710 ),
	'change' => array( 'type' => 'pattern_adoption', 'fingerprint' => $pa_fp, 'payload' => array( 'pattern_type' => 'pull-quote', 'replacement_markup' => $pa_replacement ) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( ! is_wp_error( $rpa2 ) && true === $rpa2['applied'], 'PA2.1: revision apply succeeds' );
eq( $pa_live_before, $GLOBALS['__posts'][710]['post_content'], 'PA2.2: live post byte-identical' );
ok( is_int( $rpa2['revision_id'] ) && isset( $GLOBALS['__posts'][ $rpa2['revision_id'] ] ), 'PA2.3: revision exists' );
eq( 0, $GLOBALS['__write_calls']['wp_update_post'], 'PA2.4: wp_update_post never called' );

// Real-impl delegation pin: an INVALID pattern_type must come back with the
// pattern-adoption surface's OWN error code — only the real
// snt_ai_pattern_adoption_apply_impl's code map produces it.
$rpa3 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 710 ),
	'change' => array( 'type' => 'pattern_adoption', 'fingerprint' => $pa_fp, 'payload' => array( 'pattern_type' => 'not-a-real-type', 'replacement_markup' => $pa_replacement ) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( is_wp_error( $rpa3 ), 'PA3.1: invalid pattern_type refuses' );
eq( 'snt_pattern_adoption_invalid_pattern_type', $rpa3->get_error_code(), 'PA3.2: the refusal carries the ABSORBED impl\'s own error code — delegation to snt_ai_pattern_adoption_apply_impl, not a re-implementation' );

/* ════════════════════════════════════════════════════════════════════════
 * link_insert — dry_run + revision (delegation pinned by
 * snt_ai_link_already_linked, produced only by the real snt_ai_link_apply_impl)
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nlink_insert: dry_run preview + revision staging + real-impl error-code pin\n";
tf_reset_writes();
$rli1 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 720 ),
	'change' => array( 'type' => 'link_insert', 'fingerprint' => $li_fp, 'payload' => array(
		'anchor' => $li_anchor, 'context_snippet' => '', 'target_url' => 'https://example.test/notes/post-721/', 'target_post_id' => 721,
	) ),
	'mode'   => 'revision', // dry_run defaults true
) );
ok( ! is_wp_error( $rli1 ) && false === $rli1['applied'], 'LI1.1: dry_run previews' );
ok( false !== strpos( (string) ( $rli1['diff']['after'] ?? '' ), '<a href="https://example.test/notes/post-721/">' . $li_anchor . '</a>' ), 'LI1.2: diff.after holds the spliced link exactly as the real impl would write it' );
eq( 0, tf_total_writes(), 'LI1.3: zero writes' );

$li_live_before = $GLOBALS['__posts'][720]['post_content'];
tf_reset_writes();
$rli2 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 720 ),
	'change' => array( 'type' => 'link_insert', 'fingerprint' => $li_fp, 'payload' => array(
		'anchor' => $li_anchor, 'context_snippet' => '', 'target_url' => 'https://example.test/notes/post-721/', 'target_post_id' => 721,
	) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( ! is_wp_error( $rli2 ) && true === $rli2['applied'], 'LI2.1: revision apply succeeds' );
eq( $li_live_before, $GLOBALS['__posts'][720]['post_content'], 'LI2.2: live post byte-identical' );
ok( is_int( $rli2['revision_id'] ) && false !== strpos( $GLOBALS['__posts'][ $rli2['revision_id'] ]['post_content'] ?? '', '</a>' ), 'LI2.3: the staged revision carries the linked content (delegation target: snt_ai_link_apply_impl via write_callback)' );
eq( 0, $GLOBALS['__write_calls']['wp_update_post'], 'LI2.4: wp_update_post never called' );

// Real-impl delegation pin: content whose anchor already sits inside an <a>
// must come back with the link surface's OWN error code.
tf_post( 725, array( 'post_content' => 'Already linked: <a href="https://example.test/x/">' . $li_anchor . '</a> here.' ) );
$li725_pos = strpos( $GLOBALS['__posts'][725]['post_content'], $li_anchor );
$li725_fp  = snt_ai_drift_fingerprint( $GLOBALS['__posts'][725]['post_content'], $li_anchor, $li725_pos );
$rli3 = snt_ability_sn_apply( array(
	'target' => array( 'post_id' => 725 ),
	'change' => array( 'type' => 'link_insert', 'fingerprint' => $li725_fp, 'payload' => array(
		'anchor' => $li_anchor, 'context_snippet' => '', 'target_url' => 'https://example.test/notes/post-721/', 'target_post_id' => 721,
	) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( is_wp_error( $rli3 ), 'LI3.1: already-linked anchor refuses' );
eq( 'snt_ai_link_already_linked', $rli3->get_error_code(), 'LI3.2: the refusal carries the ABSORBED impl\'s own error code (snt_ai_link_position_inside_anchor inside the real snt_ai_link_apply_impl) — delegation, not a re-implementation. (Gate 2\'s not_already_linked mirror is inert in this harness: sn_health_contains_note_link is not loaded, and its check is function_exists-guarded — so the real impl\'s own guard is what fires, which is exactly the delegation pin this test wants.)' );

/* ════════════════════════════════════════════════════════════════════════
 * roadmap_board — board-as-data (the maturity roadmap's option override):
 * fingerprint oracle via dry_run, 409 stale, gate-2 leak-token refusal,
 * publish write, reset back to code-canonical. Delegation pinned by the
 * REAL sn_maturity_roadmap_* helpers loaded above — the board the write
 * changes is the same board the shortcode renders.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nroadmap_board: fingerprint oracle + stale conflict + leak refusal + publish write + reset\n";

$rb_target       = array( 'scope' => 'maturity_roadmap' );
// A REALISTIC override payload: the door's own docblock requires the FULL
// board (wholesale, no per-cell patch shape) — a caller reads the effective
// board via dry_run, edits one cell, and writes the whole thing back. Only
// 'Analytics'.'done' is touched here; every other family/column is carried
// forward byte-for-byte from code, which is what lets RB5.2 and RB7 below
// tell "the override touched this" from "the override just doesn't say."
// (A payload that only ever names the ONE family it edits — this fixture's
// shape before v12.6.0 — is a genuine wholesale delete of every other
// family under the merge's own "absent counts as changed" rule; that is
// unchanged by this session and not what these assertions are testing.)
$rb_static_board = sn_maturity_roadmap_static_board();
$rb_board         = $rb_static_board;
$rb_board['Analytics']['done'] = array( 'A rewritten done sentence for the sweep' );
$rb_fp     = sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() );

// Missing fingerprint: 422 with the type's own code; the encoded response
// carries observed (the current fingerprint) and diff.before (the current
// board) — the documented observe step.
$rrb1 = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'payload' => array( 'board' => $rb_board ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( is_wp_error( $rrb1 ), 'RB1.1: missing fingerprint refuses' );
eq( 'snt_sn_apply_missing_fingerprint', $rrb1->get_error_code(), 'RB1.2: with the 422 missing-fingerprint code (the restore_revision idiom), never the generic 409' );
ok( false !== strpos( (string) $rrb1->get_error_message(), $rb_fp ), 'RB1.3: the refusal carries the CURRENT fingerprint (gates.fingerprint.observed) — the dry-run-as-read-surface contract' );

// Stale fingerprint: the 409 merge conflict.
$rrb2 = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'fingerprint' => 'not-the-current-fingerprint', 'payload' => array( 'board' => $rb_board ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( is_wp_error( $rrb2 ) && 'snt_sn_apply_fingerprint_stale' === $rrb2->get_error_code(), 'RB2.1: a stale fingerprint is the 409 stale-branch conflict' );

// Gate 2: a board carrying a banned internal token refuses as a
// validation failure — the write-gate mirror of the page's leak sweep.
$rrb3 = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'fingerprint' => $rb_fp, 'payload' => array( 'board' => array(
		'Analytics' => array( 'done' => array( 'This sentence names snt_ internals and must never render' ), 'planned' => array(), 'considering' => array() ),
	) ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( is_wp_error( $rrb3 ) && 'snt_sn_apply_validation_failed' === $rrb3->get_error_code(), 'RB3.1: a banned internal token refuses at gate 2 — the leak sweep enforced at the DOOR, not just asserted on the page' );

// mode:revision refuses structurally (publish-only, og_card's posture).
$rrb4 = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'fingerprint' => $rb_fp, 'payload' => array( 'board' => $rb_board ) ),
	'mode'   => 'revision', 'dry_run' => false,
) );
ok( is_wp_error( $rrb4 ) && 'snt_sn_apply_mode_not_granted' === $rrb4->get_error_code(), 'RB4.1: mode:revision refuses — an option has no revision to stage' );

// The publish write: the option lands and the EFFECTIVE board (what the
// shortcode renders) is now the override — delegation to the same helpers
// the render path reads.
tf_reset_writes();
$rrb5 = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'fingerprint' => $rb_fp, 'payload' => array( 'board' => $rb_board ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rrb5 ) && true === $rrb5['applied'], 'RB5.1: publish write applies' );
eq( $rb_board, sn_maturity_roadmap_effective_board(), 'RB5.2: the EFFECTIVE board merges the override over code — the override\'s own edited cell lands, and every family the override didn\'t touch survives (equal to $rb_board here only because $rb_board itself already carries every untouched family forward from code; RB7 below is the version where code moves AFTER this write, which is the case that actually distinguishes a merge from the shadowing this session removes)' );
// v11.5.0: TWO option writes, both deliberate — the board override itself,
// plus the idempotency RECORD that keyless mutating calls now make (the
// auto-key means this call's response is stored for replay; pre-11.5.0 a
// keyless call recorded nothing, which was exactly the audit finding).
eq( 2, $GLOBALS['__write_calls']['update_option'], 'RB5.3: exactly two option writes — the board override + the auto-key replay record, nothing incidental' );
ok( sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() ) !== $rb_fp, 'RB5.4: the fingerprint moved with the board' );

// reset:true deletes the override — back to code-canonical, and the
// pre-write fingerprint is current again.
$rb_fp2 = sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() );
$rrb6   = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'fingerprint' => $rb_fp2, 'payload' => array( 'reset' => true ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rrb6 ) && true === $rrb6['applied'], 'RB6.1: reset applies' );
eq( sn_maturity_roadmap_static_board(), sn_maturity_roadmap_effective_board(), 'RB6.2: the effective board is the static board again — code-canonical restored' );
eq( $rb_fp, sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() ), 'RB6.3: and the original fingerprint is current again' );

// RB7: the merge actually ENGAGES on a write made through the REAL door —
// every merge test elsewhere in the suite (tests/maturity-roadmap-merge*)
// calls snt_roadmap_store_envelope() directly, which proves the merge
// FUNCTION works but never proves sn_apply's write step actually calls it.
// This is the assertion that would have caught the whole defect this
// session exists to close: write one cell through snt_ability_sn_apply(),
// then have CODE move an untouched family (a later release's static-board
// edit — exactly the "WHY THIS EXISTS" scenario documented at the top of
// inc/maturity-roadmap-merge.php), and confirm that code edit lands beside
// the override instead of being shadowed by it.
tf_reset_writes();
$rb7_static_before = sn_maturity_roadmap_static_board();
$rb7_board          = $rb7_static_before;
$rb7_board['Analytics']['done'] = array( 'RB7: the owner rewrote this sentence through the real write door' );
$rb7_fp    = sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() );
$rb7_write = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'fingerprint' => $rb7_fp, 'payload' => array( 'board' => $rb7_board ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rb7_write ) && true === $rb7_write['applied'], 'RB7.1: the override write goes through the REAL sn_apply door, not a direct envelope call' );
// The sibling structurally-identical publish call (RB5.3) makes exactly two
// option writes — the board override plus the auto-key idempotency replay
// record. This call is that same shape (fresh fingerprint, publish, a board
// payload), so it should read alike; if it doesn't, the envelope write
// changed how many option writes a publish performs and that's worth a look,
// not a silent number swap here.
eq( 2, $GLOBALS['__write_calls']['update_option'], 'RB7.1b: exactly two option writes, same as RB5.3 — the board override + the auto-key replay record' );

// Code moves on: a family the override never named changes underneath it.
// snt_roadmap_merge_report() is the same function sn_maturity_roadmap_
// effective_report() calls in production — passing it a hand-advanced
// static board simulates "the plugin shipped a release" without needing to
// redefine sn_maturity_roadmap_static_board() itself.
$rb7_static_after = $rb7_static_before;
$rb7_new_sentence  = 'RB7: a brand-new code-shipped sentence, added after the override write';
$rb7_static_after['Proof of origin']['done'][] = $rb7_new_sentence;
$rb7_report = snt_roadmap_merge_report( $rb7_static_after );

ok( in_array( $rb7_new_sentence, $rb7_report['merged']['Proof of origin']['done'], true ), 'RB7.3: the code edit to a family the override never touched LANDS in the merged board — the exact defect this session closes' );
eq( $rb7_board['Analytics']['done'], $rb7_report['merged']['Analytics']['done'], 'RB7.4: the override\'s own edited cell still holds — code moving elsewhere does not clobber it' );
ok( in_array( array( 'family' => 'Proof of origin', 'column' => 'done' ), $rb7_report['code_landed'], true ), 'RB7.5: the report attributes the landed change to code_landed, not override_held — correct provenance from a REAL write, not a fixture the test hand-assembled' );

// Clean up: back to code-canonical for the sections that follow.
$rrb7 = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'fingerprint' => sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() ), 'payload' => array( 'reset' => true ) ),
	'mode'   => 'publish', 'dry_run' => false,
) );
ok( ! is_wp_error( $rrb7 ) && true === $rrb7['applied'], 'RB7.6: reset applies, restoring code-canonical for the sections below' );

echo "\nroadmap_board: Task 5 — diff.merge exposes drift BEFORE a write\n";

// RB8: a dry run with NO override stored (exactly the state RB7.6 just left
// behind) exposes diff.merge with all three lists EMPTY. Quiet is the
// normal case, and it must be REPORTED quiet, not simply absent — an absent
// key and an empty one look identical to a careless caller that only checks
// truthiness, and that's the whole difference between "no drift" and "this
// build doesn't report drift". $rb_board is RB1-7's own fixture (a full,
// valid board), reused here only so the dry run's gates pass cleanly.
$rb8_fp  = sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() );
$rb8_dry = snt_ability_sn_apply( array(
	'target' => $rb_target,
	'change' => array( 'type' => 'roadmap_board', 'fingerprint' => $rb8_fp, 'payload' => array( 'board' => $rb_board ) ),
	'mode'   => 'publish', 'dry_run' => true,
) );
ok( ! is_wp_error( $rb8_dry ), 'RB8.1: the dry run itself succeeds' );
ok( is_array( $rb8_dry['diff'] ) && array_key_exists( 'merge', $rb8_dry['diff'] ), 'RB8.2: diff.merge EXISTS on a quiet dry run — not merely absent' );
eq( array(), $rb8_dry['diff']['merge']['conflicts'], 'RB8.3: diff.merge.conflicts is empty with no override stored' );
eq( array(), $rb8_dry['diff']['merge']['code_landed'], 'RB8.4: diff.merge.code_landed is empty with no override stored' );
eq( array(), $rb8_dry['diff']['merge']['override_held'], 'RB8.5: diff.merge.override_held is empty with no override stored' );
// RB8.6: same argument as RB8.2, applied to `invalid` — an absent key and a
// `false` one look identical to a careless caller, so the key must be
// PRESENT and false in the quiet case, not merely falsy-by-omission.
ok( array_key_exists( 'invalid', $rb8_dry['diff']['merge'] ), 'RB8.6: diff.merge.invalid EXISTS on a quiet dry run — not merely absent' );
ok( false === $rb8_dry['diff']['merge']['invalid'], 'RB8.7: diff.merge.invalid is false with no override stored' );

// RB9: a dry run while a CONFLICT exists names the conflicting cell in
// diff.merge.conflicts. Built the honest way per the task brief: a real
// write, then the static board "advancing" on the same cell. RB7 above
// proved code_landed works this way by calling snt_roadmap_merge_report()
// directly with a hand-advanced static board — but sn_maturity_roadmap_
// static_board() is a hard-coded PHP function this test cannot redefine to
// simulate a second, conflicting release. So this reaches ONE layer lower
// than RB7: snt_roadmap_store_envelope() lets a caller set the envelope's
// recorded `base` directly (which is exactly what a real write does,
// stamping it with the static board AT THAT MOMENT — see inc/sn-apply-
// roadmap-board.php's write step) — a synthetic, deliberately-stale `base`
// stands in for "code shipped a release since this override was written".
// Everything downstream of that write — sn_maturity_roadmap_effective_
// report(), snt_roadmap_merge_report(), snt_roadmap_merge(), and the REAL
// current sn_maturity_roadmap_static_board() — runs for real; only the
// stored base is synthetic. The layer reached: snt_sn_apply_roadmap_board_
// diff() is called DIRECTLY (the function this task modifies), not through
// the full snt_ability_sn_apply() door — a real write's gate 1 always
// stamps base from the live static board, which can never differ from
// itself within one test run.
$rb9_static = sn_maturity_roadmap_static_board();
$rb9_base   = $rb9_static;
$rb9_base['Analytics']['done'] = array( 'RB9: a synthetic OLD base sentence, never actually shipped by code' );
$rb9_override_board = $rb9_static;
$rb9_override_board['Analytics']['done'] = array( "RB9: the override's own edit to this cell" );
snt_roadmap_store_envelope( $rb9_override_board, $rb9_base );
$rb9_diff = snt_sn_apply_roadmap_board_diff( array( 'payload' => array( 'board' => $rb9_override_board ) ) );
ok( in_array( array( 'family' => 'Analytics', 'column' => 'done' ), $rb9_diff['merge']['conflicts'], true ), 'RB9.1: the conflicting cell is named in diff.merge.conflicts BEFORE any write — code and the override both moved Analytics.done since base' );
eq( $rb9_override_board['Analytics']['done'], $rb9_diff['before']['Analytics']['done'], 'RB9.2: a conflict still resolves to the OVERRIDE\'s value in diff.before, per the merge\'s own "a conflict renders the override" rule' );
delete_option( 'snt_maturity_roadmap_board' );

// RB10: diff.before equals the MERGED board, not the raw stored override.
// Distinguishing case: the override's stored snapshot holds a STALE cell
// (Analytics.planned) it never touched, so the raw override and the merged
// board disagree there by construction — code_landed's current value must
// win in diff.before, never the override's old copy. Same layer as RB9
// (snt_sn_apply_roadmap_board_diff() called directly), for the same reason.
$rb10_static = sn_maturity_roadmap_static_board();
$rb10_base   = $rb10_static;
$rb10_base['Analytics']['planned'] = array( "RB10: an OLD planned sentence, the override's stale snapshot of this cell" );
$rb10_override_board = $rb10_base; // the override never touched this cell: its stored board IS the stale base.
snt_roadmap_store_envelope( $rb10_override_board, $rb10_base );
$rb10_diff = snt_sn_apply_roadmap_board_diff( array( 'payload' => array() ) );
eq( sn_maturity_roadmap_effective_board(), $rb10_diff['before'], 'RB10.1: diff.before equals sn_maturity_roadmap_effective_board() exactly — the same merged read surface, computed once' );
eq( $rb10_static['Analytics']['planned'], $rb10_diff['before']['Analytics']['planned'], 'RB10.2: diff.before carries CODE\'s current value for a cell only code moved...' );
ok( $rb10_diff['before']['Analytics']['planned'] !== $rb10_override_board['Analytics']['planned'], 'RB10.3: ...and that value is NOT the raw stored override\'s stale snapshot' );
delete_option( 'snt_maturity_roadmap_board' );

/* ════════════════════════════════════════════════════════════════════════
 * ALL EIGHT change types: structural dry_run zero-writes sweep (session-4
 * recorder pattern — loop the ENUM itself, so a future ninth type that
 * skips this table fails the count assertion automatically).
 * ════════════════════════════════════════════════════════════════════════ */
// sentence_replace fixture: whole-post content_hash fingerprint (the
// composing-caller binding), a sentence-scale span copied byte-exactly.
tf_post( 780, array( 'post_content' => '<!-- wp:paragraph --><p>This is a deliberately long sentence that the sweep will replace, byte-exactly, with two shorter ones.</p><!-- /wp:paragraph -->' ) );
$sr_phrase = 'This is a deliberately long sentence that the sweep will replace, byte-exactly, with two shorter ones.';
$sr_fp     = snt_corpus_content_hash( $GLOBALS['__posts'][780]['post_content'] );

// delete_draft fixture: a DRAFT post (the only status the type accepts) with
// the content_hash fingerprint create_draft's rollback object would carry.
tf_post( 790, array( 'post_status' => 'draft', 'post_content' => '<!-- wp:paragraph --><p>An abandoned draft the sweep will preview trashing.</p><!-- /wp:paragraph -->' ) );
$dd_fp = snt_corpus_content_hash( $GLOBALS['__posts'][790]['post_content'] );

// link_reshape fixture: a post with one anchor whose boundaries the sweep
// will preview moving; fingerprint = live content_hash (sentence_replace's binding).
tf_post( 795, array( 'post_content' => '<!-- wp:paragraph --><p>Intro sentence here. <a href="/notes/target/">The whole overlong anchor text</a>. Outro sentence here.</p><!-- /wp:paragraph -->' ) );
$lr_fp = snt_corpus_content_hash( $GLOBALS['__posts'][795]['post_content'] );

echo "\nStructural sweep: every change type's dry_run path writes NOTHING\n";
$sweep_calls = array(
	'block_migration'  => array( 'target' => array( 'post_id' => 750 ), 'mode' => 'revision', 'change' => array( 'type' => 'block_migration', 'fingerprint' => $bm_fp, 'payload' => array( 'migration_type' => 'heading-hierarchy-skip', 'replacement_markup' => $bm_replacement ) ) ),
	'pattern_adoption' => array( 'target' => array( 'post_id' => 710 ), 'mode' => 'revision', 'change' => array( 'type' => 'pattern_adoption', 'fingerprint' => $pa_fp, 'payload' => array( 'pattern_type' => 'pull-quote', 'replacement_markup' => $pa_replacement ) ) ),
	'alt_text'         => array( 'target' => array( 'attachment_id' => 740 ), 'mode' => 'revision', 'change' => array( 'type' => 'alt_text', 'payload' => array( 'text' => 'A generic but valid alt text for the sweep fixture' ) ) ),
	'link_insert'      => array( 'target' => array( 'post_id' => 720 ), 'mode' => 'revision', 'change' => array( 'type' => 'link_insert', 'fingerprint' => $li_fp, 'payload' => array( 'anchor' => $li_anchor, 'context_snippet' => '', 'target_url' => 'https://example.test/notes/post-721/', 'target_post_id' => 721 ) ) ),
	'drift_replace'    => array( 'target' => array( 'post_id' => 760 ), 'mode' => 'revision', 'change' => array( 'type' => 'drift_replace', 'fingerprint' => $dr_fp, 'payload' => array( 'phrase' => $dr_phrase, 'replacement' => 'in June 2026', 'position' => $dr_pos, 'context_snippet' => '' ) ) ),
	'emdash_replace'   => array( 'target' => array( 'post_id' => 761 ), 'mode' => 'revision', 'change' => array( 'type' => 'emdash_replace', 'fingerprint' => $em_fp, 'payload' => array( 'phrase' => $em_phrase, 'replacement' => $em_repl, 'position' => $em_pos, 'context_snippet' => '' ) ) ),
	'surfaces'         => array( 'target' => array( 'post_id' => 700 ), 'mode' => 'revision', 'change' => array( 'type' => 'surfaces', 'payload' => array( 'excerpt' => 'Sweep excerpt proposal, plainly long enough to pass the validation gate without any warnings raised at all.' ) ) ),
	// The two publish-only types run the sweep in publish mode: dry_run must
	// still preview (all four gates + diff) with zero side effects.
	'og_card'          => array( 'target' => array( 'post_id' => 730 ), 'mode' => 'publish', 'change' => array( 'type' => 'og_card', 'payload' => array() ) ),
	'anchor_sweep'     => array( 'target' => array( 'scope' => 'provenance_anchors' ), 'mode' => 'publish', 'change' => array( 'type' => 'anchor_sweep', 'payload' => array() ) ),
	// create_draft (session 6c) is REVISION-only (mode:publish refuses
	// structurally — see snt_sn_apply_mode_support()), the mirror image of
	// og_card/anchor_sweep's publish-only posture above.
	'create_draft'     => array( 'target' => array( 'new_post' => true ), 'mode' => 'revision', 'change' => array( 'type' => 'create_draft', 'payload' => array( 'title' => 'Sweep draft title', 'content' => $cd_content ) ) ),
	// restore_revision (session 7) is PUBLISH-only — the mirror image of
	// create_draft's REVISION-only posture, same mode_support mechanism.
	'restore_revision' => array( 'target' => array( 'post_id' => 770 ), 'mode' => 'publish', 'change' => array( 'type' => 'restore_revision', 'fingerprint' => $rr_fp, 'payload' => array( 'revision_id' => 771 ) ) ),
	// sentence_replace: the agent-composed body edit — fingerprint is the
	// LIVE content_hash (restore_revision's binding), producible by any
	// caller via sn_posts; the only body type with no scan/suggest mint.
	'sentence_replace' => array( 'target' => array( 'post_id' => 780 ), 'mode' => 'revision', 'change' => array( 'type' => 'sentence_replace', 'fingerprint' => $sr_fp, 'payload' => array( 'phrase' => $sr_phrase, 'replacement' => 'This is a shorter sentence. It has a sibling now.', 'context_snippet' => '' ) ) ),
	// roadmap_board: board-as-data — PUBLISH-only (option write, og_card's
	// posture); fingerprint = the CURRENT effective board's hash, computed
	// by the same real helper the write path binds to.
	'roadmap_board'    => array( 'target' => array( 'scope' => 'maturity_roadmap' ), 'mode' => 'publish', 'change' => array( 'type' => 'roadmap_board', 'fingerprint' => sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() ), 'payload' => array( 'board' => array( 'Analytics' => array( 'done' => array( 'A sweep-only replacement sentence' ), 'planned' => array(), 'considering' => array() ) ) ) ) ),
	// delete_draft (v10.58.0, audit item 6): create_draft's mirror —
	// REVISION-only, trash-only, draft-only; fingerprint = the draft's
	// content_hash (create_draft's rollback object carries it).
	'delete_draft'     => array( 'target' => array( 'post_id' => 790 ), 'mode' => 'revision', 'change' => array( 'type' => 'delete_draft', 'fingerprint' => $dd_fp, 'payload' => array() ) ),
	// link_reshape (v10.58.0, audit item 5): tag-boundary movement — the
	// fingerprint is the live content_hash, new_anchor a unique contiguous
	// substring of current_anchor.
	'link_reshape'     => array( 'target' => array( 'post_id' => 795 ), 'mode' => 'revision', 'change' => array( 'type' => 'link_reshape', 'fingerprint' => $lr_fp, 'payload' => array( 'current_anchor' => 'The whole overlong anchor text', 'new_anchor' => 'overlong anchor' ) ) ),
	// unlink (v10.59.0): link_reshape's promised sibling — same fixture post,
	// remove the wrapper, keep the text.
	'unlink'           => array( 'target' => array( 'post_id' => 795 ), 'mode' => 'revision', 'change' => array( 'type' => 'unlink', 'fingerprint' => $lr_fp, 'payload' => array( 'anchor_text' => 'The whole overlong anchor text' ) ) ),
);
eq( count( SNT_SN_APPLY_CHANGE_TYPES ), count( $sweep_calls ), 'SWEEP.0: the sweep table covers the FULL enum — a new change type added to SNT_SN_APPLY_CHANGE_TYPES fails here until it joins the sweep' );
foreach ( SNT_SN_APPLY_CHANGE_TYPES as $sweep_type ) {
	ok( isset( $sweep_calls[ $sweep_type ] ), "SWEEP.has: a sweep case exists for '$sweep_type'" );
	if ( ! isset( $sweep_calls[ $sweep_type ] ) ) { continue; }
	$case = $sweep_calls[ $sweep_type ];
	tf_reset_writes();
	$posts_snap = $GLOBALS['__posts'];
	$meta_snap  = $GLOBALS['__post_meta'];
	$opts_snap  = $GLOBALS['__options'];
	$res = snt_ability_sn_apply( array(
		'target' => $case['target'],
		'change' => $case['change'],
		'mode'   => $case['mode'],
		// dry_run omitted on purpose — the DEFAULT must be the safe path.
	) );
	ok( ! is_wp_error( $res ) && false === ( $res['applied'] ?? null ), "SWEEP.$sweep_type: dry_run (defaulted) previews without applying" );
	eq( 0, tf_total_writes(), "SWEEP.$sweep_type: ZERO write-primitive + side-effect calls" );
	eq( $posts_snap, $GLOBALS['__posts'], "SWEEP.$sweep_type: post store byte-identical" );
	eq( $meta_snap, $GLOBALS['__post_meta'], "SWEEP.$sweep_type: postmeta store byte-identical" );
	eq( $opts_snap, $GLOBALS['__options'], "SWEEP.$sweep_type: options store byte-identical (no staged-meta or idempotency row from a dry run without a key)" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
