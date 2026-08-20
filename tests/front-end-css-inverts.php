<?php
/**
 * Tests: front-end CSS must invert with the palette.
 *
 * THE DEFECT THIS CLOSES. Dark mode is a TOKEN LAYER in the theme: theme
 * v12.0.0 redefines the `--wp--preset--color--*` custom properties under
 * `:root[data-theme="dark"]`. Anything referencing those tokens inverts for
 * free. A HARDCODED literal cannot, by construction.
 *
 * Reported live on 2026-08-20: /stats rendered its two charts as SOLID WHITE
 * BLOCKS in dark mode, from a single `background:#fff` on the chart SVG. The
 * prose around them was fine, because the prose used tokens.
 *
 * A LITERAL IS NOT ALWAYS A BUG. Ink on a surface that never inverts must stay
 * literal — tying it to the palette would put dark ink on a dark red button in
 * dark mode. Those carry an `sn-allow-literal` comment and are exempt.
 *
 * WHAT THIS CANNOT SEE: it reads stylesheets, not rendered pages. A token used
 * in the wrong ROLE — the theme's own "ink as chrome" bug, where an INK token
 * was used to mean "a surface that contrasts with the page" and so inverted
 * twice — passes this check cleanly. That failure needs a real render.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "front-end CSS inverts with the palette\n\n";

/**
 * Colours NOT sitting inside a var() fallback, and not in an allow-listed rule.
 */
function sn_naked_colours( $css ) {
	// Drop var(--x, <fallback>) — a fallback literal is the point of a fallback.
	$css = preg_replace( '/var\(\s*--[a-zA-Z0-9-]+\s*,[^)]*\)/', 'VAR', $css );
	// Drop any rule preceded by an sn-allow-literal comment.
	$css = preg_replace( '#/\*[^*]*sn-allow-literal.*?\*/[^}]*\}#s', '', $css );
	preg_match_all( '/#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)/', $css, $m );
	return $m[0];
}

// provenance-front.css is EXEMPT, deliberately and temporarily. Its remaining
// literals are the three-tier epistemic status palette — verified #12703a,
// asserted #7a5200, unattributed #5b6270 — which have no palette-token
// equivalent and whose dark-mode treatment is a DESIGN decision about
// legibility, not a mechanical swap. Named here so the debt is visible and
// countable rather than silent.
$exempt = array( 'assets/provenance-front.css' );

$files = glob( dirname( __DIR__ ) . '/assets/*front*.css' );
ok( count( $files ) > 5, 'the sweep finds the front-end stylesheets (guard: a glob matching nothing would pass vacuously)' );

$dirty = array();
foreach ( $files as $file ) {
	$rel = 'assets/' . basename( $file );
	if ( in_array( $rel, $exempt, true ) ) {
		continue;
	}
	$naked = sn_naked_colours( (string) file_get_contents( $file ) );
	if ( $naked ) {
		$dirty[ $rel ] = $naked;
	}
}

foreach ( $dirty as $rel => $naked ) {
	echo "FAIL: $rel has hardcoded colour(s): " . implode( ' ', array_slice( array_unique( $naked ), 0, 6 ) ) . "\n";
	$fail++;
}
ok( empty( $dirty ), 'NO front-end stylesheet paints a hardcoded colour — every one inverts with the palette' );

// The exemption must SHRINK, never grow silently.
ok( 1 === count( $exempt ), 'exactly ONE stylesheet is exempt — if this grows, someone added dark-mode debt without saying so' );
$prov = sn_naked_colours( (string) file_get_contents( dirname( __DIR__ ) . '/assets/provenance-front.css' ) );
ok( count( $prov ) <= 11, 'the exempt file has at most the 11 literals it had when exempted (' . count( $prov ) . ') — the debt may shrink, never grow' );

// Negative control: the detector must be able to FIND something.
ok( array() !== sn_naked_colours( '.x{background:#fff}' ), 'NEGATIVE CONTROL: a bare literal IS detected' );
ok( array() === sn_naked_colours( '.x{background:var(--wp--preset--color--void,#fff)}' ), 'and a var() fallback is NOT flagged' );
ok( array() === sn_naked_colours( "/* sn-allow-literal: ink on a fixed surface */\n.x{color:#fff}" ), 'and an allow-listed rule is NOT flagged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
