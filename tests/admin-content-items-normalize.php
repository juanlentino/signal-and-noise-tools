<?php
/**
 * CLI fixture for the v10.48.0 Now/Uses editor boundary.
 *
 * The editors stopped rendering one input per item and now render one TEXTAREA
 * per section. That change is confined to a boundary normalizer on purpose: the
 * row serializers keep their array contract and their existing tests. These
 * assertions pin the normalizer, including the case that matters most — a stale
 * form posting the OLD array shape must still save, because a tab left open
 * across the update would otherwise silently write nothing.
 *
 * Run: php tests/admin-content-items-normalize.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_key( $k ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function esc_url_raw( $u ) { return (string) $u; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_action( $h = null, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $t, $v, ...$a ) { return $v; }
$GLOBALS['__settings'] = array();
function sn_setting( $p, $d = null ) { return $GLOBALS['__settings'][ $p ] ?? $d; }
function sn_setting_update( $p, $v ) { $GLOBALS['__settings'][ $p ] = $v; return true; }

require __DIR__ . '/../inc/admin-post-actions.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Now/Uses editor boundary (v10.48.0)\n\n";

// ── Plain items (/now) ──
ok( array( 'a', 'b' ) === sn_content_items_normalize( "a\nb" ), 'splits on newlines' );
ok( array( 'a', 'b' ) === sn_content_items_normalize( "a\r\nb" ), 'splits on CRLF too' );
ok( array( 'a', 'b' ) === sn_content_items_normalize( "a\n\n\nb" ),
	'blank lines are DROPPED, not turned into empty items (an empty item would make the serializer refuse the whole save over one stray newline)' );
ok( array( 'a', 'b' ) === sn_content_items_normalize( "  a  \n\tb\t" ), 'each line is trimmed' );
ok( array( 'a', 'b' ) === sn_content_items_normalize( "- a\n* b" ),
	'a pasted markdown bullet is accepted rather than round-tripping as "- - a"' );
ok( array() === sn_content_items_normalize( '' ), 'empty string yields no items' );
ok( array() === sn_content_items_normalize( "   \n\n " ), 'whitespace-only yields no items' );
ok( array() === sn_content_items_normalize( null ), 'null degrades to no items, never a fatal' );

// THE COMPATIBILITY CASE: a tab left open across the update posts the old shape.
ok( array( 'x', 'y' ) === sn_content_items_normalize( array( 'x', 'y' ) ),
	'the OLD array shape still passes through — a stale open form must not silently save nothing' );

// ── Pairs (/uses) ──
$p = sn_content_pairs_normalize( "SSL UF8 | Advanced DAW controller\nPlain thing" );
ok( 2 === count( $p ), 'pairs: one entry per line' );
ok( 'SSL UF8' === $p[0]['name'] && 'Advanced DAW controller' === $p[0]['note'], 'pairs: name and note split on the pipe' );
ok( 'Plain thing' === $p[1]['name'] && '' === $p[1]['note'], 'pairs: a line with no pipe is all name, empty note' );
$p2 = sn_content_pairs_normalize( 'a | b | c' );
ok( 'b | c' === $p2[0]['note'], 'pairs: only the FIRST pipe splits, so a note may contain pipes' );
$p3 = sn_content_pairs_normalize( '| orphan note' );
ok( '' === $p3[0]['name'] && 'orphan note' === $p3[0]['note'],
	'pairs: a note with no name is PRESERVED so the serializer can refuse it — dropping it here would be silent data loss' );
ok( array( array( 'name' => 'z', 'note' => '' ) ) === sn_content_pairs_normalize( array( array( 'name' => 'z', 'note' => '' ) ) ),
	'pairs: the old array shape passes through unchanged' );

// ── End to end: the textarea shape must serialize identically to the old form ──
$from_textarea = sn_now_rows_to_text( array( array( 'label' => 'Building', 'items' => sn_content_items_normalize( "one\ntwo" ) ) ) );
$from_inputs   = sn_now_rows_to_text( array( array( 'label' => 'Building', 'items' => array( 'one', 'two' ) ) ) );
ok( $from_textarea === $from_inputs && "## Building\n- one\n- two" === $from_textarea,
	'end to end: a textarea and the old per-item inputs produce a BYTE-IDENTICAL document' );

$uses_textarea = sn_uses_rows_to_text( array( array( 'label' => 'Interface', 'items' => sn_content_pairs_normalize( "SSL UF8 | DAW controller" ) ) ) );
ok( "## Interface\n- SSL UF8 | DAW controller" === $uses_textarea, 'end to end: /uses pipes round-trip to the stored format exactly' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
