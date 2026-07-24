<?php
/**
 * Tests for the v9.20.1 one-time dossier repair: regenerate /now from its box
 * (upgrade v9.19.0 core-block content to the dossier markup) and re-seed an
 * empty Uses box from the frozen default (which creates /about/uses).
 *
 * Standalone CLI harness (no PHPUnit). Note: sn_now_sync_page / sn_uses_sync_page
 * are DEFINED in content-migrations.php, so they are exercised for real here
 * (their deps are stubbed) rather than stubbed (which would redeclare-fatal).
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SN_NOW_USES_DOSSIER_REPAIR_OPT' ) ) { define( 'SN_NOW_USES_DOSSIER_REPAIR_OPT', 'sn_now_uses_dossier_repair_v1' ); }
if ( ! defined( 'SN_NOW_SLUG' ) ) { define( 'SN_NOW_SLUG', 'now' ); }
if ( ! defined( 'SN_NOW_PAGE_MIGRATED_OPT' ) ) { define( 'SN_NOW_PAGE_MIGRATED_OPT', 'sn_now_page_migrated_v1' ); }
if ( ! defined( 'SN_ABOUT_SLUG' ) ) { define( 'SN_ABOUT_SLUG', 'about' ); }
if ( ! defined( 'SN_USES_SLUG' ) ) { define( 'SN_USES_SLUG', 'uses' ); }
if ( ! defined( 'SN_USES_PAGE_MIGRATED_OPT' ) ) { define( 'SN_USES_PAGE_MIGRATED_OPT', 'sn_uses_page_migrated_v1' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$GLOBALS['__opt']      = array();
$GLOBALS['__upd']      = array();
$GLOBALS['__saved']    = null;  // raw passed to sn_uses_page_save (null = not called)
$GLOBALS['__uses_box'] = null;  // sn_uses_page_get() return

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
// /now Page exists (so the regen updates rather than inserts); nothing else resolves.
if ( ! function_exists( 'get_page_by_path' ) ) { function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return SN_NOW_SLUG === $p ? (object) array( 'ID' => 7, 'post_content' => 'old', 'post_excerpt' => 'x' ) : null; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a ) { $GLOBALS['__upd'][] = $a; return $a['ID']; } }
if ( ! function_exists( 'wp_insert_post' ) ) { function wp_insert_post( $a, $e = false ) { return 9; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'time' ) ) { function time() { return 1; } }
// The /now box (populated) drives sn_now_sync_page's real regenerate.
if ( ! function_exists( 'sn_now_page_sections' ) ) { function sn_now_page_sections() { return array( array( 'label' => 'Building', 'items' => array( 'x' ) ) ); } }
// The Uses box seam (lives in uses-page.php); stubbing get/save does NOT redeclare.
if ( ! function_exists( 'sn_uses_page_get' ) ) { function sn_uses_page_get() { return $GLOBALS['__uses_box']; } }
if ( ! function_exists( 'sn_uses_page_save' ) ) { function sn_uses_page_save( $raw ) { $GLOBALS['__saved'] = (string) $raw; return true; } }

require_once SNT_PATH . 'inc/page-sync-engine.php'; // live Now/Uses sync engine (v9.81.0 split)
require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "v9.20.1 dossier repair\n";

// --- Seed ---
$seed = sn_load_uses_default();
ok( false !== strpos( $seed, '## Microphones' ), 'seed carries the Microphones group' );
ok( false !== strpos( $seed, 'SSL UF8 | Advanced DAW controller' ), 'seed carries a name | note item' );
ok( 5 === substr_count( $seed, '## ' ), 'seed has all 5 groups' );

// --- Empty Uses box -> regenerate /now (dossier) + seed the Uses box + flag ---
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array(); $GLOBALS['__saved'] = null; $GLOBALS['__uses_box'] = null;
sn_migrate_now_uses_dossier_repair();
ok( 1 === count( $GLOBALS['__upd'] ), '/now regenerated via one update' );
ok( false !== strpos( $GLOBALS['__upd'][0]['post_content'] ?? '', 'class="sn-now-page"' ), '/now regenerated INTO the dossier markup' );
ok( null !== $GLOBALS['__saved'] && false !== strpos( $GLOBALS['__saved'], '## Microphones' ), 'empty Uses box -> seeded with the recovered gear list' );
ok( ! empty( $GLOBALS['__opt'][ SN_NOW_USES_DOSSIER_REPAIR_OPT ] ), 'flag set after repair' );

// --- Flag set -> no-op ---
$GLOBALS['__upd'] = array(); $GLOBALS['__saved'] = null;
sn_migrate_now_uses_dossier_repair();
ok( 0 === count( $GLOBALS['__upd'] ) && null === $GLOBALS['__saved'], 'flag set -> repair is a no-op' );

// --- Populated Uses box -> never re-seeds ---
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array(); $GLOBALS['__saved'] = null; $GLOBALS['__uses_box'] = array( 'raw' => '## Custom', 'updated' => '2026-07-10' );
sn_migrate_now_uses_dossier_repair();
ok( null === $GLOBALS['__saved'], 'populated Uses box -> never re-seeded (never clobbers a saved box)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
