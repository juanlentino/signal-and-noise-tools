<?php
/**
 * Standalone fixture tests for the /now page content editor (plugin v7.5.0).
 *
 * inc/now-page.php: the owner asked for /now content to live in the plugin
 * ("done in content instead... Content in the plugin"), not hardcoded in the
 * theme data file. The theme shipped the seams for exactly this:
 * `sn_now_sections` (v10.21.0) + `sn_now_updated` (v10.21.1). This module
 * stores an owner-edited plain-text document in a durable autoload=no option
 * (transients are flush-volatile under Breeze), parses it into the theme's
 * section shape, and feeds both filters. Empty option = theme-file fallback.
 *
 * Format: `## Label` opens a section; every other non-empty line is an item
 * (leading `- ` / `* ` stripped). Items before the first header are dropped.
 *
 * Run: php tests/now-page.php
 * @since plugin v7.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_NOW_PAGE_TEST', true ); // suppress add_filter wiring on require

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) {
	$same = isset( $GLOBALS['__options'][ $k ] ) && $GLOBALS['__options'][ $k ] === $v;
	$GLOBALS['__options'][ $k ] = $v;
	$GLOBALS['__last_autoload'] = $autoload;
	return ! $same;
}
function delete_option( $k ) { $had = isset( $GLOBALS['__options'][ $k ] ) ; unset( $GLOBALS['__options'][ $k ] ); return $had; }

require_once __DIR__ . '/../inc/now-page.php';

// ── parser ──
echo "\nTest: sn_now_parse_sections\n";
$raw = "## Building\n- Signal & Noise, shipped in public.\nThe provenance series; first note in July.\n\n## Listening\n* Current rotation goes here.\n\n## Empty Section\n\n## Reading\n- A book.\n";
$sections = sn_now_parse_sections( $raw );
ok( 3 === count( $sections ), 'three non-empty sections parsed (empty section dropped)' );
ok( 'Building' === ( $sections[0]['label'] ?? '' ), 'first label parsed' );
ok( array( 'Signal & Noise, shipped in public.', 'The provenance series; first note in July.' ) === ( $sections[0]['items'] ?? array() ), 'dash-prefixed AND bare lines both count as items, prefixes stripped' );
ok( 'Current rotation goes here.' === ( $sections[1]['items'][0] ?? '' ), 'star prefix stripped too' );
ok( array() === sn_now_parse_sections( '' ), 'empty raw → no sections' );
ok( array() === sn_now_parse_sections( "just some lines\n- with items\n" ), 'items before any header are dropped (no label-less sections)' );
ok( array() === sn_now_parse_sections( "## Only Header\n\n## Another\n" ), 'headers without items → no sections' );

// ── save / get round-trip ──
echo "\nTest: sn_now_page_save / sn_now_page_get\n";
ok( true === sn_now_page_save( $raw ), 'save returns true' );
ok( false === ( $GLOBALS['__last_autoload'] ?? true ), 'option stored autoload=no (durable under Breeze flushes)' );
$page = sn_now_page_get();
ok( is_array( $page ) && $raw === ( $page['raw'] ?? '' ), 'raw round-trips' );
ok( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $page['updated'] ?? '' ) ), 'save stamps updated as YYYY-MM-DD' );
ok( 3 === count( sn_now_page_sections() ), 'sn_now_page_sections() parses the stored raw' );

// unchanged content re-save → false (drives the "no changes" flash) but keeps the stamp shape.
ok( false === sn_now_page_save( $raw ), 're-saving identical content returns false' );

// empty save clears the option entirely (back to theme-file content).
ok( true === sn_now_page_save( "  \n " ), 'whitespace-only save clears' );
ok( null === sn_now_page_get(), 'cleared option → get returns null' );
ok( array() === sn_now_page_sections(), 'cleared option → no sections' );

// hostile stored shapes degrade to null, never fatal.
$GLOBALS['__options']['sn_now_page'] = 'not-an-array';
ok( null === sn_now_page_get(), 'non-array stored value → null' );
$GLOBALS['__options'] = array();

// ── theme-filter callbacks ──
echo "\nTest: sn_tf_now_sections / sn_tf_now_updated\n";
$theme_default_sections = array( array( 'label' => 'Theme file', 'items' => array( 'fallback' ) ) );
ok( $theme_default_sections === sn_tf_now_sections( $theme_default_sections ), 'no saved content → theme default passes through' );
ok( '2026-07-01' === sn_tf_now_updated( '2026-07-01' ), 'no saved content → theme default date passes through' );

sn_now_page_save( "## Plugin Section\n- plugin item\n" );
$out = sn_tf_now_sections( $theme_default_sections );
ok( is_array( $out ) && 'Plugin Section' === ( $out[0]['label'] ?? '' ), 'saved content REPLACES the theme sections' );
ok( sn_tf_now_updated( '2026-07-01' ) === ( sn_now_page_get()['updated'] ?? '' ), 'saved content supplies its save-stamp as the updated date' );

// content that parses to NOTHING (e.g. prose without headers) must fall back,
// not blank the live page.
sn_now_page_save( "no headers here, just prose\n" );
ok( $theme_default_sections === sn_tf_now_sections( $theme_default_sections ), 'unparseable saved content → theme default (never a blank /now)' );
ok( '2026-07-01' === sn_tf_now_updated( '2026-07-01' ), 'unparseable saved content → theme default date' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
