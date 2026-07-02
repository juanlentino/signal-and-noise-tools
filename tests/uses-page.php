<?php
/**
 * Standalone fixture tests for the /uses page content editor (plugin v7.6.0).
 *
 * Owner direction 2026-07-01: /uses gets the same plugin-managed content
 * behavior as /now. The theme's seam (`sn_uses_groups`, shipped v10.10.0
 * with the uses trio and explicitly documented as "the deferred-admin-UI
 * seam") finally gets fed. inc/uses-page.php mirrors inc/now-page.php but
 * items are {name, note} pairs — an optional ` | ` splits name from note:
 *
 *   ## Interface & control
 *   - Universal Audio Apollo Twin X DUO | Custom 10 plug-in upgrade
 *   - SSL UF8
 *
 * The section grammar is shared with the /now parser (sn_now_parse_sections);
 * this module maps its string items to pairs. Same fallback discipline: empty
 * clears, zero-group content never replaces the theme file content. A
 * serializer round-trips the theme's live groups so the editor can prefill
 * from the current file content instead of making the owner retype it.
 *
 * Run: php tests/uses-page.php
 * @since plugin v7.6.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_NOW_PAGE_TEST', true );  // suppress /now filter wiring (shared parser rides in)
define( 'SN_USES_PAGE_TEST', true ); // suppress /uses filter wiring

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $fmt, $ts = null ) { return '1999-12-31'; } }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) {
	$same = isset( $GLOBALS['__options'][ $k ] ) && $GLOBALS['__options'][ $k ] === $v;
	$GLOBALS['__options'][ $k ] = $v;
	$GLOBALS['__last_autoload'] = $autoload;
	return ! $same;
}
function delete_option( $k ) { $had = isset( $GLOBALS['__options'][ $k ] ); unset( $GLOBALS['__options'][ $k ] ); return $had; }

require_once __DIR__ . '/../inc/now-page.php';  // shared section grammar
require_once __DIR__ . '/../inc/uses-page.php';

// ── parser: name | note pairs ──
echo "\nTest: sn_uses_parse_groups\n";
$raw = "## Interface & control\n- Universal Audio Apollo Twin X DUO | Custom 10 plug-in upgrade\n- SSL UF8\n\n## Headphones\nAudeze LCD-X | Creator Package\n";
$groups = sn_uses_parse_groups( $raw );
ok( 2 === count( $groups ), 'two groups parsed' );
ok( 'Interface & control' === ( $groups[0]['label'] ?? '' ), 'label parsed' );
ok( array( 'name' => 'Universal Audio Apollo Twin X DUO', 'note' => 'Custom 10 plug-in upgrade' ) === ( $groups[0]['items'][0] ?? null ), 'pipe splits name | note' );
ok( array( 'name' => 'SSL UF8', 'note' => '' ) === ( $groups[0]['items'][1] ?? null ), 'no pipe → empty note' );
ok( 'Audeze LCD-X' === ( $groups[1]['items'][0]['name'] ?? '' ), 'bare (dashless) item lines work' );
ok( array() === sn_uses_parse_groups( 'prose with no headers' ), 'headerless content → no groups' );
// A name containing a pipe only splits on the FIRST pipe.
$multi = sn_uses_parse_groups( "## G\n- A | B | C\n" );
ok( 'A' === ( $multi[0]['items'][0]['name'] ?? '' ) && 'B | C' === ( $multi[0]['items'][0]['note'] ?? '' ), 'only the first pipe splits (rest stays in the note)' );

// ── serializer round-trips (prefill from the theme's live groups) ──
echo "\nTest: sn_uses_serialize_groups\n";
$text = sn_uses_serialize_groups( $groups );
ok( false !== strpos( $text, '## Interface & control' ), 'serializer emits section headers' );
ok( false !== strpos( $text, '- Universal Audio Apollo Twin X DUO | Custom 10 plug-in upgrade' ), 'serializer emits name | note' );
ok( false !== strpos( $text, "- SSL UF8\n" ) && false === strpos( $text, 'SSL UF8 |' ), 'empty note emits no pipe' );
ok( sn_uses_parse_groups( $text ) === $groups, 'parse(serialize(groups)) round-trips exactly' );
ok( '' === sn_uses_serialize_groups( array() ), 'empty groups → empty string' );
ok( '' === sn_uses_serialize_groups( 'hostile' ), 'non-array input → empty string (no fatal)' );

// ── save / get round-trip ──
echo "\nTest: sn_uses_page_save / sn_uses_page_get\n";
ok( true === sn_uses_page_save( $raw ), 'save returns true' );
ok( false === ( $GLOBALS['__last_autoload'] ?? true ), 'option stored autoload=no' );
$page = sn_uses_page_get();
ok( is_array( $page ) && $raw === ( $page['raw'] ?? '' ), 'raw round-trips' );
ok( '1999-12-31' === ( $page['updated'] ?? '' ), 'stamp uses wp_date (site timezone) — the v7.5.1 lesson, from day one here' );
ok( false === sn_uses_page_save( $raw ), 'identical re-save returns false' );
ok( true === sn_uses_page_save( '  ' ), 'whitespace-only save clears' );
ok( null === sn_uses_page_get(), 'cleared → null' );
$GLOBALS['__options']['sn_uses_page'] = 'hostile';
ok( null === sn_uses_page_get(), 'hostile stored shape → null' );
$GLOBALS['__options'] = array();

// ── theme-filter callback ──
echo "\nTest: sn_tf_uses_groups\n";
$theme_default = array( array( 'label' => 'Theme file', 'items' => array( array( 'name' => 'fallback', 'note' => '' ) ) ) );
ok( $theme_default === sn_tf_uses_groups( $theme_default ), 'no saved content → theme default passes through' );
sn_uses_page_save( "## Plugin Group\n- plugin item | with note\n" );
$out = sn_tf_uses_groups( $theme_default );
ok( 'Plugin Group' === ( $out[0]['label'] ?? '' ), 'saved content REPLACES the theme groups' );
sn_uses_page_save( "no headers, just prose\n" );
ok( $theme_default === sn_tf_uses_groups( $theme_default ), 'zero-group saved content → theme default (never a blank /uses)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
