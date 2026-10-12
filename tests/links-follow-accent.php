<?php
/**
 * S&N links follow the owner's accent pick (2026-10-12, owner: "the links
 * don't follow my pick in os settings").
 *
 * Every S&N link read `--os-ui-accent-strong`, which OpenStation 1.1.13
 * declares as the literal Nebula #ec9bff (variables.css:546) and the accent
 * picker never rewrites, so the links stayed pink under a red pick.
 * assets/snt-link.css now derives `--snt-link` from `--os-ui-accent`: the
 * pick itself when it already reads as text, else the least lift toward white
 * (or, on the light Legacy theme, darkening toward black) that brings its
 * luminance to a target, computed in linear sRGB.
 *
 * Pins:
 *  1. the sheet derives the token from the accent, gated by @supports, and is
 *     enqueued on the shell;
 *  2. the formula, evaluated here for every OpenStation swatch plus the
 *     owner's #ff0000, holds AA (4.5:1) on every dark surface token and every
 *     Legacy light surface, and keeps the pick's hue (red stays red);
 *  3. every S&N link site reads `--snt-link` first.
 *
 * Run: php tests/links-follow-accent.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$css  = (string) @file_get_contents( $root . '/assets/snt-link.css' );
$code = preg_replace( '#/\*.*?\*/#s', '', $css );

// 1. The sheet and its carrier.
ok( '' !== $css, 'assets/snt-link.css exists' );
ok( 1 === preg_match( '/@supports\s*\(\s*color:\s*color\(\s*from red srgb-linear/', $code ), 'the derivation is gated by @supports on relative color' );
$assets = (string) file_get_contents( $root . '/inc/desktop-mode-assets.php' );
ok( false !== strpos( $assets, "'assets/snt-link.css'" ), 'the sheet is enqueued with the desktop commands, so widget cards get it with no S&N window open' );

// The dark rule lifts toward white: 1 - (1 - c) * min(1, (1 - T) / max(0.001, 1 - Y)).
preg_match( '/body,\s*\.os-shell\s*\{\s*--snt-link:\s*color\(\s*from var\(\s*--os-ui-accent,\s*#[0-9a-f]{6}\s*\)\s*srgb-linear\s+calc\(\s*1 - \(\s*1 - r\s*\)\s*\*\s*min\(\s*1,\s*([0-9.]+)\s*\//', $code, $dark );
// The Legacy rule darkens toward black: c * min(1, T / max(0.001, Y)).
preg_match( '/\.os-shell\[data-os-desktop-theme="desktop-mode-legacy"\]\s*\{\s*--snt-link:\s*color\(\s*from var\(\s*--os-ui-accent,\s*#[0-9a-f]{6}\s*\)\s*srgb-linear\s+calc\(\s*r \* min\(\s*1,\s*([0-9.]+)\s*\//', $code, $light );
ok( isset( $dark[1] ), 'the dark rule on body and the shell derives --snt-link from --os-ui-accent (lift toward white)' );
ok( isset( $light[1] ), 'the light Legacy theme derives it from the accent too (darken toward black)' );
$y_expr = '( 0.2126 * r + 0.7152 * g + 0.0722 * b )';
ok( substr_count( $code, $y_expr ) >= 7, 'every channel reads the WCAG luminance of the pick (the gate, three dark, three light)' );
ok( false === strpos( $code, 'accent-strong' ), 'the derivation never reads the fixed Nebula --os-ui-accent-strong' );

// 2. Evaluate the formula as written.
function lin( $c ) { return $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 ); }
function gam( $c ) { return $c <= 0.0031308 ? 12.92 * $c : 1.055 * pow( $c, 1 / 2.4 ) - 0.055; }
function lrgb( $hex ) { return array_map( function ( $x ) { return lin( hexdec( $x ) / 255 ); }, str_split( ltrim( $hex, '#' ), 2 ) ); }
function y( $v ) { return 0.2126 * $v[0] + 0.7152 * $v[1] + 0.0722 * $v[2]; }
function ratio( $a, $b ) { return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 ); }
function over( $hex, $alpha, $under ) {
	$f = str_split( ltrim( $hex, '#' ), 2 ); $u = str_split( ltrim( $under, '#' ), 2 ); $o = '#';
	for ( $i = 0; $i < 3; $i++ ) { $o .= sprintf( '%02x', round( hexdec( $f[ $i ] ) * $alpha + hexdec( $u[ $i ] ) * ( 1 - $alpha ) ) ); }
	return $o;
}
function link_dark( $hex, $one_minus_t ) {
	$v = lrgb( $hex ); $k = min( 1, $one_minus_t / max( 0.001, 1 - y( $v ) ) );
	return array_map( function ( $c ) use ( $k ) { return 1 - ( 1 - $c ) * $k; }, $v );
}
function link_light( $hex, $t ) {
	$v = lrgb( $hex ); $k = min( 1, $t / max( 0.001, y( $v ) ) );
	return array_map( function ( $c ) use ( $k ) { return $c * $k; }, $v );
}

