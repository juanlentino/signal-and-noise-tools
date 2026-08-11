<?php
/**
 * Tests: /verify panel contrast — AA on the SHIPPED stylesheet.
 *
 * Sibling of tests/provenance-front-contrast.php, and it exists because the
 * rendered-pair scan (tools/contrast-render-scan.mjs) found what neither the
 * arithmetic tier nor the declared-usage tier could: --concrete, a SURFACE grey,
 * used as a TEXT colour at 2.68:1. That scan needs a browser and a live site, so
 * it can never run in CI — this fixture is the part of its finding that CAN be
 * pinned without one, read from the real file rather than from a copy of the
 * values.
 *
 * DELIBERATELY NOT PINNED: .sn-verify-check-no (the 01/02/03 numerals) and the
 * footer's middot separator. Both carry aria-hidden="true" and convey nothing a
 * reader must read — WCAG 1.4.3 exempts purely decorative text, so scoring them
 * would manufacture failures a reader can never experience. That exemption is an
 * ASSERTION IN THE MARKUP, so this file checks the markup still makes it: if the
 * aria-hidden ever comes off, the exemption dies with it and the numeral is a
 * 1.32:1 defect. The test fails in that case rather than staying quietly true.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

function pvc_lum( $hex ) {
	// A missing token used to arrive here as '' and return luminance 0 — which
	// is PURE BLACK, so an UNDEFINED colour scored 21:1 and every ratio
	// assertion about it passed. That is the absent-reads-as-a-great-result
	// trap in its purest form, and it hid a real bug: four rules referencing a
	// --signal-ink that was never defined. Refuse to score what does not exist.
	if ( ! is_string( $hex ) || 1 !== preg_match( '/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $hex ) ) ) {
		echo "  FAIL - pvc_lum() got a non-colour (" . var_export( $hex, true ) . ") — an absent token must never score as black\n";
		global $fail;
		++$fail;
		return NAN;
	}
	$hex = ltrim( trim( $hex ), '#' );
	if ( 3 === strlen( $hex ) ) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
	$c = array();
	foreach ( array( 0, 2, 4 ) as $i ) {
		$v   = hexdec( substr( $hex, $i, 2 ) ) / 255;
		$c[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function pvc_ratio( $a, $b ) {
	$la = pvc_lum( $a ); $lb = pvc_lum( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

$css_path = __DIR__ . '/../assets/css/prov-verify.css';
$css      = (string) file_get_contents( $css_path );
ok( '' !== $css, 'the shipped stylesheet is readable (the file, never a copy of its values)' );

// Resolve the palette from the file itself — a test carrying its own hex copies
// would keep passing after someone edited the real token.
$tok = array();
foreach ( array( 'void', 'bone', 'asphalt', 'concrete', 'concrete-ink', 'rust', 'blood', 'signal', 'signal-ink' ) as $name ) {
	if ( preg_match( '/--' . preg_quote( $name, '/' ) . '\s*:\s*(#[0-9a-fA-F]{3,6})/', $css, $m ) ) {
		$tok[ $name ] = $m[1];
	}
}
ok( isset( $tok['concrete-ink'] ), '--concrete-ink is defined in the palette' );
ok( isset( $tok['void'] ) && '#fff' === strtolower( $tok['void'] ), 'the panel surface is --void #fff (the background these ratios are against)' );

echo "\nGroup: the state stamp is READ, so it clears AA\n";
// The base rule is what an unsettled ('pending') and an UNREACHABLE row render.
ok( 1 === preg_match( '/\.sn-verify-check-state\s*\{[^}]*color:\s*var\(--concrete-ink\)/s', $css ), 'the stamp text uses --concrete-ink, not the surface grey' );
ok( 1 === preg_match( '/\.sn-verify-check-state\s*\{[^}]*border:\s*2px solid var\(--concrete-ink\)/s', $css ), 'the stamp border uses --concrete-ink too' );
$r = pvc_ratio( $tok['concrete-ink'], $tok['void'] );
ok( $r >= 4.5, sprintf( 'stamp text %s on %s = %.2f:1 — clears AA 4.5:1 for normal text', $tok['concrete-ink'], $tok['void'], $r ) );
ok( $r >= 3.0, sprintf( 'stamp border = %.2f:1 — clears 1.4.11 non-text 3:1 (the border carries the state)', $r ) );

echo "\nGroup: the regression this replaces\n";
$old = pvc_ratio( $tok['concrete'], $tok['void'] );
ok( $old < 4.5, sprintf( '--concrete really was failing as text: %.2f:1 (the finding was real, not a scanner artefact)', $old ) );
ok( false === strpos( $css, 'border:2px solid var(--concrete);' ), '--concrete is no longer the stamp\'s border' );
// The UNREACHABLE stamp is a SETTLED state a reader acts on — it must not keep
// the surface grey just because it is the quiet outcome.
ok( 1 === preg_match( '/\[data-state="UNREACHABLE"\]\s*\.sn-verify-check-state\{[^}]*color:\s*var\(--concrete-ink\)/', $css ), 'the UNREACHABLE stamp clears AA as well as the pending one' );

echo "\nGroup: the settled state colours still pass on their own terms\n";
foreach ( array( 'bone' => 'PASS', 'blood' => 'FAIL', 'rust' => 'NOTE' ) as $t => $state ) {
	$rr = pvc_ratio( $tok[ $t ], $tok['void'] );
	ok( $rr >= 4.5, sprintf( '%s stamp (--%s %s) = %.2f:1', $state, $t, $tok[ $t ], $rr ) );
}

echo "\nGroup: --concrete as TEXT — an explicit exempt list, so a NEW one fails\n";
// v10.90.1 fixed the stamp and stopped there. Four more selectors were still
// painting real text with the surface grey at 2.68:1 — a placeholder, a result
// label, the status line, and the noscript message, which is read by exactly
// the readers who cannot watch the checks run. Enumerating what is ALLOWED to
// keep --concrete is the only form of this check that catches the next one:
// counting occurrences passes as soon as someone adds a fifth.
$exempt = array(
	'.sn-verify-check[data-state="UNREACHABLE"] .sn-verify-check-no' => 'aria-hidden numeral (1.4.3 decoration)',
	'.sn-verify-walk-no'                                             => 'aria-hidden numeral, set in prov-verify.js',
	'.sn-verify-foot'                                                => 'its links set their own colour; the only inherited text is the aria-hidden middot',
);
$offenders = array();
foreach ( preg_split( '/\}/', $css ) as $block ) {
	if ( false === strpos( $block, 'color:var(--concrete)' ) ) {
		continue;
	}
	$sel = trim( preg_replace( '/\s+/', ' ', substr( $block, 0, strpos( $block, '{' ) ?: 0 ) ) );
	$sel = trim( preg_replace( '~/\*.*?\*/~s', '', $sel ) );
	$ok  = false;
	foreach ( array_keys( $exempt ) as $e ) {
		if ( false !== strpos( $sel, $e ) ) { $ok = true; break; }
	}
	if ( ! $ok ) { $offenders[] = $sel; }
}
ok( array() === $offenders, 'no NEW selector paints text with the surface grey' . ( $offenders ? ' — FOUND: ' . implode( ' | ', $offenders ) : '' ) );
ok( 3 === count( $exempt ), 'the exempt list is 3 entries, each with a stated reason — it is a list, not a wildcard' );
foreach ( array( '.sn-verify-form input::placeholder', '.sn-verify-status-line', '.sn-verify-noscript' ) as $fixed ) {
	ok( false !== strpos( $css, $fixed ) && 1 === preg_match( '/' . preg_quote( $fixed, '/' ) . '\{[^}]*var\(--concrete-ink\)/', $css ), $fixed . ' reads at 4.54:1' );
}

