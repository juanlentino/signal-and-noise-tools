<?php
/**
 * Tests for the /accessibility CMS-flip: the frozen-seed loader and the one-time
 * top-level Page create/seed migration (create-once, never-clobber, retry-safe).
 *
 * Standalone CLI harness (no PHPUnit).
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SN_A11Y_SLUG' ) ) { define( 'SN_A11Y_SLUG', 'accessibility' ); }
if ( ! defined( 'SN_A11Y_PAGE_MIGRATED_OPT' ) ) { define( 'SN_A11Y_PAGE_MIGRATED_OPT', 'sn_accessibility_page_migrated_v1' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$GLOBALS['__opt']       = array();
$GLOBALS['__a11y_page'] = null;   // get_page_by_path('accessibility')
$GLOBALS['__ins']       = array();
$GLOBALS['__upd']       = array();

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) {
		return SN_A11Y_SLUG === $p ? $GLOBALS['__a11y_page'] : null;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) { function wp_insert_post( $a, $e = false ) { $GLOBALS['__ins'][] = $a; return 71; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a ) { $GLOBALS['__upd'][] = $a; return $a['ID']; } }

require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "/accessibility frozen-seed + top-level migration\n";

// --- loader reads the real seed file ---
$body = sn_load_accessibility_body();
ok( is_string( $body ) && '' !== $body, 'sn_load_accessibility_body() returns non-empty markup' );
ok( false !== strpos( $body, 'class="wp-block-heading sn-a11y-headline">Accessibility.<' ), 'seed carries the hero headline' );
ok( false !== strpos( $body, 'sn-a11y-section-label">Commitment<' ), 'seed carries the first section label' );
ok( false !== strpos( $body, 'href="/contact/">the contact page</a>' ), 'Feedback link is a real inline anchor' );
ok( false === strpos( $body, 'the /contact page' ), 'the render-time string-replace placeholder is gone' );
ok( 6 === substr_count( $body, 'class="wp-block-heading sn-a11y-section-label"' ), 'seed has all six section headings' );
ok( false === strpos( $body, 'wp-block-paragraph' ), 'no invalid wp-block-paragraph class (block-recovery guard)' );

// --- migration: create when absent ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__a11y_page'] = null;
sn_migrate_accessibility_page();
ok( 1 === count( $GLOBALS['__ins'] ), 'absent Page -> one create' );
$ins = $GLOBALS['__ins'][0] ?? array();
ok( ( $ins['post_name'] ?? '' ) === SN_A11Y_SLUG, 'created with post_name=accessibility' );
ok( 0 === ( $ins['post_parent'] ?? -1 ), 'created top-level (post_parent 0)' );
ok( ( $ins['page_template'] ?? '' ) === 'page-accessibility', 'bound to page-accessibility template' );
ok( ( $ins['post_status'] ?? '' ) === 'publish', 'published' );
ok( '' !== trim( (string) ( $ins['post_excerpt'] ?? '' ) ), 'seeds a native Excerpt' );
ok( false !== strpos( (string) ( $ins['post_content'] ?? '' ), 'sn-a11y-headline' ), 'body is the seed markup' );
ok( ! empty( $GLOBALS['__opt'][ SN_A11Y_PAGE_MIGRATED_OPT ] ), 'sets the migrated flag' );

// --- idempotent: flag set -> never again ---
$GLOBALS['__ins'] = array();
sn_migrate_accessibility_page();
ok( 0 === count( $GLOBALS['__ins'] ), 'flag set -> no second create' );

// --- never clobber an existing, owner-edited Page ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__a11y_page'] = (object) array( 'ID' => 9, 'post_content' => '<p>owner edit</p>', 'post_excerpt' => 'x' );
sn_migrate_accessibility_page();
ok( 0 === count( $GLOBALS['__ins'] ) && 0 === count( $GLOBALS['__upd'] ), 'existing non-empty Page -> no write' );
ok( ! empty( $GLOBALS['__opt'][ SN_A11Y_PAGE_MIGRATED_OPT ] ), 'existing Page still flags (stops checking)' );

// --- existing but EMPTY Page -> seed in place (update, not create) ---
$GLOBALS['__opt'] = array(); $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__a11y_page'] = (object) array( 'ID' => 9, 'post_content' => '', 'post_excerpt' => '' );
sn_migrate_accessibility_page();
ok( 0 === count( $GLOBALS['__ins'] ) && 1 === count( $GLOBALS['__upd'] ), 'existing empty Page -> update in place' );
$upd = $GLOBALS['__upd'][0] ?? array();
ok( 9 === ( $upd['ID'] ?? 0 ) && false !== strpos( (string) ( $upd['post_content'] ?? '' ), 'sn-a11y-headline' ), 'update seeds the body' );
ok( '' !== trim( (string) ( $upd['post_excerpt'] ?? '' ) ), 'update seeds the excerpt when empty' );

// --- existing empty body but a pre-existing excerpt -> body seeded, excerpt kept ---
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__a11y_page'] = (object) array( 'ID' => 9, 'post_content' => '', 'post_excerpt' => 'owner excerpt' );
sn_migrate_accessibility_page();
$upd = $GLOBALS['__upd'][0] ?? array();
ok( ! isset( $upd['post_excerpt'] ), 'a pre-existing owner excerpt is never clobbered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
