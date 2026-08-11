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
foreach ( array( 'void', 'bone', 'asphalt', 'concrete', 'concrete-ink', 'rust', 'blood' ) as $name ) {
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
