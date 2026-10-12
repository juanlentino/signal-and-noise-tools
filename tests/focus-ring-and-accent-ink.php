<?php
/**
 * Focus rings are visible, and a primary button's label reads on its fill.
 *
 * 2026-10-12 audit. The shell's --os-ui-focus-ring is a whole box-shadow
 * list, so `0 0 0 2px var( --os-ui-focus-ring )` is invalid, computes to none,
 * and beside `outline: none` left a focused control with no ring. And the
 * accent picker keeps a primary label white until the accent is very light:
 * white on red is 3.9:1. The ink is now black or white at the exact WCAG
 * crossover, computed from the fill; the rule is read from the stylesheet,
 * evaluated as CSS evaluates it, and checked against OpenStation's swatches,
 * the WordPress admin schemes, and fills right at the threshold.
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
foreach ( array_merge( glob( $root . '/assets/*.css' ), glob( $root . '/assets/*/*.css' ), glob( $root . '/apps/*/*.css' ) ) as $file ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file ) );
	if ( preg_match( '/box-shadow:\s*[^;]*\d+px\s+var\(\s*--os-ui-focus-ring/', $css ) ) {
		$bad[] = basename( $file );
	}
}
ok( array() === $bad, 'no stylesheet puts the focus-ring token inside a box-shadow of its own (invalid, so no ring): ' . implode( ', ', $bad ) );

$app = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/assets/os-app.css' ) );
ok( 1 === preg_match( '/\.snt-app \.snt-submit:focus-visible \{[^}]*box-shadow: var\( --os-ui-focus-ring,/', $app ), 'the native submit takes the shell ring itself' );
ok( 1 === preg_match( '/\.snt-app select:focus \{[^}]*box-shadow: var\( --os-ui-focus-ring-field,/', $app ), 'native fields take the shell\'s field ring' );

// The ink rule, read from the sheet and evaluated as CSS evaluates it: V =
// clamp( 0, ( T - Y ) * M, 1 ) in linear sRGB, the ink is gray V (white at 1).
$found = preg_match( '/@supports \( color: color\( from red srgb-linear .*?\) \) \{\s*\.snt-app\[data-os-app\] \{\s*--os-ui-accent-ink: color\( from var\( --wp-admin-theme-color[^;]*clamp\( 0, \( ([0-9.]+) - \( 0\.2126 \* r \+ 0\.7152 \* g \+ 0\.0722 \* b \) \) \* ([0-9]+), 1 \)/s', $app, $m );
ok( 1 === $found, 'the accent ink is computed from the fill in linear sRGB, gated on the same expression' );
$threshold  = $found ? (float) $m[1] : 0.0;
$multiplier = $found ? (float) $m[2] : 0.0;
// PR #2015 re-review: the listed fills alone let 0.19, 0.20 and a 1e7
// multiplier through (232,220 to 578,420 of 16.7M fills wrong). Pin them:
// black and white tie at luminance 0.17913, and below 1e8 a gray band opens.
ok( abs( $threshold - 0.17913 ) <= 5e-5, sprintf( 'the ink switches at the black/white tie, luminance 0.17913 (rule: %s)', $threshold ) );
ok( $multiplier >= 1e8, sprintf( 'the step is hard enough that no 8-bit fill gets a gray ink (multiplier %.0e, needs 1e8)', $multiplier ) );
$g0   = strpos( $app, '@supports ( color: ' );
$gate = false !== $g0 ? substr( $app, $g0 + 19, (int) strpos( $app, ' ) {', $g0 ) - $g0 - 19 ) : '';
$r0   = strpos( $app, '--os-ui-accent-ink: color( from var( --wp-admin-theme-color, var( --os-ui-accent, #2271b1 ) ) ' );
$rule = false !== $r0 ? substr( $app, $r0 + 94, (int) strpos( $app, ' );', $r0 ) - $r0 - 94 ) : '';
ok( '' !== $rule && 'color( from red ' . $rule . ' )' === $gate, 'the @supports gate tests the rule\'s own expression' );

function lin( $c ) { $c /= 255; return $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4; }
function lum( $hex ) { list( $r, $g, $b ) = array_map( static fn( $p ) => lin( hexdec( $p ) ), str_split( ltrim( $hex, '#' ), 2 ) ); return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b; }
function ratio_y( $x, $y ) { return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ); }
$ink_y = static fn( $y ) => max( 0.0, min( 1.0, ( $threshold - $y ) * $multiplier ) ); // gray V has luminance V

// AA where either ink can reach it; otherwise the better of the two.
$check = static function ( $label, $y ) use ( $ink_y ) {
	$picked = ratio_y( $ink_y( $y ), $y );
	$best   = max( ratio_y( 0.0, $y ), ratio_y( 1.0, $y ) );
	ok( $best >= 4.5 ? $picked >= 4.5 : $picked >= $best - 1e-9, sprintf( '%s: ink at %.2f:1 (best possible %.2f:1)', $label, $picked, $best ) );
};
// OpenStation's shipped swatches (includes/accents.php), red, and the WordPress admin schemes.
$fills = array(
	'pulse' => '#f252fc', 'nebula' => '#ec9bff', 'sirius' => '#9af2ff', 'lagoon' => '#9f98ff', 'starlight' => '#fffbff',
	'wp-blue' => '#2271b1', 'indigo' => '#3858e9', 'teal' => '#04a4cc', 'emerald' => '#059669', 'amber' => '#d97706', 'rose' => '#e11d48',
	'red' => '#ff0000', 'wp-red' => '#d63638', 'violet' => '#a500fa', 'green' => '#0a9105', 'wp-modern' => '#3858e9', 'wp-ocean' => '#9ebaa0', 'wp-sunrise' => '#dd823b', 'wp-coffee' => '#c7a589', 'wp-midnight' => '#e14d43', 'wp-ectoplasm' => '#a3b745', 'wp-light' => '#04a4cc', 'wp-blue-scheme' => '#096484',
);
foreach ( $fills as $name => $hex ) {
	$check( "$name $hex", lum( $hex ) );
}
// A fill right at the crossover: the step must stay hard, never a gray ink.
foreach ( array( -5e-4, -5e-5, -5e-6, -5e-7, 5e-7, 5e-6, 5e-5, 5e-4 ) as $d ) {
	$check( sprintf( 'a fill at luminance 0.1791%+.0e', $d ), 0.1791 + $d );
}
ok( lum( '#ff0000' ) > $threshold && ratio_y( 0.0, lum( '#ff0000' ) ) >= 4.5, 'red takes black ink, which passes AA (5.25:1); the picker\'s white was 4.0:1' );
ok( lum( '#e11d48' ) < $threshold && ratio_y( 1.0, lum( '#e11d48' ) ) >= 4.5, 'Rose takes white ink (4.70:1): the review\'s case, black there was 4.47:1' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
