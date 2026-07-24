<?php
/**
 * Tests for the /about/uses CMS-flip: the dossier renderer (faithful sn-uses-*
 * markup with name + note items), the child-Page create-and-regenerate helpers,
 * and the one-time migration.
 *
 * Standalone CLI harness (no PHPUnit).
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SN_ABOUT_SLUG' ) ) { define( 'SN_ABOUT_SLUG', 'about' ); }
if ( ! defined( 'SN_USES_SLUG' ) ) { define( 'SN_USES_SLUG', 'uses' ); }
if ( ! defined( 'SN_USES_PAGE_MIGRATED_OPT' ) ) { define( 'SN_USES_PAGE_MIGRATED_OPT', 'sn_uses_page_migrated_v1' ); }
if ( ! defined( 'SN_NOW_SLUG' ) ) { define( 'SN_NOW_SLUG', 'now' ); }
if ( ! defined( 'SN_NOW_PAGE_MIGRATED_OPT' ) ) { define( 'SN_NOW_PAGE_MIGRATED_OPT', 'sn_now_page_migrated_v1' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$GLOBALS['__opt']        = array();
$GLOBALS['__uses_page']  = null;   // get_page_by_path('about/uses')
$GLOBALS['__about_page'] = null;   // get_page_by_path('about')
$GLOBALS['__ins']        = array();
$GLOBALS['__upd']        = array();
$GLOBALS['__uses_raw']   = null;   // sn_uses_page_get() raw (null = nothing saved)
$GLOBALS['__uses_groups'] = array(); // sn_uses_parse_groups() output

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) {
		if ( SN_ABOUT_SLUG . '/' . SN_USES_SLUG === $p ) { return $GLOBALS['__uses_page']; }
		if ( SN_ABOUT_SLUG === $p ) { return $GLOBALS['__about_page']; }
		return null;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) { function wp_insert_post( $a, $e = false ) { $GLOBALS['__ins'][] = $a; return 55; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a ) { $GLOBALS['__upd'][] = $a; return $a['ID']; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'time' ) ) { function time() { return 1; } }
// Stub the /uses text-box seam (lives in inc/uses-page.php).
if ( ! function_exists( 'sn_uses_page_get' ) ) { function sn_uses_page_get() { return null === $GLOBALS['__uses_raw'] ? null : array( 'raw' => (string) $GLOBALS['__uses_raw'], 'updated' => '2026-07-10' ); } }
if ( ! function_exists( 'sn_uses_parse_groups' ) ) { function sn_uses_parse_groups( $raw ) { return $GLOBALS['__uses_groups']; } }

require_once SNT_PATH . 'inc/page-sync-engine.php'; // live Now/Uses sync engine (v9.81.0 split)
require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "/about/uses dossier + child migration\n";

$grp = array(
	array( 'label' => 'Microphones', 'items' => array(
		array( 'name' => 'Neumann U87', 'note' => 'the workhorse' ),
		array( 'name' => 'A <b>plain</b> mic', 'note' => '' ),
	) ),
);

// --- Dossier body ---
$html = sn_uses_dossier_html( $grp );
ok( false !== strpos( $html, '<!-- wp:html -->' ) && false !== strpos( $html, '<div class="sn-uses-page">' ), 'body is a core/html block in the sn-uses-page scope' );
ok( false !== strpos( $html, 'class="sn-uses-headline">Uses.<' ), 'hero renders the Uses. headline' );
ok( false !== strpos( $html, 'class="sn-uses-item-name">Neumann U87<' ), 'item name renders (escaped)' );
ok( false !== strpos( $html, 'class="sn-uses-item-note">the workhorse<' ), 'item note renders when present' );
ok( false === strpos( $html, '<b>plain' ), 'item name is escaped (no raw HTML)' );
ok( false !== strpos( $html, 'class="sn-uses-meta">2 items<' ), 'meta shows the total item count' );
ok( false !== strpos( $html, '<span class="sn-uses-section-count">02</span>' ), 'section count is zero-padded' );
ok( '' === sn_uses_dossier_html( array() ), 'empty groups -> empty string' );

// --- current_body reads the text box ---
$GLOBALS['__uses_raw']    = '## Microphones';
$GLOBALS['__uses_groups'] = $grp;
ok( false !== strpos( sn_uses_current_body(), 'sn-uses-page' ), 'current_body builds from the text box' );
$GLOBALS['__uses_raw'] = null;
ok( '' === sn_uses_current_body(), 'current_body is empty when nothing is saved' );

// --- upsert: child create needs the About parent ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__uses_page'] = null; $GLOBALS['__about_page'] = null;
ok( 0 === sn_uses_upsert_page( 'BODY' ), 'no About parent -> upsert returns 0 (retry)' );
ok( 0 === count( $GLOBALS['__ins'] ), 'no About parent -> nothing created' );

$GLOBALS['__about_page'] = (object) array( 'ID' => 3 );
$id = sn_uses_upsert_page( 'BODY' );
ok( 55 === $id && 1 === count( $GLOBALS['__ins'] ), 'with the parent present -> creates the child' );
$ins = $GLOBALS['__ins'][0] ?? array();
ok( 3 === ( $ins['post_parent'] ?? 0 ) && ( $ins['post_name'] ?? '' ) === 'uses' && ( $ins['page_template'] ?? '' ) === 'page-uses', 'child is parented to About, slug uses, template page-uses' );

// --- upsert: existing child -> update, excerpt never clobbered ---
$GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__uses_page'] = (object) array( 'ID' => 8, 'post_content' => 'old', 'post_excerpt' => 'keep' );
sn_uses_upsert_page( 'NEW' );
ok( 0 === count( $GLOBALS['__ins'] ) && 1 === count( $GLOBALS['__upd'] ) && 'NEW' === ( $GLOBALS['__upd'][0]['post_content'] ?? '' ), 'existing child -> update, not insert' );
ok( ! isset( $GLOBALS['__upd'][0]['post_excerpt'] ), 'existing non-empty excerpt is never clobbered' );

// --- migration: retry until parent + content, then create + flag ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array();
$GLOBALS['__uses_page'] = null; $GLOBALS['__about_page'] = null;
$GLOBALS['__uses_raw'] = '## Microphones'; $GLOBALS['__uses_groups'] = $grp;
sn_migrate_uses_page();
ok( 0 === count( $GLOBALS['__ins'] ) && empty( $GLOBALS['__opt'][ SN_USES_PAGE_MIGRATED_OPT ] ), 'parent missing -> no create, no flag (retry)' );
$GLOBALS['__about_page'] = (object) array( 'ID' => 3 );
sn_migrate_uses_page();
ok( 1 === count( $GLOBALS['__ins'] ) && ! empty( $GLOBALS['__opt'][ SN_USES_PAGE_MIGRATED_OPT ] ), 'parent present -> creates child + flags' );
$GLOBALS['__ins'] = array();
sn_migrate_uses_page();
ok( 0 === count( $GLOBALS['__ins'] ), 'flag set -> never creates again' );

// --- migration: empty box -> retry; existing non-empty child -> no clobber ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array();
$GLOBALS['__uses_raw'] = null; $GLOBALS['__uses_groups'] = array();
sn_migrate_uses_page();
ok( empty( $GLOBALS['__opt'][ SN_USES_PAGE_MIGRATED_OPT ] ), 'empty box -> no flag (retry)' );

$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__uses_page'] = (object) array( 'ID' => 8, 'post_content' => '<p>owner</p>', 'post_excerpt' => 'x' );
$GLOBALS['__uses_raw'] = '## Microphones'; $GLOBALS['__uses_groups'] = $grp;
sn_migrate_uses_page();
ok( 0 === count( $GLOBALS['__ins'] ) && 0 === count( $GLOBALS['__upd'] ) && ! empty( $GLOBALS['__opt'][ SN_USES_PAGE_MIGRATED_OPT ] ), 'existing non-empty child -> no write, still flags' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