echo "\nGroup: --signal is an OUTLINE colour, never text and never a surface under text\n";
// 3.29:1 clears 1.4.11's 3:1 for a non-text indicator and fails 1.4.3's 4.5:1
// for text. The token was never wrong; using it as text was.
// The token itself is GONE from this file (dead after v10.90.1 routed every
// use through --signal-ink). Scored from the literal it used to hold, so the
// reason the ink token exists survives the removal of the thing it replaced.
$sig = pvc_ratio( '#ff4c47', $tok['void'] );
ok( $sig >= 3.0 && $sig < 4.5, sprintf( 'the retired #ff4c47 was %.2f:1 — an outline colour, never text', $sig ) );
ok( ! isset( $tok['signal'] ), '--signal is no longer declared here: a stale copy of another package\'s palette invites a re-sync' );
ok( isset( $tok['signal-ink'] ), '--signal-ink is defined' );
$ink = pvc_ratio( $tok['signal-ink'], $tok['void'] );
ok( $ink >= 4.5, sprintf( '--signal-ink %s = %.2f:1 — clears AA as text AND as a surface under --void text', $tok['signal-ink'], $ink ) );
// Scan the DECLARATIONS, not the prose about them: the comment explaining why
// --signal was retired necessarily contains the string `var(--signal)`, and a
// raw-text check reads a note ABOUT a thing as an instance OF it.
$css_nc = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
ok( false === strpos( $css_nc, 'var(--signal)' ), 'no bare --signal survives in any RULE (comments explaining its retirement do not count)' );
foreach ( array(
	'.sn-verify-form button:hover'     => 'the primary button label on hover',
	'.sn-verify-facts a:hover'         => 'a fact link on hover — DARKER than its --blood rest, never lighter',
	'.sn-verify-cmp-form button:hover' => 'the compare button on hover',
) as $sel => $what ) {
	ok( 1 === preg_match( '/' . preg_quote( $sel, '/' ) . '\{[^}]*var\(--signal-ink\)/', $css ), $what );
}

