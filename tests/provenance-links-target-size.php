<?php
/**
 * The panel's action row: no two link hit areas overlap (axe target-size).
 *
 * The links are given a tall hit area by vertical padding cancelled with an
 * equal negative margin, so the row's layout does not move. When the row wraps
 * (Download proof, Git ledger and Verify stack at 320 and 375 wide), each
 * line's hit area reaches `padding` above and below its line box. A row gap
 * smaller than twice that padding makes the boxes overlap: the 2026-10-18
 * sweep measured 28px boxes on a 4.5px row gap, 22.4px clear against 24px.
 *
 * Standalone: reads the stylesheet, no WordPress.
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$css = (string) file_get_contents( __DIR__ . '/../assets/provenance-front.css' );
ok( '' !== $css, 'the provenance stylesheet reads' );

// Row gap: the first value of `gap` on the row (em).
preg_match( '/\.sn-prov-links\{[^}]*\bgap:([\d.]+)em/', $css, $g );
// Hit-area padding on each link (em), and the negative margin that cancels it.
preg_match( '/\.sn-prov-links a\{[^}]*padding:([\d.]+)em 0;margin:-([\d.]+)em 0/', $css, $p );
ok( isset( $g[1] ), 'the row gap is readable from the stylesheet' );
ok( isset( $p[1], $p[2] ), 'the link hit-area padding and its cancelling margin are readable' );

$row_gap = (float) ( $g[1] ?? 0 );
$pad     = (float) ( $p[1] ?? 0 );
ok( $pad > 0 && (float) ( $p[2] ?? 0 ) === $pad, 'the padding is cancelled by an equal margin, so the row layout never moves' );
ok( $row_gap >= 2 * $pad, sprintf( 'row gap %.2fem >= 2 x padding %.2fem: stacked hit areas do not overlap', $row_gap, $pad ) );

// At the row's font size (.7rem = 11.2px) the gap must also clear the 8px floor.
preg_match( '/\.sn-prov-links\{[^}]*font-size:([\d.]+)rem/', $css, $f );
$px = (float) ( $f[1] ?? 0 ) * 16;
ok( $px > 0 && $row_gap * $px >= 8, sprintf( 'row gap is %.1fpx at the row\'s %.1fpx type (>= 8px)', $row_gap * $px, $px ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
