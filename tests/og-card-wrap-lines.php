<?php
/**
 * Standalone tests for sn_og_wrap_lines()'s last-line ellipsis (#1221).
 *
 * `imagettfbbox` is a real GD extension function already loaded in this CLI,
 * so it cannot be redeclared here the way this suite fakes absent WP
 * functions. inc/og-card-generator.php routes every measurement through
 * sn_og_imagettfbbox(), guarded by function_exists() — pre-defining it here,
 * BEFORE requiring the generator, makes measurement deterministic: exactly
 * 10px per character, so a max_width can be chosen to land exactly on a
 * line boundary.
 *
 * @since plugin v13.109.22 (or the next version to ship)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }

// 10px per character, flat -- no kerning, no per-glyph variance. Good enough
// to land a candidate exactly on $max_width and catch an off-by-one in the
// overflow/ellipsis branch, which real font metrics could mask by accident.
function sn_og_imagettfbbox( $size, $angle, $font, $text ) {
	$w = 10 * mb_strlen( (string) $text, 'UTF-8' );
	return array( 0, 0, $w, 0, $w, -10, 0, -10 );
}

require __DIR__ . '/../inc/og-card-generator.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "og-card sn_og_wrap_lines() last-line ellipsis — #1221\n\n";

// Each word is 4 chars + 1 space = 50px/word at 10px/char. max_width=190px
// fits exactly 3 words+spaces-minus-trailing (aaaa bbbb cccc = 14 chars =
// 140px) with room to spare below the next word — chosen so the LAST allowed
// line's remaining text ("cccc dddd", 9 chars = 90px) fits inside max_width
// on its own, with zero overflow.
$words_fit_exactly = 'aaaa bbbb cccc dddd';
$lines = sn_og_wrap_lines( $words_fit_exactly, 10, '/no/such/font.ttf', 90, 2 );
ok( 2 === count( $lines ), 'wraps to exactly 2 lines: ' . implode( ' | ', $lines ) );
ok( 'cccc dddd' === end( $lines ), 'the last line is the exact-fit remainder, no ellipsis appended: got "' . end( $lines ) . '"' );
ok( false === strpos( end( $lines ), '…' ), 'no spurious ellipsis when the remainder fits exactly (#1221)' );

// Same shape, but now the remainder genuinely overflows (one extra word) —
// the ellipsis path must still fire and still fit within max_width.
$words_overflow = 'aaaa bbbb cccc dddd eeee';
$lines2 = sn_og_wrap_lines( $words_overflow, 10, '/no/such/font.ttf', 90, 2 );
ok( 2 === count( $lines2 ), 'still wraps to exactly 2 lines: ' . implode( ' | ', $lines2 ) );
ok( false !== strpos( end( $lines2 ), '…' ), 'a genuinely overflowing remainder still gets ellipsized: got "' . end( $lines2 ) . '"' );
$last_bbox = sn_og_imagettfbbox( 10, 0, '/no/such/font.ttf', end( $lines2 ) );
ok( ( $last_bbox[2] - $last_bbox[0] ) <= 90, 'the ellipsized line still fits within max_width: got "' . end( $lines2 ) . '"' );

// A title that fits in fewer than max_lines lines overall must never reach
// the last-allowed-line branch at all, so it can never gain a spurious "…".
$short = 'aaaa bbbb';
$lines3 = sn_og_wrap_lines( $short, 10, '/no/such/font.ttf', 500, 3 );
ok( 1 === count( $lines3 ) && 'aaaa bbbb' === $lines3[0], 'a short title that fits on one line is untouched: got ' . implode( ' | ', $lines3 ) );
ok( false === strpos( $lines3[0], '…' ), 'no ellipsis on a title that never overflows at all' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
