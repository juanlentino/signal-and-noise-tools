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
function __( $s, $d = null ) { return $s; }
function number_format_i18n( $n ) { return (string) $n; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function wp_nonce_field( $a = '', $n = '', $r = true, $e = true ) { $f = '<input name="_wpnonce" value="nonce">'; if ( $e ) { echo $f; } return $f; }
function current_user_can( $c ) { return $GLOBALS['__cap'] ?? true; }
function wp_unslash( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }

// Logic seams the admin calls.
$GLOBALS['__clusters'] = array();
$GLOBALS['__preview']  = null;
$GLOBALS['__hist']     = array();
$GLOBALS['__alltags']  = array();
function sn_tag_find_duplicate_clusters() { return $GLOBALS['__clusters']; }
function sn_tag_merge_preview( $f, $i ) { return $GLOBALS['__preview']; }
function get_option( $k, $d = false ) { return $k === 'sn_tag_merge_history' ? $GLOBALS['__hist'] : $d; }
function get_terms( $a = array() ) { return $GLOBALS['__alltags']; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {}

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
ok( strpos( $h, 'postbox' ) !== false, 'render: uses native postbox chrome' );
ok( strpos( $h, 'Preview merge' ) !== false, 'render: a Preview merge control per cluster' );

// GET preview -> confirm panel (no mutation; reads $_GET)
$_GET['sn_tag_preview'] = '1'; $_GET['sn_tag_from'] = '10,11'; $_GET['sn_tag_into'] = '12';
$GLOBALS['__preview'] = array( 'from' => array( array( 'id' => 10, 'name' => 'AI-Generated Music', 'slug' => 'ai-generated-music', 'count' => 5 ), array( 'id' => 11, 'name' => 'AI Generated Music', 'slug' => 'ai-generated-music-2', 'count' => 2 ) ), 'into' => array( 'id' => 12, 'name' => 'Music', 'slug' => 'music' ), 'posts_affected' => 3 );
ob_start(); sn_admin_render_tag_cleanup_section(); $h = ob_get_clean();
ok( strpos( $h, '3' ) !== false && stripos( $h, 'Confirm merge' ) !== false, 'preview: confirm panel shows affected count + Confirm merge button' );
ok( strpos( $h, 'value="tag_merge"' ) !== false && strpos( $h, 'sn_action' ) !== false, 'preview: confirm posts the tag_merge action through the dispatcher' );
ok( strpos( $h, '_wpnonce' ) !== false, 'preview: confirm form carries a nonce' );
ok( strpos( $h, 'page=sn-content' ) !== false && strpos( $h, 'tab=content' ) !== false, 'preview: confirm form posts back to the sn-content page (dispatcher contract)' );
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

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
