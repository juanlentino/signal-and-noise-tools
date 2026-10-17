<?php
/**
 * Standalone test: the /maturity index cards carry their family's roadmap mix
 * as an LED ladder (inc/maturity-index-page.php, 2026-10-17). One segment per
 * roadmap item, solid for done, dashed for planned, dotted for considering,
 * hollow for later, with the counts as text. Read from the live roadmap board,
 * so it never disagrees with /maturity/roadmap.
 *
 * Run: php tests/maturity-index-ladder.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
define( 'SNT_VERSION', 'test' );
if ( ! defined( 'SN_MATURITY_ROADMAP_STATUSES' ) ) { define( 'SN_MATURITY_ROADMAP_STATUSES', array( 'done', 'planned', 'considering', 'later' ) ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function apply_filters( $h, $v ) { return $v; }
function add_shortcode( $t, $c ) {}
function wp_enqueue_style() {}
function plugins_url( $p, $f ) { return 'https://x.test/' . $p; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_posts( $q ) { return array( (object) array( 'ID' => 1 ) ); }
function get_permalink( $p ) { return 'https://x.test/maturity/x/'; }
$GLOBALS['__board'] = array(
	'Analytics'       => array( 'done' => array( 'a', 'b', 'c', 'd', 'e' ), 'planned' => array( 'f' ), 'considering' => array( 'g', 'h' ), 'later' => array( 'i', 'j' ) ),
	'Proof of origin' => array( 'done' => array( 'a' ), 'later' => array( '<script>' ) ),
);
function sn_maturity_roadmap_board() { return $GLOBALS['__board']; }
require dirname( __DIR__ ) . '/inc/maturity-index-page.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$l = sn_maturity_index_ladder( $GLOBALS['__board']['Analytics'] );
ok( 5 === substr_count( $l, '<i class="is-done"></i>' ) && 1 === substr_count( $l, '<i class="is-planned"></i>' ) && 2 === substr_count( $l, '<i class="is-considering"></i>' ) && 2 === substr_count( $l, '<i class="is-later"></i>' ), 'one segment per roadmap item, in status order' );
ok( strpos( $l, 'is-done' ) < strpos( $l, 'is-planned' ) && strpos( $l, 'is-planned' ) < strpos( $l, 'is-considering' ) && strpos( $l, 'is-considering' ) < strpos( $l, 'is-later' ), 'done, then planned, then considering, then later' );
ok( false !== strpos( $l, 'class="sn-maturity-ladder" aria-hidden="true"' ), 'the segments are decorative' );
ok( false !== strpos( $l, '<span class="sn-maturity-ladder-n">5 done · 1 planned · 2 considering · 2 later</span>' ), 'the counts are real text' );
ok( '' === sn_maturity_index_ladder( null ) && '' === sn_maturity_index_ladder( array() ) && '' === sn_maturity_index_ladder( array( 'done' => array() ) ), 'no items, no ladder: never a row of zeros' );
ok( false === strpos( sn_maturity_index_ladder( $GLOBALS['__board']['Proof of origin'] ), '<script>' ), 'item text never reaches the ladder, only counts' );
ok( false !== strpos( sn_maturity_index_ladder( $GLOBALS['__board']['Proof of origin'] ), '1 done · 1 later' ), 'empty statuses are left out of the text' );

$html = sn_maturity_index_shortcode();
ok( 2 === substr_count( $html, 'class="sn-maturity-ladder"' ), 'the shortcode draws a ladder for each family on the board, matched by its label' );
ok( 1 === preg_match( '#sn-maturity-index-card--analytics"[^>]*>.*?sn-maturity-ladder.*?</a>#s', $html ), 'the ladder sits inside its own card' );
ok( 0 === preg_match( '#sn-maturity-index-card--roadmap"[^>]*>(?:(?!</a>).)*sn-maturity-ladder#s', $html ), 'the Roadmap card has no family, so no ladder' );
$GLOBALS['__board'] = array();
ok( 0 === substr_count( sn_maturity_index_shortcode(), 'sn-maturity-ladder' ), 'an empty board draws nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
