<?php
/**
 * Tests for the /now CMS-flip migration: the text-box → blocks converter
 * and the create-and-seed migration (sn_migrate_now_page).
 *
 * Standalone CLI harness (no PHPUnit): inline WP stubs, a pass/fail counter,
 * and the summary line the CI sweep gates on.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SN_NOW_SLUG' ) ) { define( 'SN_NOW_SLUG', 'now' ); }
if ( ! defined( 'SN_NOW_PAGE_MIGRATED_OPT' ) ) { define( 'SN_NOW_PAGE_MIGRATED_OPT', 'sn_now_page_migrated_v1' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

$GLOBALS['__opt']          = array();
$GLOBALS['__page']         = null;   // get_page_by_path return
$GLOBALS['__ins']          = array(); // captured wp_insert_post args
$GLOBALS['__upd']          = array(); // captured wp_update_post args
$GLOBALS['__now_sections'] = array(); // what sn_now_page_sections() returns

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_page_by_path' ) ) { function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return $GLOBALS['__page']; } }
if ( ! function_exists( 'wp_insert_post' ) ) { function wp_insert_post( $a, $e = false ) { $GLOBALS['__ins'][] = $a; return 77; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a ) { $GLOBALS['__upd'][] = $a; return $a['ID']; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'time' ) ) { function time() { return 1; } }
// get_post: the migration reads the freshly-created Page back to backdate its
// post_date (so the modified-date byline renders). Fixed noon stamp for the gap check.
if ( ! function_exists( 'get_post' ) ) { function get_post( $id ) { return (object) array( 'ID' => $id, 'post_date' => '2026-07-10 12:00:00' ); } }
// Content source lives in now-page.php; stub it so this suite is self-contained.
if ( ! function_exists( 'sn_now_page_sections' ) ) { function sn_now_page_sections() { return $GLOBALS['__now_sections']; } }

require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "/now converter + migration\n";

// --- Converter ---
$blocks = sn_now_sections_to_blocks( array(
	array( 'label' => 'Building', 'items' => array( 'Signal & Noise theme', 'A <script>bad</script> item' ) ),
) );
ok( false !== strpos( $blocks, '<!-- wp:heading' ), 'converter emits a heading block' );
ok( false !== strpos( $blocks, '>Building<' ), 'converter emits the label text' );
ok( false !== strpos( $blocks, '<!-- wp:list ' ) || false !== strpos( $blocks, "<!-- wp:list -->" ), 'converter emits a list block' );
ok( false !== strpos( $blocks, '<!-- wp:list-item' ), 'converter emits list-item blocks' );
ok( false === strpos( $blocks, '<script>bad' ), 'converter escapes item HTML' );
ok( substr_count( $blocks, '<!-- wp:list-item' ) === substr_count( $blocks, '<!-- /wp:list-item' ), 'list-item delimiters balance' );
ok( substr_count( $blocks, '<!-- wp:list -->' ) === substr_count( $blocks, '<!-- /wp:list -->' ), 'list delimiters balance' );
ok( substr_count( $blocks, '<!-- wp:heading' ) === substr_count( $blocks, '<!-- /wp:heading' ), 'heading delimiters balance' );
ok( '' === trim( sn_now_sections_to_blocks( array() ) ), 'empty sections -> empty string' );
ok( '' === trim( sn_now_sections_to_blocks( array( array( 'label' => '', 'items' => array( 'x' ) ) ) ) ), 'label-less section -> dropped (empty)' );

// --- Hero loader ---
ok( false !== strpos( sn_load_now_hero(), 'sn-catalog-eyebrow' ), 'hero loader returns the frozen hero markup' );
ok( false !== strpos( sn_load_now_hero(), 'wp:post-date' ), 'hero includes the automatic modified-date block' );

// --- Migration: no Page + real sections -> CREATE the Page ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = null;
$GLOBALS['__now_sections'] = array( array( 'label' => 'Building', 'items' => array( 'x' ) ) );
sn_migrate_now_page();
ok( 1 === count( $GLOBALS['__ins'] ), 'no Page + content -> exactly one wp_insert_post' );
$ins = $GLOBALS['__ins'][0] ?? array();
ok( ( $ins['post_name'] ?? '' ) === 'now', 'creates the page with slug now' );
ok( ( $ins['post_status'] ?? '' ) === 'publish', 'creates it published' );
ok( ( $ins['post_type'] ?? '' ) === 'page', 'creates a page' );
ok( ( $ins['page_template'] ?? '' ) === 'page-now', 'binds the page-now template' );
ok( false !== strpos( $ins['post_content'] ?? '', 'sn-catalog-eyebrow' ), 'body includes the hero' );
ok( false !== strpos( $ins['post_content'] ?? '', '<!-- wp:list' ), 'body includes the converted sections' );
ok( '' !== trim( $ins['post_excerpt'] ?? '' ), 'seeds a non-empty excerpt' );
ok( ! empty( $GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] ), 'flag set after creating' );
// Follow-up: backdate post_date so post_modified > post_date and the hero's
// modified-date byline renders on first load (WP core renders nothing when equal).
ok( 1 === count( $GLOBALS['__upd'] ), 'create path issues one follow-up update to backdate post_date' );
ok( isset( $GLOBALS['__upd'][0]['post_date'] ) && strtotime( (string) $GLOBALS['__upd'][0]['post_date'] ) < strtotime( '2026-07-10 12:00:00' ), 'follow-up update backdates post_date (opens the modified > published gap)' );

// --- Migration: flag set -> no-op ---
$GLOBALS['__ins'] = array();
sn_migrate_now_page();
ok( 0 === count( $GLOBALS['__ins'] ), 'flag set -> never creates again' );

// --- Migration: empty text box -> retry-safe (no insert, NO flag) ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array();
$GLOBALS['__page'] = null;
$GLOBALS['__now_sections'] = array();
sn_migrate_now_page();
ok( 0 === count( $GLOBALS['__ins'] ), 'empty box -> no Page created' );
ok( empty( $GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] ), 'empty box -> flag NOT set (retry next admin_init)' );

// --- Migration: Page already exists with content -> never clobber, but mark migrated ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 9, 'post_content' => '<!-- wp:paragraph --><p>owner</p><!-- /wp:paragraph -->', 'post_excerpt' => 'hand' );
$GLOBALS['__now_sections'] = array( array( 'label' => 'Building', 'items' => array( 'x' ) ) );
sn_migrate_now_page();
ok( 0 === count( $GLOBALS['__ins'] ) && 0 === count( $GLOBALS['__upd'] ), 'existing non-empty Page -> no write' );
ok( ! empty( $GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] ), 'existing Page -> still marks migrated' );

// --- Migration: Page exists but empty -> seed it (update, not insert) ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 9, 'post_content' => '', 'post_excerpt' => '' );
$GLOBALS['__now_sections'] = array( array( 'label' => 'Building', 'items' => array( 'x' ) ) );
sn_migrate_now_page();
ok( 1 === count( $GLOBALS['__upd'] ), 'existing empty Page -> one wp_update_post' );
ok( 9 === ( $GLOBALS['__upd'][0]['ID'] ?? 0 ), 'update targets the existing Page' );
ok( false !== strpos( $GLOBALS['__upd'][0]['post_content'] ?? '', 'sn-catalog-eyebrow' ), 'update seeds the hero+sections body' );
ok( ! empty( $GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] ), 'empty Page seeded -> flag set' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
