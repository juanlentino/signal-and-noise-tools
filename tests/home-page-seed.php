<?php
/**
 * The front-page Page is seeded once with the hero, never overwritten.
 * Run: php tests/home-page-seed.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['opt'] = array(); $GLOBALS['posts'] = array(); $GLOBALS['updates'] = 0;
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function wp_slash( $v ) { return $v; }
function is_wp_error( $x ) { return false; }
function wp_update_post( $a ) { $GLOBALS['updates']++; $GLOBALS['posts'][ $a['ID'] ]->post_content = $a['post_content']; return $a['ID']; }
function add_action() {}
function sn_content_seed_file( $n ) { return __DIR__ . '/../inc/seed-content/' . $n; }
require __DIR__ . '/../inc/home-page-seed.php';

$seed = sn_load_home_body();
ok( '' !== $seed, 'the seed file ships' );
ok( false !== strpos( $seed, 'I BUILD THINGS<br>THAT SOUND RIGHT.' ) && false !== strpos( $seed, 'class="sn-hero-subtitle' ) && false !== strpos( $seed, 'href="/services"' ) && false !== strpos( $seed, 'href="/about"' ), 'the seed carries the hero: title, subtitle, both buttons' );
ok( false !== strpos( $seed, '<!-- wp:group {"className":"sn-hero-inner"' ) && false === strpos( $seed, '<div class="sn-hero-inner">' ), 'the inner wrapper is a Group block, not an unbalanced raw-HTML div' );
ok( substr_count( $seed, '<!-- wp:group' ) === substr_count( $seed, '<!-- /wp:group -->' ) && substr_count( $seed, '<div' ) === substr_count( $seed, '</div>' ), 'blocks and divs balance' );

$page = function ( $c ) { return (object) array( 'ID' => 383, 'post_type' => 'page', 'post_content' => $c ); };

// No static front page: nothing, and no flag (retries).
$GLOBALS['opt'] = array( 'show_on_front' => 'posts' ); $GLOBALS['posts'] = array( 383 => $page( '' ) );
sn_seed_home_page();
ok( '' === $GLOBALS['posts'][383]->post_content && ! get_option( SN_HOME_PAGE_SEEDED_OPT ), 'no static front page: untouched, not flagged' );

// Empty front page: seeded, flagged.
$GLOBALS['opt'] = array( 'show_on_front' => 'page', 'page_on_front' => 383 ); $GLOBALS['updates'] = 0;
sn_seed_home_page();
ok( $seed === $GLOBALS['posts'][383]->post_content && get_option( SN_HOME_PAGE_SEEDED_OPT ), 'empty front page: seeded with the hero and flagged' );

// Flagged: never runs again.
$GLOBALS['posts'][383]->post_content = '';
sn_seed_home_page();
ok( '' === $GLOBALS['posts'][383]->post_content && 1 === $GLOBALS['updates'], 'once flagged it never writes again' );

// Owner content: never overwritten, flagged.
$GLOBALS['opt'] = array( 'show_on_front' => 'page', 'page_on_front' => 383 ); $GLOBALS['posts'] = array( 383 => $page( '<p>The owner line.</p>' ) ); $GLOBALS['updates'] = 0;
sn_seed_home_page();
ok( '<p>The owner line.</p>' === $GLOBALS['posts'][383]->post_content && 0 === $GLOBALS['updates'] && get_option( SN_HOME_PAGE_SEEDED_OPT ), 'a front page with content is never overwritten' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
