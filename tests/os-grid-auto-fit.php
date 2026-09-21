<?php
/**
 * No `auto-fill` in the app's grids.
 *
 * `auto-fill` KEEPS the empty tracks it creates; `auto-fit` collapses them. A row
 * with fewer items than tracks therefore leaves the remainder blank under
 * auto-fill, instead of letting the items grow into it.
 *
 * Measured live 2026-09-10 on AI -> MCP Clients: `.snt-systems` was a 1,702px row
 * holding EIGHT 202px tracks for FOUR tiles. The tiles sat at 202px and the right
 * half of the section was empty -- the same dead-half-window defect the 820px
 * width cap produced, one level further in, and invisible to a width check
 * because the CONTAINER stretched correctly. Switching to auto-fit measured the
 * same four tiles at 417px, spanning 100%.
 *
 * `.snt-systems` is shared by AI -> MCP Clients, Connections -> Cloudways and the
 * Dashboard's Systems row, so the single word fixed three leaves.
 *
 * COMMENTS ARE STRIPPED FIRST. This file's own prose says "auto-fill" a dozen
 * times, and so does the rule's explanatory comment in the stylesheet; a scan
 * that read them would fail forever and a scan tuned to pass anyway would stop
 * seeing a real declaration.
 *
 * Run: php tests/os-grid-auto-fit.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$sheets = array(
	dirname( __DIR__ ) . '/apps/sn-dashboard/sn-dashboard.css',
	dirname( __DIR__ ) . '/assets/os-app.css',
);

$total_repeats = 0;
foreach ( $sheets as $path ) {
	$name = basename( $path );
	$css  = (string) file_get_contents( $path );
	ok( '' !== $css, "$name is readable" );

	$stripped = preg_replace( '#/\*.*?\*/#s', '', $css );
	ok( $stripped !== $css, "$name: comments were actually stripped (the scan is not reading prose)" );

	$repeats = preg_match_all( '/repeat\(\s*auto-(fill|fit)/', $stripped, $m );
	$total_repeats += $repeats;
	$fills = array_keys( $m[1] ?? array(), 'fill' );
	ok(
		array() === $fills,
		"$name: no repeat( auto-fill ) survives -- " . $repeats . ' auto-* repeat() rules, ' . count( $fills ) . ' of them auto-fill'
	);
}

// #1622: the tile rows moved onto the kit's <os-grid min-item-width>, whose
// updateTracks() fits the tracks to the width alone and KEEPS the empty ones,
// the auto-fill shape above. The same defect is held off by the track cap in
// os-app.css: snt_kit_grid() writes the item count and the tracks stop there.
// Pinned by declaration, so a rewrite of either half is loud.
$shared = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $sheets[1] ) );
ok( 1 === preg_match( '/os-grid\[min-item-width\]\s*\{[^}]*grid-template-columns:\s*repeat\(\s*min\(\s*var\(\s*--_os-grid-tracks[^)]*\)\s*,\s*var\(\s*--snt-grid-items[^)]*\)\s*\)/', $shared ), 'os-app.css caps an os-grid[min-item-width] at min( the kit\'s tracks, --snt-grid-items ), so a four-tile row never holds eight tracks' );
$kit = (string) file_get_contents( dirname( __DIR__ ) . '/inc/openstation-kit-display.php' );
ok( false !== strpos( $kit, "'style'          => '--snt-grid-items:' . count( \$items )" ), 'snt_kit_grid() writes the item count the cap reads' );
ok( $total_repeats >= 1, 'the scan still finds an auto-* grid to check (' . $total_repeats . ', the Home pulse) -- a stylesheet that stopped matching would otherwise pass silently' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
