<?php
/**
 * Standalone tests for signal-noise/update-post-surfaces (v10.7.0) — the
 * reviewed-text apply step. Stubs model the REAL callee shapes including
 * failure: wp_update_post( …, true ) returns WP_Error on failure (not
 * false), sanitize_* model core's strip/normalize transform, and
 * sn_generate_og_card returns bool.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}

// ── Fixture store ────────────────────────────────────────────────────
$GLOBALS['__posts'] = array(
	10 => (object) array( 'ID' => 10, 'post_status' => 'future', 'post_type' => 'post', 'post_excerpt' => 'old' ),
	11 => (object) array( 'ID' => 11, 'post_status' => 'trash', 'post_type' => 'post', 'post_excerpt' => '' ),
	12 => (object) array( 'ID' => 12, 'post_status' => 'publish', 'post_type' => 'post', 'post_excerpt' => '' ),
	13 => (object) array( 'ID' => 13, 'post_status' => 'inherit', 'post_type' => 'revision', 'post_excerpt' => '' ),
	14 => (object) array( 'ID' => 14, 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_excerpt' => '' ),
);
$GLOBALS['__meta']         = array();
$GLOBALS['__meta_deleted'] = array();
$GLOBALS['__updates']      = array();
$GLOBALS['__wp_update_post_fail'] = false;
$GLOBALS['__card_calls']   = array();

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $arr, $wp_error = false ) {
		if ( $GLOBALS['__wp_update_post_fail'] ) {
			// Real shape: WP_Error when $wp_error=true, 0 otherwise.
			return $wp_error ? new WP_Error( 'db_update_error', 'Could not update post.' ) : 0;
		}
		$GLOBALS['__updates'][] = $arr;
		return (int) $arr['ID'];
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) { $GLOBALS['__meta'][ $id ][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $k ) { $GLOBALS['__meta_deleted'][] = "$id:$k"; return true; }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	// Core: strips tags, normalizes whitespace runs (keeps newlines), trims.
	function sanitize_textarea_field( $s ) { return trim( preg_replace( '/[ \t]+/', ' ', strip_tags( (string) $s ) ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
}
if ( ! function_exists( 'sn_generate_og_card' ) ) {
	function sn_generate_og_card( $id ) { $GLOBALS['__card_calls'][] = (int) $id; return true; }
}
$GLOBALS['__transients'] = array();
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) { return $v; }
}
// Corpus gates (SNT_CORPUS_STATUSES + snt_corpus_post_type_allowed) come from
// the real corpus-inspect.php — the target contract is genuinely shared.
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'revision', 'attachment' ), true ); }
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $t ) {
		if ( ! post_type_exists( $t ) ) { return null; }
		$o = new stdClass();
		$o->public = in_array( $t, array( 'post', 'page', 'attachment' ), true ); // attachment IS public in real WP
		return $o;
	}
}

require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/abilities-update-post-surfaces.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "update-post-surfaces — plugin v10.7.0\n\n";

// ── Registration ─────────────────────────────────────────────────────
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$a = $GLOBALS['__abilities']['signal-noise/update-post-surfaces'] ?? null;
ok( is_array( $a ), 'ability is registered' );
ok( 'snt_ability_perm_edit_post' === ( $a['permission_callback'] ?? '' ), 'gates on edit_post for the target post' );
ok( array( 'post_id' ) === ( $a['input_schema']['required'] ?? array() ), 'only post_id is schema-required' );
ok( isset( $a['input_schema']['properties']['seo_title'], $a['input_schema']['properties']['focus_keyword'] ), 'seo_title and focus_keyword are writable surfaces' );
ok( false === ( $a['meta']['annotations']['destructive'] ?? true ), 'annotated non-destructive (revision-backed overwrite)' );
ok( 'tools' === ( $a['category'] ?? '' ), 'category tools — no AI anywhere' );

// ── Happy path: all five surfaces at once ────────────────────────────
$r = snt_ability_update_post_surfaces( array(
	'post_id'          => 10,
	'excerpt'          => 'New excerpt.',
	'meta_description' => 'New description.',
	'og_card_title'    => 'New card title',
	'seo_title'        => 'New SEO title',
	'focus_keyword'    => 'provenance',
) );
ok( is_array( $r ) && true === $r['ok'], 'write returns ok' );
ok( $r['updated'] === array( 'excerpt', 'meta_description', 'og_card_title', 'seo_title', 'focus_keyword' ), 'all five surfaces reported updated' );
ok( $GLOBALS['__updates'][0] === array( 'ID' => 10, 'post_excerpt' => 'New excerpt.' ), 'excerpt goes through wp_update_post (revision path)' );
ok( ( $GLOBALS['__meta'][10]['_sn_meta_description'] ?? '' ) === 'New description.', 'meta description written to _sn_meta_description' );
ok( ( $GLOBALS['__meta'][10]['_sn_og_card_title'] ?? '' ) === 'New card title', 'OG title written to _sn_og_card_title' );
ok( ( $GLOBALS['__meta'][10]['_sn_seo_title'] ?? '' ) === 'New SEO title', 'SEO title written to _sn_seo_title' );
ok( ( $GLOBALS['__meta'][10]['_sn_focus_keyword'] ?? '' ) === 'provenance', 'focus keyword written to _sn_focus_keyword' );
ok( in_array( '10:_sn_autogen_excerpt', $GLOBALS['__meta_deleted'], true )
	&& in_array( '10:_sn_autogen_meta_description', $GLOBALS['__meta_deleted'], true )
	&& in_array( '10:_sn_autogen_og_card_title', $GLOBALS['__meta_deleted'], true ), 'autogen sentinels cleared for the three prepop surfaces' );
ok( $GLOBALS['__card_calls'] === array( 10 ) && true === $r['card_regenerated'], 'OG card PNG regenerated once and reported' );

// ── Partial write: only meta description ─────────────────────────────
$GLOBALS['__updates'] = array(); $GLOBALS['__card_calls'] = array();
$r = snt_ability_update_post_surfaces( array( 'post_id' => 12, 'meta_description' => 'Only this.' ) );
ok( $r['updated'] === array( 'meta_description' ), 'partial write updates only the supplied surface' );
ok( array() === $GLOBALS['__updates'] && array() === $GLOBALS['__card_calls'], 'no excerpt update, no card regeneration on a meta-only write' );
ok( false === $r['card_regenerated'], 'card_regenerated honestly false when the card was not touched' );

// ── Sanitization models the core transform ───────────────────────────
$r = snt_ability_update_post_surfaces( array( 'post_id' => 12, 'og_card_title' => "  Spaced\n<b>title</b>  " ) );
ok( ( $GLOBALS['__meta'][12]['_sn_og_card_title'] ?? '' ) === 'Spaced title', 'og title is tag-stripped and whitespace-normalized' );

// ── Failure shapes ───────────────────────────────────────────────────
$r = snt_ability_update_post_surfaces( array( 'post_id' => 999, 'excerpt' => 'x' ) );
ok( is_wp_error( $r ) && 'snt_surfaces_post_not_found' === $r->get_error_code(), 'unknown post → 404 error' );
$r = snt_ability_update_post_surfaces( array( 'post_id' => 11, 'excerpt' => 'x' ) );
ok( is_wp_error( $r ), 'trashed post → error' );
$r = snt_ability_update_post_surfaces( array( 'post_id' => 13, 'excerpt' => 'x' ) );
ok( is_wp_error( $r ), 'revision (inherit status, internal type) → error, never writable' );
$r = snt_ability_update_post_surfaces( array( 'post_id' => 14, 'meta_description' => 'x' ) );
ok( is_wp_error( $r ), 'attachment (public type but non-corpus status) → error — surfaces are a post/page contract' );
$r = snt_ability_update_post_surfaces( array( 'post_id' => 10 ) );
ok( is_wp_error( $r ) && 'snt_surfaces_nothing_to_write' === $r->get_error_code(), 'post_id alone → 422 nothing-to-write' );

$GLOBALS['__wp_update_post_fail'] = true;
$before_meta = $GLOBALS['__meta'][10];
$r = snt_ability_update_post_surfaces( array( 'post_id' => 10, 'excerpt' => 'x', 'meta_description' => 'should not land' ) );
ok( is_wp_error( $r ) && 'db_update_error' === $r->get_error_code(), 'wp_update_post failure surfaces as the WP_Error, not swallowed' );
ok( $GLOBALS['__meta'][10] === $before_meta, 'a failed excerpt write aborts before any meta writes land' );
$GLOBALS['__wp_update_post_fail'] = false;

// ── Impl-level length caps (v10.9.0): reject, never truncate ─────────
$before_count = (int) ( $GLOBALS['__transients']['snt_surfaces_writes_12'] ?? 0 );
$r = snt_ability_update_post_surfaces( array( 'post_id' => 12, 'excerpt' => str_repeat( 'x', 1001 ) ) );
ok( is_wp_error( $r ) && 'snt_surfaces_too_long' === $r->get_error_code(), 'over-cap excerpt → 422 rejection, never silent truncation' );
ok( false !== strpos( $r->message, 'excerpt' ), 'over-cap error names the offending field' );
$r = snt_ability_update_post_surfaces( array( 'post_id' => 12, 'focus_keyword' => str_repeat( 'k', 81 ) ) );
ok( is_wp_error( $r ) && 'snt_surfaces_too_long' === $r->get_error_code(), '81-char focus keyword → rejected at the impl even without schema validation' );
ok( ( $GLOBALS['__transients']['snt_surfaces_writes_12'] ?? 0 ) === $before_count, 'rejected writes consume NO throttle quota' );

// ── Per-post throttle (v10.9.0): 5 successful writes per window ──────
$GLOBALS['__transients'] = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$r = snt_ability_update_post_surfaces( array( 'post_id' => 12, 'meta_description' => "write $i" ) );
	if ( ! is_array( $r ) || true !== ( $r['ok'] ?? false ) ) { ok( false, "throttle: write $i within the cap succeeds" ); }
}
ok( 5 === (int) ( $GLOBALS['__transients']['snt_surfaces_writes_12'] ?? 0 ), 'five successful writes counted in the per-post window' );
$r = snt_ability_update_post_surfaces( array( 'post_id' => 12, 'meta_description' => 'write 6' ) );
ok( is_wp_error( $r ) && 'snt_surfaces_throttled' === $r->get_error_code(), 'sixth write inside the window → 429 throttled' );
ok( ( $r->data['status'] ?? 0 ) === 429, 'throttle rejection carries HTTP 429' );
$r = snt_ability_update_post_surfaces( array( 'post_id' => 10, 'meta_description' => 'other post' ) );
ok( is_array( $r ) && true === $r['ok'], 'the throttle is PER POST — a different post still writes' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
