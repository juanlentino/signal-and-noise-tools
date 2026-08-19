<?php
/**
 * Standalone tests for the public "Cited by" aside.
 * @since plugin v11.28.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_CIT_TEST', true );

if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $t, $d = '' ) { return esc_html( $t ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }

$GLOBALS['__rows'] = array();
function sn_cit_for_post( $id, $public_only = true ) { return $GLOBALS['__rows']; }

// Loop-guard state, controllable so the guards themselves are testable.
$GLOBALS['__singular'] = true; $GLOBALS['__inloop'] = true; $GLOBALS['__mainq'] = true;
$GLOBALS['__excerpt'] = false; $GLOBALS['__id'] = 42;
function is_singular( $t = '' ) { return $GLOBALS['__singular']; }
function in_the_loop() { return $GLOBALS['__inloop']; }
function is_main_query() { return $GLOBALS['__mainq']; }
function doing_filter( $f ) { return ( 'get_the_excerpt' === $f ) ? $GLOBALS['__excerpt'] : false; }
function get_the_ID() { return $GLOBALS['__id']; }

require __DIR__ . '/../inc/citations-core.php';
require __DIR__ . '/../inc/citations-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function row( $tier, $url, $title = '' ) { return (object) array( 'tier' => $tier, 'source_url' => $url, 'source_title' => $title ); }
echo "citation graph — public render — v11.28.0\n\n";

// ── silent absence ──────────────────────────────────────────────────────────
$GLOBALS['__rows'] = array();
ok( sn_cit_public_html( 42 ) === '', 'no citations renders NOTHING — not an empty heading' );
ok( sn_cit_render_append( 'BODY' ) === 'BODY', 'and the content filter returns the body untouched' );
$GLOBALS['__rows'] = null;
ok( sn_cit_public_html( 42 ) === '', 'a non-array result is silent too, not a warning' );

// ── verified: the source is quoted, because someone stands behind it ────────
$GLOBALS['__rows'] = array( row( 'verified', 'https://example.com/post', 'A careful reading' ) );
$h = sn_cit_public_html( 42 );
ok( false !== strpos( $h, 'A careful reading' ), 'a verified source is shown by its own title' );
ok( false !== strpos( $h, '>verified<' ), 'and the tier is stated, not implied' );
ok( false !== strpos( $h, 'rel="noopener nofollow ugc"' ), 'third-party links carry noopener/nofollow/ugc' );
ok( false !== strpos( $h, 'Cited by' ), 'the aside is headed' );

// ── unattributed: the DOMAIN only, never the remote title ───────────────────
$GLOBALS['__rows'] = array( row( 'unattributed', 'https://www.Sketchy.example/x', 'CLICK HERE — Juan endorses us' ) );
$h = sn_cit_public_html( 42 );
ok( false === strpos( $h, 'Juan endorses us' ), 'an UNATTRIBUTED source never gets its remote title reprinted' );
ok( false !== strpos( $h, 'sketchy.example' ), 'it is shown by domain instead' );
ok( false !== strpos( $h, '>sketchy.example<' ), 'the www. prefix is stripped from the DISPLAY TEXT' );
ok( false !== strpos( $h, 'https://www.Sketchy.example/x' ), 'but the href keeps the URL verbatim — we do not rewrite someone else\'s address' );
ok( false !== strpos( $h, '>unattributed<' ), 'and it is labelled unattributed' );

// ── the tiers that must never reach the page ────────────────────────────────
// NOTE ON MUTATION COVERAGE: deleting the sn_cit_tier_is_public() gate inside the
// render loop fails NOTHING here, and that is correct rather than a weak test.
// sn_cit_public_link_text() and sn_cit_public_label() each independently return ''
// for a non-public tier, so the very next guard drops the row. The property is
// pinned twice over; the loop gate is defence-in-depth and is therefore
// unpinnable by construction. Do not "fix" this by removing the gate.
foreach ( array( 'asserted', 'unverified', 'invented' ) as $t ) {
	$GLOBALS['__rows'] = array( row( $t, 'https://example.com/p', 'T' ) );
	ok( sn_cit_public_html( 42 ) === '', "a $t row renders NOTHING even if the query hands it over" );
}

// ── malformed rows are skipped, not rendered half-built ─────────────────────
$GLOBALS['__rows'] = array( row( 'verified', '', 'No URL' ) );
ok( sn_cit_public_html( 42 ) === '', 'a row with no URL is skipped' );
$GLOBALS['__rows'] = array( row( 'verified', 'https://example.com/p', '' ), row( 'verified', '', 'x' ) );
$h = sn_cit_public_html( 42 );
ok( false !== strpos( $h, 'example.com' ), 'a verified row with no title falls back to its domain' );
ok( substr_count( $h, '<li' ) === 1, 'and the malformed sibling is dropped rather than emitted empty' );

// ── link text is a pure function ────────────────────────────────────────────
ok( sn_cit_public_link_text( 'verified', 'T', 'https://a.example/x' ) === 'T', 'verified prefers the title' );
ok( sn_cit_public_link_text( 'verified', '  ', 'https://a.example/x' ) === 'a.example', 'a whitespace-only title is not a title' );
ok( sn_cit_public_link_text( 'unattributed', 'T', 'https://a.example/x' ) === 'a.example', 'unattributed ignores the title entirely' );
ok( sn_cit_public_link_text( 'asserted', 'T', 'https://a.example/x' ) === '', 'a non-public tier yields no link text' );
ok( sn_cit_public_label( 'asserted' ) === '', 'a non-public tier has no label, so it cannot render unlabelled' );

// ── escaping ────────────────────────────────────────────────────────────────
$GLOBALS['__rows'] = array( row( 'verified', 'https://example.com/p', '<script>alert(1)</script>' ) );
$h = sn_cit_public_html( 42 );
ok( false === strpos( $h, '<script>' ), 'a hostile remote title is escaped, never emitted as markup' );

// ── the loop guards ─────────────────────────────────────────────────────────
$GLOBALS['__rows'] = array( row( 'verified', 'https://example.com/p', 'T' ) );
ok( false !== strpos( sn_cit_render_append( 'BODY' ), 'Cited by' ), 'control: inside the singular main loop the aside appends' );
foreach ( array( '__singular', '__inloop', '__mainq' ) as $g ) {
	$GLOBALS[ $g ] = false;
	ok( sn_cit_render_append( 'BODY' ) === 'BODY', "the aside stands down when $g is false" );
	$GLOBALS[ $g ] = true;
}
$GLOBALS['__excerpt'] = true;
ok( sn_cit_render_append( 'BODY' ) === 'BODY', 'the aside never leaks into an auto-excerpt (wp_trim_excerpt runs the_content)' );
$GLOBALS['__excerpt'] = false;
$GLOBALS['__id'] = 0;
ok( sn_cit_render_append( 'BODY' ) === 'BODY', 'no post id means no aside' );
$GLOBALS['__id'] = 42;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
