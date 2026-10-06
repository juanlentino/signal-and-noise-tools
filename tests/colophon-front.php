<?php
/**
 * Tests for inc/colophon-front.php — the colophon's one real record and its
 * stylesheet's contracts (2026-10-06 spec-sheet design).
 * Run: php tests/colophon-front.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $s, $d = null ) { return (string) $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function get_permalink( $id ) { return 'https://example.com/n/' . $id . '/'; }
function get_the_title( $id ) { return 'Note &amp; ' . $id; }
$GLOBALS['__chains'] = array();
function get_posts( $a ) { return array_keys( $GLOBALS['__chains'] ); }
function sn_prov_get_chain( $id ) { return $GLOBALS['__chains'][ $id ] ?? array(); }
require dirname( __DIR__ ) . '/inc/colophon-front.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$good = array( 'version' => 2, 'status' => 'confirmed', 'bitcoin_block' => 963622, 'signature' => 'sig', 'content_hash' => '11904cabace3a8ae1e42dffc23aa4a6779c14e55cb91bf7d7b5866922c855016', 'committed_at' => '2026-08-22T18:30:12Z' );

echo "Group: which record\n";
ok( null === sn_colophon_pick_record( array() ), 'no notes: no record' );
$pending  = array_merge( $good, array( 'status' => 'pending', 'bitcoin_block' => 0 ) );
$unsigned = array_merge( $good, array( 'signature' => '' ) );
$genesis  = array_merge( $good, array( 'version' => 0 ) );
ok( null === sn_colophon_pick_record( array( 5 => array( $pending ), 6 => array( $unsigned ), 7 => array( $genesis ) ) ), 'pending, unsigned and genesis-only heads never qualify' );
$pick = sn_colophon_pick_record( array( 9 => array( $good, $pending ), 8 => array( $good ) ) );
ok( 8 === $pick['post_id'], 'the HEAD decides: a note whose newest version is still pending is skipped for the next one' );
ok( 3 === sn_colophon_pick_record( array( 3 => array( $good ), 2 => array( $good ) ) )['post_id'], 'the newest qualifying note wins' );

echo "\nGroup: the figure\n";
ok( '' === sn_colophon_record_html(), 'nothing qualifies: no figure, the row stays text' );
$GLOBALS['__chains'] = array( 4 => array( $good ) );
$html = sn_colophon_record_html();
ok( false !== strpos( $html, '<span class="sn-colophon-big">v2</span>2026-08-22' ), 'version and its date' );
ok( false !== strpos( $html, '11904cab…c855016' ), 'the fingerprint, shortened from both ends' );
ok( false !== strpos( $html, '<span class="sn-colophon-big">963622</span>' ), 'the Bitcoin block' );
ok( false !== strpos( $html, '<a href="https://example.com/n/4/">Note &amp; 4</a>' ), 'the note is named and linked, its title escaped once' );
ok( false !== strpos( $html, '<a class="sn-colophon-verify" href="https://example.com/verify">Verify a Note</a>' ), 'Verify a Note, by its visible name' );
ok( 4 === substr_count( $html, '<dt>' ) && false === strpos( $html, 'aria-label' ), 'four labeled tiles, no aria-label' );

echo "\nGroup: stylesheet contracts\n";
$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/colophon-front.css' );
ok( false !== strpos( $css, 'main.is-layout-constrained:has(.sn-colophon)>*' ) && false !== strpos( $css, 'var(--wp--custom--page-track,1320px)' ), 'the page, title and rule included, takes the shared page track' );
ok( false !== strpos( $css, '.sn-colophon-items{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr))' ), 'rows sit two across' );
ok( false !== strpos( $css, '.sn-colophon-item--records{grid-column:1/-1}' ), 'Records spans its band' );
ok( 1 === preg_match( '/@media \(max-width:640px\)\{[^}]*grid-template-columns:minmax\(0,1fr\)/', $css ), 'one column on a phone' );
$no_fallback = preg_replace( '/var\([^()]*,[^()]*\)/', '', $css );
ok( 0 === preg_match_all( '/#[0-9a-f]{3,6}\b/i', $no_fallback ), 'colors are theme tokens only (hex appears only as var() fallbacks)' );
ok( false === strpos( $css, 'opacity' ), 'dimmed text uses a token, never opacity' );
ok( false !== strpos( $css, 'min-height:44px' ), 'the Verify button is a 44px target' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
