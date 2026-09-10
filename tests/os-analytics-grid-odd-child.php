<?php
/**
 * The S&N Analytics grids and their odd last child.
 *
 * `.sn-an-grid` and its six siblings are a FIXED two-track grid. A view that
 * feeds one an ODD number of panels strands the last in the left track with the
 * right half empty. Measured live 2026-09-10 at a 1,557px window: the last
 * child's right edge landed at 49% on Technology (5 panels), Engagement (3),
 * Posts (3) and Traffic & edge (1). Same empty-right-half symptom as the Home
 * app's 820px width cap, a different cause -- not a cap, an odd count against an
 * even track count.
 *
 * Two things are pinned:
 *
 * 1. The odd last child spans both tracks.
 * 2. The rule covers the SAME :is() list as the grid rule itself. Fixing only
 *    `.sn-an-grid` would leave six classes carrying the identical latent bug,
 *    and nothing else would notice until a view happened to use one.
 *
 * COMMENTS ARE STRIPPED FIRST, both block and line forms: this file's prose and
 * the rule's own comment both name the selectors they are about.
 *
 * Run: php tests/os-analytics-grid-odd-child.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$path = dirname( __DIR__ ) . '/apps/sn-analytics/sn-analytics.css';
$raw  = (string) file_get_contents( $path );
ok( '' !== $raw, 'the analytics stylesheet is readable' );

$css = preg_replace( '#/\*.*?\*/#s', '', $raw );
$css = preg_replace( '#^\s*//.*$#m', '', (string) $css );
ok( $css !== $raw, 'comments were stripped (the scan is not reading its own prose)' );

// The grid classes are DERIVED from the rules that declare the two tracks, so a
// new grid class added there is covered here without editing this test.
//
// Two traps this derivation had to survive. First, `preg_match` alone found
// `.snt-grid` -- an unrelated earlier rule that also uses repeat(2). Second, and
// worse, taking every `sn-an-*` in the selector picked up `.sn-an-header-main`,
// which is an ANCESTOR in `.sn-an-header-main .snt-native-stats`: the grid there
// is the stat row, and spanning a stat row's odd last child would be wrong.
// Only the FINAL compound names the element the rule applies to.
$classes = array();
preg_match_all( '/([^{}]*)\{[^{}]*grid-template-columns:\s*repeat\(\s*2[^{}]*\}/s', $css, $all, PREG_SET_ORDER );
foreach ( $all as $rule ) {
	foreach ( explode( ',', preg_replace( '/:is\(([^()]*)\)/', ':is()', $rule[1] ) ) as $sel ) {
		// Restore the :is() list only for the selector we are inspecting.
	}
	$sel = trim( $rule[1] );
	// Mask :is(...) so its internal spaces are not read as descendant combinators.
	$masked = preg_replace( '/:is\([^()]*\)/', ':IS', $sel );
	$parts  = preg_split( '/\s*[\s>+~]\s*/', $masked, -1, PREG_SPLIT_NO_EMPTY );
	$last   = (string) end( $parts );
	if ( false === strpos( $last, ':IS' ) && false === strpos( $last, 'sn-an-' ) ) { continue; }
	// The final compound is the grid: pull its classes back out of the original.
	if ( false !== strpos( $last, ':IS' ) && preg_match( '/:is\(([^()]*)\)\s*$/', $sel, $inner ) ) {
		preg_match_all( '/\.(sn-an-[a-z-]+)/', $inner[1], $c );
	} else {
		preg_match_all( '/\.(sn-an-[a-z-]+)/', $last, $c );
	}
	foreach ( $c[1] as $cls ) { $classes[ $cls ] = true; }
}
$classes = array_keys( $classes );
ok( count( $classes ) >= 5, 'the two-track grid classes were derived from the CSS (' . count( $classes ) . '): ' . implode( ', ', $classes ) );

// 1. The span rule exists and names every one of them.
if ( preg_match( '/([^{}]*):last-child:nth-child\(\s*odd\s*\)\s*\{([^{}]*)\}/s', $css, $span ) ) {
	ok( true, 'an odd-last-child rule exists' );
	ok(
		preg_match( '/grid-column:\s*1\s*\/\s*-1/', $span[2] ),
		'...and it spans both tracks (grid-column: 1 / -1)'
	);
	$missing = array();
	foreach ( $classes as $cls ) {
		if ( false === strpos( $span[1], '.' . $cls ) ) { $missing[] = $cls; }
	}
	// The count guard is the point: with an empty $classes this assertion is
	// trivially true, and it passed that way on the first run.
	ok(
		count( $classes ) >= 5 && array() === $missing,
		'...and covers EVERY one of the ' . count( $classes ) . ' two-track classes'
			. ( $missing ? ': missing ' . implode( ', ', $missing ) : '' )
	);
} else {
	ok( false, 'an odd-last-child rule exists' );
}

// 2. An empty grid renders nothing.
$has_empty = (bool) preg_match( '/:empty\s*\{[^{}]*display:\s*none/s', $css );
ok( $has_empty, 'an empty grid is hidden rather than occupying a row' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
