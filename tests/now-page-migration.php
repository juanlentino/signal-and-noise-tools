<?php
/**
 * Tests for the /now CMS-flip: the dossier renderer (faithful sn-now-* markup),
 * the create-and-regenerate helpers, and the one-time migration.
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
if ( ! defined( 'SN_ABOUT_SLUG' ) ) { define( 'SN_ABOUT_SLUG', 'about' ); }
if ( ! defined( 'SN_USES_SLUG' ) ) { define( 'SN_USES_SLUG', 'uses' ); }
if ( ! defined( 'SN_USES_PAGE_MIGRATED_OPT' ) ) { define( 'SN_USES_PAGE_MIGRATED_OPT', 'sn_uses_page_migrated_v1' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$GLOBALS['__opt']          = array();
$GLOBALS['__page']         = null;
$GLOBALS['__ins']          = array();
$GLOBALS['__upd']          = array();
$GLOBALS['__now_sections'] = array();

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_page_by_path' ) ) { function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return $GLOBALS['__page']; } }
if ( ! function_exists( 'wp_insert_post' ) ) { function wp_insert_post( $a, $e = false ) { $GLOBALS['__ins'][] = $a; return 77; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a ) { $GLOBALS['__upd'][] = $a; return $a['ID']; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'time' ) ) { function time() { return 1; } }
if ( ! function_exists( 'sn_now_page_sections' ) ) { function sn_now_page_sections() { return $GLOBALS['__now_sections']; } }

require_once SNT_PATH . 'inc/page-sync-engine.php'; // live Now/Uses sync engine (v9.81.0 split)
require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "/now dossier + migration\n";

// --- Shared section renderer ---
$sec = sn_dossier_section_html( 'now', 0, 'Building', '<li class="sn-now-item">x</li>', 3 );
ok( false !== strpos( $sec, 'class="sn-now-section"' ), 'section carries the sn-now-section class' );
ok( false !== strpos( $sec, 'class="sn-now-section-label"' ) && false !== strpos( $sec, '>Building<' ), 'section-label holds the (escaped) label' );
ok( false !== strpos( $sec, '<span class="sn-now-section-count">03</span>' ), 'count badge is zero-padded' );
ok( false !== strpos( $sec, 'class="sn-now-list"' ), 'list carries the sn-now-list class' );

// --- Dossier body ---
$html = sn_now_dossier_html( array(
	array( 'label' => 'Building', 'items' => array( 'Signal & Noise', 'A <script>x</script> item' ) ),
), 'July 10, 2026' );
ok( false !== strpos( $html, '<!-- wp:html -->' ) && false !== strpos( $html, '<!-- /wp:html -->' ), 'body is wrapped in a core/html block' );
ok( false !== strpos( $html, '<div class="sn-now-page">' ), 'body wraps in the sn-now-page scope' );
ok( false !== strpos( $html, 'class="sn-now-hero"' ) && false !== strpos( $html, 'class="sn-now-headline">Now.<' ), 'hero renders with the Now. headline' );
ok( false !== strpos( $html, 'Updated July 10, 2026' ), 'hero meta shows the given Updated date' );
ok( false !== strpos( $html, 'class="sn-now-item-text">Signal &amp; Noise<' ), 'item text is escaped' );
ok( false === strpos( $html, '<script>x' ), 'item HTML is escaped (no raw script)' );
ok( '' === sn_now_dossier_html( array(), 'x' ), 'empty sections -> empty string' );
ok( '' === sn_now_dossier_html( array( array( 'label' => '', 'items' => array( 'a' ) ) ), 'x' ), 'label-less section -> empty' );

// --- build_body stamps a date ---
ok( false !== strpos( sn_now_build_body( array( array( 'label' => 'B', 'items' => array( 'x' ) ) ) ), 'Updated ' ), 'build_body stamps an Updated date' );

// --- upsert: no Page -> create (no backdate second write) ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = null;
$id = sn_now_upsert_page( '<!-- wp:html --><div class="sn-now-page">x</div><!-- /wp:html -->' );
ok( 1 === count( $GLOBALS['__ins'] ) && 0 === count( $GLOBALS['__upd'] ), 'create -> one insert, no follow-up update' );
$ins = $GLOBALS['__ins'][0] ?? array();
ok( ( $ins['post_name'] ?? '' ) === 'now' && ( $ins['post_status'] ?? '' ) === 'publish' && ( $ins['page_template'] ?? '' ) === 'page-now', 'create binds slug/status/template' );
ok( 77 === $id, 'upsert returns the new id' );

// --- upsert: existing Page -> update (regenerate), excerpt only when empty ---
$GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 9, 'post_content' => 'old', 'post_excerpt' => 'keep' );
sn_now_upsert_page( 'NEWBODY' );
ok( 0 === count( $GLOBALS['__ins'] ) && 1 === count( $GLOBALS['__upd'] ), 'existing Page -> update, no insert' );
ok( 'NEWBODY' === ( $GLOBALS['__upd'][0]['post_content'] ?? '' ), 'update replaces post_content' );
ok( ! isset( $GLOBALS['__upd'][0]['post_excerpt'] ), 'a non-empty excerpt is never clobbered' );

// --- sync: regenerate from the text box ---
$GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 9, 'post_content' => 'old', 'post_excerpt' => '' );
$GLOBALS['__now_sections'] = array( array( 'label' => 'Building', 'items' => array( 'fresh-item' ) ) );
sn_now_sync_page();
ok( 1 === count( $GLOBALS['__upd'] ) && false !== strpos( $GLOBALS['__upd'][0]['post_content'] ?? '', '>fresh-item<' ), 'sync regenerates the Page from the box' );
$GLOBALS['__upd'] = array();
$GLOBALS['__now_sections'] = array();
sn_now_sync_page();
ok( 0 === count( $GLOBALS['__upd'] ), 'sync with an empty box is a no-op (never blanks)' );

// --- migration: create-once / idempotent / retry-safe / never-clobber ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array();
$GLOBALS['__page'] = null;
$GLOBALS['__now_sections'] = array( array( 'label' => 'Building', 'items' => array( 'x' ) ) );
sn_migrate_now_page();
ok( 1 === count( $GLOBALS['__ins'] ) && ! empty( $GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] ), 'migration creates the Page + flags' );
$GLOBALS['__ins'] = array();
sn_migrate_now_page();
ok( 0 === count( $GLOBALS['__ins'] ), 'flag set -> never creates again' );

$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array();
$GLOBALS['__now_sections'] = array();
sn_migrate_now_page();
ok( 0 === count( $GLOBALS['__ins'] ) && empty( $GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] ), 'empty box -> no create, no flag (retry)' );

$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 9, 'post_content' => '<p>owner</p>', 'post_excerpt' => 'x' );
$GLOBALS['__now_sections'] = array( array( 'label' => 'Building', 'items' => array( 'x' ) ) );
sn_migrate_now_page();
ok( 0 === count( $GLOBALS['__ins'] ) && 0 === count( $GLOBALS['__upd'] ) && ! empty( $GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] ), 'existing non-empty Page -> no write, still flags' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