// OpenStation 1.1.13 includes/accents.php, and the owner's custom pick.
$swatches = array(
	'pulse' => '#f252fc', 'nebula' => '#ec9bff', 'sirius' => '#9af2ff', 'lagoon' => '#9f98ff', 'starlight' => '#fffbff',
	'wp-blue' => '#2271b1', 'indigo' => '#3858e9', 'teal' => '#04a4cc', 'emerald' => '#059669', 'amber' => '#d97706',
	'rose' => '#e11d48', 'owner red' => '#ff0000', 'black (custom)' => '#000000',
);
// Dark: sunken, window, raised (6% Starlight over the window), elevated, and
// a widget card (rgba(20,20,22,0.55)) over a mid-gray wallpaper.
$dark_bgs = array( '#0c0b0f', '#1a1721', over( '#fffbff', 0.06, '#1a1721' ), '#33303a', over( '#141416', 0.55, '#3a3a3a' ) );
// Legacy: window, elevated, sunken.
$light_bgs = array( '#ffffff', '#f6f7f7', '#eef0f1' );

if ( isset( $dark[1], $light[1] ) ) {
	$worst = array( 99, '' );
	foreach ( $swatches as $name => $hex ) {
		$y = y( link_dark( $hex, (float) $dark[1] ) );
		foreach ( $dark_bgs as $bg ) { $r = ratio( $y, y( lrgb( $bg ) ) ); if ( $r < $worst[0] ) { $worst = array( $r, "$name on $bg" ); } }
	}
	ok( $worst[0] >= 4.5, sprintf( 'every swatch holds AA as link text on every dark surface (worst %.2f:1, %s)', $worst[0], $worst[1] ) );
	$worst = array( 99, '' );
	foreach ( $swatches as $name => $hex ) {
		$y = y( link_light( $hex, (float) $light[1] ) );
		foreach ( $light_bgs as $bg ) { $r = ratio( $y, y( lrgb( $bg ) ) ); if ( $r < $worst[0] ) { $worst = array( $r, "$name on $bg" ); } }
	}
	ok( $worst[0] >= 4.5, sprintf( 'every swatch holds AA as link text on every Legacy light surface (worst %.2f:1, %s)', $worst[0], $worst[1] ) );

	$red = array_map( 'gam', link_dark( '#ff0000', (float) $dark[1] ) );
	ok( $red[0] > 0.99 && $red[1] < 0.5 && abs( $red[1] - $red[2] ) < 0.01, sprintf( 'the owner\'s red stays red: #%02x%02x%02x, not the Nebula pink', round( $red[0] * 255 ), round( $red[1] * 255 ), round( $red[2] * 255 ) ) );
	$nebula = array_map( 'gam', link_dark( '#ec9bff', (float) $dark[1] ) );
	ok( abs( $nebula[0] - 0xec / 255 ) < 0.002 && abs( $nebula[2] - 1 ) < 0.002, 'a pick that already reads as text is left exactly as picked (Nebula)' );
	$blue = array_map( 'gam', link_dark( '#2271b1', (float) $dark[1] ) );
	ok( $blue[2] > $blue[1] && $blue[1] > $blue[0], 'a dark pick is lifted, not replaced: WordPress Blue stays blue' );
}

// 3. Link sites.
$os_app = (string) file_get_contents( $root . '/assets/os-app.css' );
ok( 1 === preg_match( '/\n\.snt-link \{\s*color: var\( --snt-link,/', $os_app ), 'a prose link (.snt-link) reads --snt-link first' );
ok( 1 === preg_match( '/\.snt-app \.snt-leaf a:not\( \[class\] \) \{\s*color: var\( --snt-link,/', $os_app ), 'a bare builder link reads --snt-link first' );
ok( 1 === preg_match( '/\.snt-app os-button\[variant="link"\] \{\s*--os-ui-button-fg: var\( --snt-link,/', $os_app ), 'door and go links read --snt-link first' );
$dash = (string) file_get_contents( $root . '/apps/sn-dashboard/sn-dashboard.css' );
foreach ( array( '.snt-home__view-all {', '.snt-home__attention-action {', '.snt-ns-hero__weeks li:last-child strong {' ) as $sel ) {
	$at   = strpos( $dash, $sel );
	$rule = false !== $at ? substr( $dash, $at, (int) strpos( $dash, '}', $at ) - $at ) : '';
	ok( false !== strpos( $rule, 'color: var( --snt-link,' ), "S&N Home reads --snt-link first: $sel" );
}
$an = (string) file_get_contents( $root . '/apps/sn-analytics/sn-analytics.css' );
ok( 1 === preg_match( '/\.snt-app\[data-os-app="sn-analytics"\] :is\(a, \.button-link\) \{\s*color: var\(--snt-link,/', $an ), 'S&N Analytics links read --snt-link first (they painted the raw accent, 4.4:1 for red)' );
foreach ( glob( $root . '/assets/desktop-mode-widget*.js' ) as $path ) {
	$js = (string) file_get_contents( $path );
	preg_match_all( '/color:([^;]*);text-decoration:none/', $js, $m );
	foreach ( $m[1] as $color ) {
		if ( false !== strpos( $color, 'accent' ) ) {
			ok( 0 === strpos( $color, 'var(--snt-link, ' ), basename( $path ) . ' card link reads --snt-link first' );
		}
	}
}
$all = $os_app . $dash . $an;
foreach ( glob( $root . '/assets/desktop-mode-widget*.js' ) as $path ) { $all .= file_get_contents( $path ); }
ok( 0 === preg_match( '/(color|--os-ui-button-fg):\s*var\(\s*--os-ui-accent-strong/', $all ), 'no S&N link leads with the fixed Nebula --os-ui-accent-strong' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
