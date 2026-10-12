<?php
/**
 * Focus rings are visible, and a primary button's label reads on its fill.
 *
 * 2026-10-12 audit. The shell's --os-ui-focus-ring is a whole box-shadow
 * list, so `0 0 0 2px var( --os-ui-focus-ring )` is invalid, computes to none,
 * and beside `outline: none` left a focused control with no ring. And the
 * accent picker keeps a primary label white until the accent is very light:
 * white on this site's red is 3.9:1. The ink now follows the fill's OKLCH
 * lightness; the threshold is read from the stylesheet and checked against
 * the WordPress admin schemes, this site's red and the kit's Pulse.
 *
 * Run: php tests/focus-ring-and-accent-ink.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$bad  = array();
foreach ( array_merge( glob( $root . '/assets/*.css' ), glob( $root . '/apps/*/*.css' ) ) as $file ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file ) );
	if ( preg_match( '/box-shadow:\s*[^;]*\d+px\s+var\(\s*--os-ui-focus-ring/', $css ) ) {
		$bad[] = basename( $file );
	}
}
ok( array() === $bad, 'no stylesheet puts the focus-ring token inside a box-shadow of its own (invalid, so no ring): ' . implode( ', ', $bad ) );

$app = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/assets/os-app.css' ) );
ok( 1 === preg_match( '/\.snt-app \.snt-submit:focus-visible \{[^}]*box-shadow: var\( --os-ui-focus-ring,/', $app ), 'the native submit takes the shell ring itself' );
ok( 1 === preg_match( '/\.snt-app select:focus \{[^}]*box-shadow: var\( --os-ui-focus-ring-field,/', $app ), 'native fields take the shell\'s field ring' );

$found = preg_match( '/@supports \( color: oklch\( from red l c h \) \) \{\s*\.snt-app\[data-os-app\] \{\s*--os-ui-accent-ink: oklch\( from var\( --wp-admin-theme-color[^;]*clamp\( 0, \( ([0-9.]+) - l \) \* 1000, 1 \) 0 0 \);/', $app, $m );
ok( 1 === $found, 'the accent ink is black or white from the fill\'s own lightness, behind a relative-color @supports' );
$threshold = $found ? (float) $m[1] : 0.0;

function lin( $c ) { $c /= 255; return $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4; }
function rgb( $hex ) { return array_map( static fn( $p ) => lin( hexdec( $p ) ), str_split( ltrim( $hex, '#' ), 2 ) ); }
function lum( $hex ) { list( $r, $g, $b ) = rgb( $hex ); return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b; }
function oklch_l( $hex ) {
	list( $r, $g, $b ) = rgb( $hex );
	$l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
	$m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
	$s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
	return 0.2104542553 * ( $l ** ( 1 / 3 ) ) + 0.7936177850 * ( $m ** ( 1 / 3 ) ) - 0.0040720468 * ( $s ** ( 1 / 3 ) );
}
function ratio( $a, $b ) { $x = lum( $a ); $y = lum( $b ); return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ); }

// This site's red, the kit's Pulse, and the WordPress admin color schemes' primary buttons.
foreach ( array( '#ff0000', '#f252fc', '#2271b1', '#04a4cc', '#096484', '#e1a948', '#a3b745', '#dd823b', '#e14d43', '#c7a589', '#9ebaa0', '#3858e9' ) as $fill ) {
	$ink    = oklch_l( $fill ) < $threshold ? '#ffffff' : '#000000';
	$other  = '#ffffff' === $ink ? '#000000' : '#ffffff';
	$picked = ratio( $ink, $fill );
	ok( $picked >= ratio( $other, $fill ) - 0.25, sprintf( '%s takes %s ink at %.2f:1 (the other ink: %.2f:1)', $fill, '#ffffff' === $ink ? 'white' : 'black', $picked, ratio( $other, $fill ) ) );
}
ok( ratio( '#000000', '#ff0000' ) >= 4.5 && oklch_l( '#ff0000' ) >= $threshold, 'this site\'s red takes black ink, which passes AA (5.25:1); white was 4.0:1' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
