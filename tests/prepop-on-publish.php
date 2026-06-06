<?php
/**
 * Tests inc/ai-prepopulate.php — the draft->publish trigger guard and the
 * snt_run_prepop engine (empty-only fill, sentinels, skip conditions).
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// ─── WP stubs ───
$GLOBALS['__test_post_meta']  = array();
$GLOBALS['__test_scheduled']  = array();
$GLOBALS['__test_posts']      = array();
$GLOBALS['__test_updated']    = array();
$GLOBALS['__ai_available']    = true;

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__test_post_meta'][ $id ][ $key ] ?? ( $single ? '' : array() ); }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $val ) { $GLOBALS['__test_post_meta'][ $id ][ $key ] = $val; return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key ) { unset( $GLOBALS['__test_post_meta'][ $id ][ $key ] ); return true; }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['__test_posts'][ $id ] ?? null; }
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $arr ) { $GLOBALS['__test_updated'][] = $arr; if ( isset( $arr['post_excerpt'], $GLOBALS['__test_posts'][ $arr['ID'] ] ) ) { $GLOBALS['__test_posts'][ $arr['ID'] ]->post_excerpt = $arr['post_excerpt']; } return $arr['ID']; }
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['__test_scheduled'][] = array( 'hook' => $hook, 'args' => $args ); return true; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( $s ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'nonce'; } }
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $id = 0 ) { return ! empty( $GLOBALS['__can_edit_post'] ); }
}
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $d ) { return $d; } }
class PP_Req { private $p; public function __construct( $p ) { $this->p = $p; } public function get_param( $k ) { return $this->p[ $k ] ?? null; } }

// AI availability + impls (stubbed — Task 2 covers impl behavior).
if ( ! function_exists( 'snt_ai_is_available' ) ) {
	function snt_ai_is_available() { return ! empty( $GLOBALS['__ai_available'] ); }
}
if ( ! function_exists( 'snt_ai_meta_desc_impl' ) ) {
	function snt_ai_meta_desc_impl( $id, $concise = false ) { $GLOBALS['__concise_seen']['meta'] = $concise; return array( 'ok' => true, 'description' => 'Generated meta.', 'length' => 14 ); }
}
if ( ! function_exists( 'snt_ai_excerpt_impl' ) ) {
	function snt_ai_excerpt_impl( $id, $concise = false ) { $GLOBALS['__concise_seen']['excerpt'] = $concise; return array( 'ok' => true, 'excerpt' => 'Generated excerpt.', 'length' => 18, 'words' => 2 ); }
}
if ( ! function_exists( 'snt_ai_og_card_title_impl' ) ) {
	function snt_ai_og_card_title_impl( $id ) { $GLOBALS['__test_post_meta'][ $id ]['_sn_og_card_title'] = 'Gen Title'; return array( 'ok' => true, 'title' => 'Gen Title', 'length' => 9, 'card_regenerated' => true, 'card_url' => 'https://x/c.png' ); }
}

require_once __DIR__ . '/../inc/ai-prepopulate.php';

$pass = 0; $fail = 0;
function pp_true( $c, $msg ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
function pp_eq( $e, $a, $msg ) { global $pass, $fail; if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n"; } }
function pp_post( $id, $status, $words = 200, $excerpt = '' ) {
	$p = new stdClass(); $p->ID = $id; $p->post_status = $status; $p->post_type = 'post';
	$p->post_content = str_repeat( 'word ', $words ); $p->post_excerpt = $excerpt;
	$GLOBALS['__test_posts'][ $id ] = $p; return $p;
}
function pp_reset() { $GLOBALS['__test_scheduled'] = array(); $GLOBALS['__test_post_meta'] = array(); $GLOBALS['__test_updated'] = array(); $GLOBALS['__concise_seen'] = array(); $GLOBALS['__ai_available'] = true; }

// ── Trigger guard ──
pp_reset(); $p = pp_post( 10, 'publish' );
snt_prepop_on_transition( 'publish', 'draft', $p );
pp_eq( 1, count( $GLOBALS['__test_scheduled'] ), 'draft->publish schedules a prepop event' );
pp_eq( 'snt_prepop_event', $GLOBALS['__test_scheduled'][0]['hook'] ?? '', 'scheduled hook is snt_prepop_event' );
pp_eq( array( 10 ), $GLOBALS['__test_scheduled'][0]['args'] ?? array(), 'scheduled with the post id' );

pp_reset(); $p = pp_post( 11, 'future' );
snt_prepop_on_transition( 'future', 'draft', $p );
pp_eq( 1, count( $GLOBALS['__test_scheduled'] ), 'draft->future (scheduled) also schedules prepop' );

pp_reset(); $p = pp_post( 12, 'publish' );
snt_prepop_on_transition( 'publish', 'publish', $p );
pp_eq( 0, count( $GLOBALS['__test_scheduled'] ), 'publish->publish re-save does NOT schedule' );

pp_reset(); $p = pp_post( 13, 'publish' ); $p->post_type = 'attachment';
snt_prepop_on_transition( 'publish', 'draft', $p );
pp_eq( 0, count( $GLOBALS['__test_scheduled'] ), 'non-post/page type does NOT schedule' );

// ── Engine: fills empty fields, sets sentinels, passes concise ──
pp_reset(); pp_post( 20, 'publish', 200, '' );
snt_run_prepop( 20 );
pp_eq( 'Generated meta.', get_post_meta( 20, '_sn_meta_description', true ), 'empty meta description is filled' );
pp_true( ! empty( $GLOBALS['__test_updated'] ) && 'Generated excerpt.' === $GLOBALS['__test_updated'][0]['post_excerpt'], 'empty excerpt written via wp_update_post' );
pp_eq( 'Gen Title', get_post_meta( 20, '_sn_og_card_title', true ), 'empty OG card title filled (impl self-persists)' );
pp_eq( '1', get_post_meta( 20, '_sn_autogen_meta_description', true ), 'meta-desc sentinel set' );
pp_eq( '1', get_post_meta( 20, '_sn_autogen_excerpt', true ), 'excerpt sentinel set' );
pp_eq( '1', get_post_meta( 20, '_sn_autogen_og_card_title', true ), 'og-title sentinel set' );
pp_true( true === ( $GLOBALS['__concise_seen']['meta'] ?? null ), 'meta-desc impl called with concise=true' );
pp_true( true === ( $GLOBALS['__concise_seen']['excerpt'] ?? null ), 'excerpt impl called with concise=true' );

// ── Engine: skips already-populated fields ──
pp_reset(); $p = pp_post( 21, 'publish', 200, 'Existing excerpt.' );
$GLOBALS['__test_post_meta'][21]['_sn_meta_description'] = 'Existing meta.';
$GLOBALS['__test_post_meta'][21]['_sn_og_card_title']   = 'Existing title.';
snt_run_prepop( 21 );
pp_eq( 'Existing meta.', get_post_meta( 21, '_sn_meta_description', true ), 'populated meta description is NOT overwritten' );
pp_eq( 0, count( $GLOBALS['__test_updated'] ), 'populated excerpt is NOT overwritten (no wp_update_post)' );
pp_eq( '', get_post_meta( 21, '_sn_autogen_meta_description', true ), 'no sentinel when field already populated' );

// ── Engine: skips when AI unavailable ──
pp_reset(); pp_post( 22, 'publish', 200, '' ); $GLOBALS['__ai_available'] = false;
snt_run_prepop( 22 );
pp_eq( '', get_post_meta( 22, '_sn_meta_description', true ), 'AI unavailable → no generation' );

// ── Engine: skips short content ──
pp_reset(); pp_post( 23, 'publish', 10, '' );
snt_run_prepop( 23 );
pp_eq( '', get_post_meta( 23, '_sn_meta_description', true ), 'content under min words → no generation' );

// ── Notice render ──
pp_reset();
$GLOBALS['__test_post_meta'][30]['_sn_autogen_meta_description'] = '1';
$GLOBALS['__test_post_meta'][30]['_sn_autogen_excerpt']         = '1';
$p = new stdClass(); $p->ID = 30;
ob_start(); sn_prepop_render_notice( $p ); $html = ob_get_clean();
pp_true( false !== strpos( $html, 'sn-prepop-notice' ), 'notice renders when sentinels set' );
pp_true( false !== strpos( $html, 'meta description' ) && false !== strpos( $html, 'excerpt' ), 'notice lists the auto-generated fields' );
pp_true( false === strpos( $html, 'OG card title' ), 'notice omits fields without a sentinel' );

pp_reset();
$p = new stdClass(); $p->ID = 31;
ob_start(); sn_prepop_render_notice( $p ); $html = ob_get_clean();
pp_eq( '', trim( $html ), 'no notice when no sentinels set' );

// ── Sentinel clear on save ──
pp_reset();
$GLOBALS['__test_post_meta'][32]['_sn_autogen_meta_description'] = '1';
$GLOBALS['__test_post_meta'][32]['_sn_autogen_excerpt']         = '1';
$GLOBALS['__test_post_meta'][32]['_sn_autogen_og_card_title']   = '1';
sn_prepop_clear_sentinels( 32 );
pp_eq( '', get_post_meta( 32, '_sn_autogen_meta_description', true ), 'save clears meta-desc sentinel' );
pp_eq( '', get_post_meta( 32, '_sn_autogen_excerpt', true ), 'save clears excerpt sentinel' );
pp_eq( '', get_post_meta( 32, '_sn_autogen_og_card_title', true ), 'save clears og-title sentinel' );

// ── Dismiss handler ──
pp_reset(); $GLOBALS['__can_edit_post'] = true;
$GLOBALS['__test_post_meta'][40]['_sn_autogen_meta_description'] = '1';
$resp = snt_prepop_dismiss_rest_handler( new PP_Req( array( 'post_id' => 40 ) ) );
pp_eq( '', get_post_meta( 40, '_sn_autogen_meta_description', true ), 'dismiss clears the sentinel' );
pp_true( is_array( $resp ) && ! empty( $resp['ok'] ), 'dismiss returns ok' );

pp_reset(); $GLOBALS['__can_edit_post'] = false;
pp_true( false === snt_prepop_dismiss_rest_permission( new PP_Req( array( 'post_id' => 41 ) ) ), 'dismiss permission denied without edit_post' );
pp_reset(); $GLOBALS['__can_edit_post'] = true;
pp_true( true === snt_prepop_dismiss_rest_permission( new PP_Req( array( 'post_id' => 41 ) ) ), 'dismiss permission granted with edit_post' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
