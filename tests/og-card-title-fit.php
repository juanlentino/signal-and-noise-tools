<?php
/**
 * Standalone tests for OG-card title auto-sizing (v9.25.2 layout fix).
 *
 * A 3-line title at 88px used to collide with the dek (both drew at y=450).
 * sn_og_fit_title() picks the largest ladder size whose wrapped block clears
 * the dek zone, so long titles shrink one step instead of overlapping.
 *
 * @since plugin v9.25.2
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }

require __DIR__ . '/../inc/og-card-generator.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "og-card title auto-fit — v9.25.2\n\n";

$ladder    = array( array( 88, 100 ), array( 74, 84 ), array( 62, 72 ) );
$title_top = 250;
$limit     = ( 630 - 180 ) - 64; // dek first baseline (450) minus a 64px gap = 386.

// Build a fit call whose wrap stub returns $counts[$size] throwaway lines.
function sn_fit_with( $ladder, $counts, $title_top, $limit ) {
	return sn_og_fit_title(
		$ladder,
		function ( $size ) use ( $counts ) { return array_fill( 0, (int) $counts[ $size ], 'X' ); },
		$title_top,
		$limit
	);
}

// 1-line title at 88 (bottom 250 <= 386) → stays 88.
$r = sn_fit_with( $ladder, array( 88 => 1, 74 => 1, 62 => 1 ), $title_top, $limit );
ok( 88 === $r['size'] && 100 === $r['lh'] && 1 === count( $r['lines'] ), 'a 1-line title stays at 88px' );

// 2-line title at 88 (bottom 350 <= 386) → stays 88 (common case unchanged).
$r = sn_fit_with( $ladder, array( 88 => 2, 74 => 2, 62 => 2 ), $title_top, $limit );
ok( 88 === $r['size'] && 2 === count( $r['lines'] ), 'a 2-line title stays at 88px (unchanged)' );

// 3-line title at 88 (bottom 450 > 386); at 74 it's 2 lines (bottom 334 <= 386) → 74.
$r = sn_fit_with( $ladder, array( 88 => 3, 74 => 2, 62 => 2 ), $title_top, $limit );
ok( 74 === $r['size'] && 84 === $r['lh'] && 2 === count( $r['lines'] ), 'a 3-line-at-88 title shrinks to 74px' );

// Still 3 lines at 74 (bottom 418 > 386) and at 62 (bottom 394 > 386): none fit → smallest step.
$r = sn_fit_with( $ladder, array( 88 => 3, 74 => 3, 62 => 3 ), $title_top, $limit );
ok( 62 === $r['size'] && 3 === count( $r['lines'] ), 'a very long title falls back to the smallest (62px)' );

// Invariant: the chosen block clears the limit whenever any ladder step could.
$r      = sn_fit_with( $ladder, array( 88 => 3, 74 => 2, 62 => 1 ), $title_top, $limit );
$bottom = $title_top + ( count( $r['lines'] ) - 1 ) * $r['lh'];
ok( $bottom <= $limit, 'the fitted title block clears the dek limit' );

// Empty ladder-line wrap is treated as 1 line (never a negative bottom).
$r = sn_fit_with( $ladder, array( 88 => 0, 74 => 0, 62 => 0 ), $title_top, $limit );
ok( 88 === $r['size'], 'an empty wrap still resolves to the largest size' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