echo "\nGroup: the roadmap board — the fade may not ride the text\n";
$rm = (string) file_get_contents( __DIR__ . '/../assets/maturity-roadmap-front.css' );
ok( '' !== $rm, 'the roadmap stylesheet is readable' );
// Measured live before the fix: opacity on a text-bearing badge dragged the
// counts to 3.29 / 2.13 / 1.76 / 1.45:1 as the status got more speculative.
// Opacity anywhere on this badge would silently reintroduce all of it.
ok( 1 !== preg_match( '/\.sn-maturity-roadmap-badge--[a-z]+\{[^}]*opacity:/', $rm ), 'no badge variant fades itself with opacity (it would fade the label and the count with it)' );
ok( 1 === preg_match( '/\.sn-maturity-roadmap-badge__n\{[^}]*var\(--sn-signal-ink,#b00303\)/', $rm ), 'the badge count uses the ink red, not the outline red' );
ok( 1 === preg_match( '/\.sn-maturity-roadmap-fold summary:hover\{color:var\(--sn-signal-ink,#b00303\)\}/', $rm ), 'the fold summary hover goes darker, not lighter' );
ok( false === strpos( $rm, 'background:var(--sn-signal,#ff4c47);border-color:var(--sn-signal,#ff4c47);color:#fff' ), 'the fold glyph no longer puts white text on the 3.29:1 red' );
// The token itself must SURVIVE where it is legitimate — a sweep that removed
// every occurrence would "pass" while deleting the focus rings.
ok( 2 <= preg_match_all( '/outline:3px solid var\(--sn-signal,#ff4c47\)/', $rm ), '--signal is still the focus-ring colour (non-text, 3:1 — it was never the bug)' );
foreach ( array( 'planned' => 0.55, 'considering' => 0.45, 'later' => 0.45 ) as $variant => $alpha ) {
	$r = pvc_ratio( sprintf( '#%02x%02x%02x', (int) round( 255 * ( 1 - $alpha ) ), (int) round( 255 * ( 1 - $alpha ) ), (int) round( 255 * ( 1 - $alpha ) ) ), '#ffffff' );
	ok( $r >= 3.0, sprintf( 'the %s badge border composites to %.2f:1 — clears 1.4.11', $variant, $r ) );
}

echo "\nGroup: the decorative exemption is an ASSERTION, and it is still made\n";
// These two are exempt ONLY because the markup says they carry no information.
// If that ever changes, the exemption is void and the numeral is 1.32:1.
$php = (string) file_get_contents( __DIR__ . '/../inc/provenance-verify.php' );
ok( 1 !== preg_match( '/class="sn-verify-check-no"(?![^>]*aria-hidden="true")/', $php ), 'every .sn-verify-check-no still carries aria-hidden="true" (its contrast exemption depends on it)' );
ok( 1 === preg_match( '/<span aria-hidden="true">&middot;<\/span>/', $php ), 'the footer separator is still aria-hidden decoration, not read text' );
$decorative = pvc_ratio( $tok['asphalt'], $tok['void'] );
ok( $decorative < 3.0, sprintf( 'documented: the numeral IS %.2f:1 — exempt as decoration, never because it passes', $decorative ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
