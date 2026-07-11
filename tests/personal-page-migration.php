<?php
/**
 * Tests for the /contact/personal CMS-flip: the frozen-seed loader and the
 * one-time CHILD Page create/seed migration (child of /contact, create-once,
 * never-clobber, retry-safe until the Contact parent exists).
 *
 * Standalone CLI harness (no PHPUnit).
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SN_CONTACT_SLUG' ) ) { define( 'SN_CONTACT_SLUG', 'contact' ); }
if ( ! defined( 'SN_PERSONAL_SLUG' ) ) { define( 'SN_PERSONAL_SLUG', 'personal' ); }
if ( ! defined( 'SN_PERSONAL_PAGE_MIGRATED_OPT' ) ) { define( 'SN_PERSONAL_PAGE_MIGRATED_OPT', 'sn_personal_page_migrated_v1' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$GLOBALS['__opt']           = array();
$GLOBALS['__personal_page'] = null;   // get_page_by_path('contact/personal')
$GLOBALS['__contact_page']  = null;   // get_page_by_path('contact')
$GLOBALS['__ins']           = array();
$GLOBALS['__upd']           = array();

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) {
		if ( SN_CONTACT_SLUG . '/' . SN_PERSONAL_SLUG === $p ) { return $GLOBALS['__personal_page']; }
		if ( SN_CONTACT_SLUG === $p ) { return $GLOBALS['__contact_page']; }
		return null;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) { function wp_insert_post( $a, $e = false ) { $GLOBALS['__ins'][] = $a; return 88; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a ) { $GLOBALS['__upd'][] = $a; return $a['ID']; } }

require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "/contact/personal frozen-seed + child migration\n";

// --- loader reads the real seed file ---
$body = sn_load_personal_body();
ok( is_string( $body ) && '' !== $body, 'sn_load_personal_body() returns non-empty markup' );
ok( false !== strpos( $body, 'Dossier · Personal' ), 'seed carries the masthead eyebrow' );
ok( false !== strpos( $body, '>PERSONAL<' ), 'seed carries the PERSONAL headline' );
ok( false !== strpos( $body, 'https://www.linkedin.com/in/juanlentino/' ), 'seed keeps the single LinkedIn channel' );
ok( false === strpos( $body, 'tagName":"main"' ) && false === strpos( $body, '<main' ), 'seed has NO outer <main> wrapper (the template supplies it)' );

// --- migration: child create needs the Contact parent (retry-safe) ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__personal_page'] = null; $GLOBALS['__contact_page'] = null;
sn_migrate_personal_page();
ok( 0 === count( $GLOBALS['__ins'] ), 'no Contact parent -> nothing created' );
ok( empty( $GLOBALS['__opt'][ SN_PERSONAL_PAGE_MIGRATED_OPT ] ), 'no Contact parent -> no flag (retry)' );

// --- migration: parent present -> creates the child + flags ---
$GLOBALS['__contact_page'] = (object) array( 'ID' => 4 );
sn_migrate_personal_page();
ok( 1 === count( $GLOBALS['__ins'] ), 'parent present -> one create' );
$ins = $GLOBALS['__ins'][0] ?? array();
ok( ( $ins['post_name'] ?? '' ) === SN_PERSONAL_SLUG, 'created with post_name=personal' );
ok( 4 === ( $ins['post_parent'] ?? -1 ), 'created as a child of the Contact Page' );
ok( ( $ins['page_template'] ?? '' ) === 'page-personal', 'bound to page-personal template' );
ok( ( $ins['post_status'] ?? '' ) === 'publish', 'published' );
ok( '' !== trim( (string) ( $ins['post_excerpt'] ?? '' ) ), 'seeds a native Excerpt' );
ok( false !== strpos( (string) ( $ins['post_content'] ?? '' ), '>PERSONAL<' ), 'body is the seed markup' );
ok( ! empty( $GLOBALS['__opt'][ SN_PERSONAL_PAGE_MIGRATED_OPT ] ), 'sets the migrated flag' );

// --- idempotent: flag set -> never again ---
$GLOBALS['__ins'] = array();
sn_migrate_personal_page();
ok( 0 === count( $GLOBALS['__ins'] ), 'flag set -> no second create' );

// --- never clobber an existing, owner-edited child Page ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__personal_page'] = (object) array( 'ID' => 12, 'post_content' => '<p>owner</p>', 'post_excerpt' => 'x' );
sn_migrate_personal_page();
ok( 0 === count( $GLOBALS['__ins'] ) && 0 === count( $GLOBALS['__upd'] ), 'existing non-empty child -> no write' );
ok( ! empty( $GLOBALS['__opt'][ SN_PERSONAL_PAGE_MIGRATED_OPT ] ), 'existing child still flags (stops checking)' );

// --- existing but EMPTY child -> seed in place (update, not create) ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__personal_page'] = (object) array( 'ID' => 12, 'post_content' => '', 'post_excerpt' => '' );
sn_migrate_personal_page();
ok( 0 === count( $GLOBALS['__ins'] ) && 1 === count( $GLOBALS['__upd'] ), 'existing empty child -> update in place' );
$upd = $GLOBALS['__upd'][0] ?? array();
ok( 12 === ( $upd['ID'] ?? 0 ) && false !== strpos( (string) ( $upd['post_content'] ?? '' ), '>PERSONAL<' ), 'update seeds the body' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
