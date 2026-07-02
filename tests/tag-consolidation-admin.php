<?php
/**
 * CLI fixture for inc/tag-consolidation-admin.php — the Content > Tags sub-tab.
 * Run: php tests/tag-consolidation-admin.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$fails = 0; $passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function __( $s, $d = null ) { return $s; }
function number_format_i18n( $n ) { return (string) $n; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function wp_nonce_field( $a = '', $n = '', $r = true, $e = true ) { $f = '<input name="_wpnonce" value="nonce">'; if ( $e ) { echo $f; } return $f; }
function current_user_can( $c ) { return $GLOBALS['__cap'] ?? true; }
function wp_unslash( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function absint( $n ) { return abs( (int) $n ); }

// Logic seams the admin calls.
$GLOBALS['__clusters'] = array();
$GLOBALS['__preview']  = null;
$GLOBALS['__hist']     = array();
$GLOBALS['__alltags']  = array();
function sn_tag_find_duplicate_clusters() { return $GLOBALS['__clusters']; }
// Input-aware: mirrors the real validate (empty/invalid $from -> WP_Error), so the
// render's $_GET parse is actually exercised (a blind stub hid the array-vs-string bug).
function sn_tag_merge_preview( $f, $i ) { return ( is_array( $f ) && $f && $i ) ? $GLOBALS['__preview'] : new WP_Error(); }
function get_option( $k, $d = false ) { return $k === 'sn_tag_merge_history' ? $GLOBALS['__hist'] : $d; }
function get_terms( $a = array() ) { return $GLOBALS['__alltags']; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {}

// AI + cleanup seams.
$GLOBALS['__ai']       = false;
$GLOBALS['__transient'] = false;
$GLOBALS['__untagged']  = array();
$GLOBALS['__unused']    = array();
function snt_ai_is_available() { return $GLOBALS['__ai']; }
function get_transient( $k ) { return $GLOBALS['__transient']; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transient'] = $v; return true; }
function delete_transient( $k ) { $GLOBALS['__transient'] = false; return true; }
function get_current_user_id() { return 1; }
function sn_tag_untagged_notes( $l = 20 ) { return $GLOBALS['__untagged']; }
function sn_tag_find_unused() { return $GLOBALS['__unused']; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }

require __DIR__ . '/../inc/admin-glance.php';        // sn_admin_glance_grid (the first-glance hero)
require __DIR__ . '/../inc/tag-consolidation-admin.php';

// empty state
$GLOBALS['__clusters'] = array();
$GLOBALS['__alltags'] = array( (object) array( 'term_id' => 5, 'name' => 'Jazz', 'slug' => 'jazz', 'count' => 4 ) );
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, 'No duplicate tags' ) !== false, 'render: empty state when no clusters' );
ok( strpos( $h, 'name="sn_tag_into"' ) !== false || strpos( $h, 'Merge any two' ) !== false, 'render: manual picker present even with no clusters' );

// cluster cards
$GLOBALS['__clusters'] = array( array(
	'key' => 'ai generated music',
	'terms' => array(
		array( 'term_id' => 10, 'name' => 'AI-Generated Music', 'slug' => 'ai-generated-music', 'count' => 5 ),
		array( 'term_id' => 11, 'name' => 'AI Generated Music', 'slug' => 'ai-generated-music-2', 'count' => 2 ),
	),
	'suggested' => 10,
) );
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, 'AI-Generated Music' ) !== false && strpos( $h, 'AI Generated Music' ) !== false, 'render: cluster lists both member tags' );
ok( strpos( $h, '<div class="sn-fieldset">' ) !== false, 'render: cluster panels use the system fieldset chrome (v8.0.2 cohesion)' );
ok( strpos( $h, 'Preview merge' ) !== false, 'render: a Preview merge control per cluster' );
// Phase 4b: first-glance hero (counts) leads the full-width list view.
ok( strpos( $h, 'class="sn-glance"' ) !== false, 'glance: first-glance hero renders on the list view' );
ok( strpos( $h, 'Duplicate clusters' ) !== false && strpos( $h, 'Unused tags' ) !== false && strpos( $h, 'Tags total' ) !== false, 'glance: hero shows duplicate-cluster / unused / total counts' );

// GET preview -> confirm panel (no mutation; reads $_GET)
// The cluster card + manual picker submit sn_tag_from as an ARRAY (name="sn_tag_from[]"),
// NOT a comma string. The render must parse that array shape.
$_GET['sn_tag_preview'] = '1'; $_GET['sn_tag_from'] = array( '10', '11' ); $_GET['sn_tag_into'] = '12';
$GLOBALS['__preview'] = array( 'from' => array( array( 'id' => 10, 'name' => 'AI-Generated Music', 'slug' => 'ai-generated-music', 'count' => 5 ), array( 'id' => 11, 'name' => 'AI Generated Music', 'slug' => 'ai-generated-music-2', 'count' => 2 ) ), 'into' => array( 'id' => 12, 'name' => 'Music', 'slug' => 'music' ), 'posts_affected' => 3 );
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, '3' ) !== false && stripos( $h, 'Confirm merge' ) !== false, 'preview: array sn_tag_from[] parses -> confirm panel shows count + Confirm merge (regression: was "Nothing to merge")' );
ok( strpos( $h, 'value="tag_merge"' ) !== false && strpos( $h, 'sn_action' ) !== false, 'preview: confirm posts the tag_merge action through the dispatcher' );
ok( strpos( $h, '_wpnonce' ) !== false, 'preview: confirm form carries a nonce' );
ok( strpos( $h, 'page=sn-content' ) !== false && strpos( $h, 'tab=content' ) !== false, 'preview: confirm form posts back to the sn-content page (dispatcher contract)' );
ok( strpos( $h, 'name="sn_tag_from" value="10,11"' ) !== false, 'preview: confirm hidden field round-trips the ids as a comma string for the POST handler' );
ok( strpos( $h, 'class="sn-glance"' ) === false, 'preview: the confirm panel stays focused — no glance hero on the confirm view' );
unset( $_GET['sn_tag_preview'], $_GET['sn_tag_from'], $_GET['sn_tag_into'] );

// recent merges
$GLOBALS['__hist'] = array( array( 'from' => array( 'ai-generated-music', 'ai-generated-music-2' ), 'into' => 'music', 'posts' => 3, 'user' => 1, 'ts' => 100 ) );
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( stripos( $h, 'music' ) !== false && stripos( $h, 'Recent' ) !== false, 'render: Recent merges list shows the history' );

// cap gate
$GLOBALS['__cap'] = false;
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, 'permission' ) !== false, 'render: cap gate blocks non-managers' );
$GLOBALS['__cap'] = true;

// --- AI section ---------------------------------------------------------------
$GLOBALS['__clusters'] = array(); $GLOBALS['__hist'] = array();
$GLOBALS['__ai'] = false;
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, 'Connect an AI provider' ) !== false, 'AI: dormant note when no provider configured' );

$GLOBALS['__ai'] = true; $GLOBALS['__transient'] = false;
$GLOBALS['__untagged'] = array( array( 'id' => 7, 'title' => 'Untagged Note' ) );
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, 'Suggest tags' ) !== false && strpos( $h, 'value="tag_ai_suggest"' ) !== false, 'AI: Suggest button when available + untagged Notes present' );

$GLOBALS['__transient'] = array( array( 'post_id' => 7, 'title' => 'Untagged Note', 'suggested' => array( array( 'term_id' => 2, 'name' => 'Jazz', 'slug' => 'jazz' ) ) ) );
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, 'Untagged Note' ) !== false && strpos( $h, 'name="assign[7][]"' ) !== false && stripos( $h, 'Apply selected' ) !== false, 'AI: review form renders Note + suggested-tag checkbox + Apply' );
$GLOBALS['__transient'] = false;

// --- Unused section ------------------------------------------------------------
$GLOBALS['__unused'] = array( array( 'term_id' => 9, 'name' => 'Empty', 'slug' => 'empty', 'count' => 0 ) );
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, 'Unused tags' ) !== false && strpos( $h, 'name="sn_tag_unused[]"' ) !== false && strpos( $h, 'value="tag_prune_unused"' ) !== false, 'Unused: lists count-0 tags + Delete control' );
$GLOBALS['__unused'] = array();
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, 'No unused tags' ) !== false, 'Unused: empty state' );

// --- v8.0.2 cohesion contract: system card vocabulary on every view -------------
// Three captures cover all 7 panel sites: maximal list (clusters + history + AI
// review + unused), the confirm view, and the empty list (the empty-dups panel).
$GLOBALS['__clusters'] = array( array( 'key' => 'k', 'terms' => array( array( 'term_id' => 10, 'name' => 'A', 'slug' => 'a', 'count' => 1 ), array( 'term_id' => 11, 'name' => 'B', 'slug' => 'b', 'count' => 1 ) ), 'suggested' => 10 ) );
$GLOBALS['__hist'] = array( array( 'from' => array( 'a' ), 'into' => 'b', 'posts' => 1, 'user' => 1, 'ts' => 1 ) );
$GLOBALS['__ai'] = true;
$GLOBALS['__transient'] = array( array( 'post_id' => 7, 'title' => 'N', 'suggested' => array( array( 'term_id' => 2, 'name' => 'Jazz', 'slug' => 'jazz' ) ) ) );
$GLOBALS['__unused'] = array( array( 'term_id' => 9, 'name' => 'Empty', 'slug' => 'empty', 'count' => 0 ) );
ob_start(); sn_admin_render_tag_cleanup_section(); $view_list = ob_get_clean();
$_GET['sn_tag_preview'] = '1'; $_GET['sn_tag_from'] = array( '10' ); $_GET['sn_tag_into'] = '12';
$GLOBALS['__preview'] = array( 'from' => array( array( 'id' => 10, 'name' => 'A', 'slug' => 'a', 'count' => 1 ) ), 'into' => array( 'id' => 12, 'name' => 'B', 'slug' => 'b' ), 'posts_affected' => 1 );
ob_start(); sn_admin_render_tag_cleanup_section(); $view_confirm = ob_get_clean();
unset( $_GET['sn_tag_preview'], $_GET['sn_tag_from'], $_GET['sn_tag_into'] );
$GLOBALS['__clusters'] = array(); $GLOBALS['__transient'] = false; $GLOBALS['__unused'] = array();
ob_start(); sn_admin_render_tag_cleanup_section(); $view_empty = ob_get_clean();
foreach ( array( 'list view' => $view_list, 'confirm view' => $view_confirm, 'empty view' => $view_empty ) as $view => $out ) {
	ok( strpos( $out, 'postbox' ) === false, "cohesion: $view has no native postbox chrome" );
	ok( strpos( $out, 'style=' ) === false, "cohesion: $view has zero inline style attributes" );
}
ok( strpos( $view_list, '<div class="sn-fieldset">' ) !== false && strpos( $view_list, '<h2 class="sn-fieldset-h">' ) !== false, 'cohesion: list panels use .sn-fieldset + .sn-fieldset-h' );
ok( strpos( $view_list, 'class="snt-label-inline"' ) !== false && strpos( $view_list, 'class="snt-label-block"' ) !== false, 'cohesion: checklist labels use the layout utilities' );
$css = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );
ok( strpos( $css, '.snt-label-inline' ) !== false && strpos( $css, '.snt-label-block' ) !== false, 'cohesion: label utilities exist in admin.css' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
