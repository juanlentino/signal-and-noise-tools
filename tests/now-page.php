<?php
/**
 * Standalone fixture tests for the /now page content editor (plugin v7.5.0).
 *
 * inc/now-page.php: the owner asked for /now content to live in the plugin
 * ("done in content instead... Content in the plugin"), not hardcoded in the
 * theme data file. This module stores an owner-edited plain-text document in a
 * durable autoload=no option (transients are flush-volatile under Breeze) and
 * parses it into the section shape that sn_now_page_save() regenerates the
 * /now Page from. Empty/unparseable option = the prior page content stands.
 *
 * Format: `## Label` opens a section; every other non-empty line is an item
 * (leading `- ` / `* ` stripped). Items before the first header are dropped.
 *
 * Run: php tests/now-page.php
 * @since plugin v7.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
// wp_date sentinel: a value gmdate can NEVER produce (fixed past date), so
// the site-timezone assertion below can only pass through the wp_date path.
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $fmt, $ts = null ) { return '1999-12-31'; } }
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
// v7.5.1: the stamp must be the SITE-timezone date (wp_date), not UTC —
// gmdate() stamped the owner's July-1-evening US-Eastern save as "July 2".
// The harness stubs wp_date with an impossible-for-gmdate sentinel; the
// stored stamp must be it.
ok( '1999-12-31' === ( $page['updated'] ?? '' ), 'stamp uses wp_date (site timezone), not gmdate/UTC' );
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
