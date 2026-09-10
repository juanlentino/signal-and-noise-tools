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
 * Run: php tests/os-grid-auto-fit.css.php
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

ok( $total_repeats >= 3, 'the scan actually found auto-* grids to check (' . $total_repeats . ') -- a stylesheet that stopped matching would otherwise pass silently' );

// The specific rule this was found in, pinned by name so a rewrite is loud.
$dash = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $sheets[0] ) );
if ( preg_match( '/\.snt-systems\s*\{([^}]*)\}/', $dash, $m ) ) {
	ok( false !== strpos( $m[1], 'auto-fit' ), '.snt-systems uses auto-fit' );
	ok( false === strpos( $m[1], 'auto-fill' ), '...and not auto-fill' );
} else {
	ok( false, '.snt-systems rule still exists' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
