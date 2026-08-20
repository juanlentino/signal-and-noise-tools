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
	// Drop COMMENTS — after the allow-list pass above, which needs them. Prose
	// paints nothing, and this file's own commentary quotes the very hex values
	// it documents. Counting those made the old exemption tally read 11 when
	// the file had ten real literals and one in a sentence about them.
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	// Drop CUSTOM-PROPERTY declarations. A token DEFINITION is the one place a
	// literal belongs — it is what every var() in the file resolves to, and a
	// palette that referenced only other tokens would have no values at all.
	//
	// This exemption would be a laundering route on its own: declare
	// `--sn-x:#fff` once, reference it everywhere, and this sweep goes quiet
	// while nothing inverts. The compensating check is in
	// tests/front-end-css-contrast.php — every literal-valued --sn-* token must
	// ALSO be declared for dark, and every resulting ink/surface pair is
	// contrast-checked in all three palettes. Neither file is sufficient alone.
	$css = preg_replace( '/--[a-zA-Z0-9-]+\s*:[^;}]*/', '', $css );
	preg_match_all( '/#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)/', $css, $m );
	return $m[0];
}

// THE EXEMPTION IS PAID OFF (v12.3.0). provenance-front.css was exempt while
// its three-tier epistemic palette — verified, asserted, unattributed — had no
// dark treatment, because choosing one was a DESIGN decision about legibility
// rather than a mechanical swap. That decision is made: the three tiers are now
// plugin-owned tokens with dark values chosen to sit in the same contrast band
// as their light counterparts, so the tiers stay equals in both schemes.
//
// The list stays here, empty, on purpose. An empty list is a claim anyone can
// check; deleting the mechanism would make the next exemption silent.
$exempt = array();

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

// The exemption must SHRINK, never grow silently. It is now zero.
ok( 0 === count( $exempt ), 'NO stylesheet is exempt — the provenance debt is paid, and any new entry here is new dark-mode debt' );

// Negative control: the detector must be able to FIND something.
ok( array() !== sn_naked_colours( '.x{background:#fff}' ), 'NEGATIVE CONTROL: a bare literal IS detected' );
ok( array() === sn_naked_colours( '.x{background:var(--wp--preset--color--void,#fff)}' ), 'and a var() fallback is NOT flagged' );
ok( array() === sn_naked_colours( "/* sn-allow-literal: ink on a fixed surface */\n.x{color:#fff}" ), 'and an allow-listed rule is NOT flagged' );
ok( array() === sn_naked_colours( ':root{--sn-x:#12703a}' ), 'and a token DEFINITION is not flagged — that is where a literal belongs' );
ok( array() !== sn_naked_colours( ':root{--sn-x:#12703a;background:#fff}' ), 'but a naked literal in the SAME rule as a definition still is (the strip is per-declaration, not per-rule)' );
ok( array() === sn_naked_colours( '/* the old value was #b00303 */ .x{color:var(--y)}' ), 'and a hex quoted in a COMMENT is not a paint instruction' );
ok( array() !== sn_naked_colours( '/* a comment */ .x{color:#b00303}' ), 'while a real declaration after a comment still is' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
