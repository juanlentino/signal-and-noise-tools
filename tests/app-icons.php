<?php
/**
 * The three S&N app icons belong to the integration, not the desktop theme.
 *
 * 2026-10-12, the owner: "The apps icons should be part of the integration,
 * not theme." Each app registers its own icon, a plugin asset URL, so it shows
 * on the desktop and on the phone (whose layout ignores theme icon slots), with
 * any desktop theme or none. A URL, not inline markup: OpenStation 1.1.13's dock
 * masks a data: URI to one color (dock.ts `_makeSvgIcon`), which would erase the
 * red mark; an image URL is painted as an <img>, colors intact.
 *
 * The registration pins live in each app's own suite (openstation-app,
 * openstation-host-dashboard, openstation-host-analytics, openstation-preferences);
 * this one pins the files and that every registration names one.
 *
 * Run: php tests/app-icons.php
 */

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "PASS: $m\n";
	} else {
		$fail++;
		echo "FAIL: $m\n";
	}
}

$root = dirname( __DIR__ );

// Where each icon is registered: the three native apps and S&N Home's desktop icon.
$registrations = array(
	'apps/sn-dashboard/sn-dashboard.os.php' => 'sn-home.svg',
	'inc/desktop-mode-dock.php'             => 'sn-home.svg',
	'apps/signal-noise/signal-noise.os.php' => 'signal-noise.svg',
	'apps/sn-analytics/sn-analytics.os.php' => 'sn-analytics.svg',
);
foreach ( $registrations as $file => $svg ) {
	$src = (string) file_get_contents( "$root/$file" );
	// Versioned, so a redraw is not served from a stale cache.
	ok( false !== strpos( $src, "SNT_URL . 'assets/icons/$svg?ver=' . SNT_VERSION" ), "$file registers assets/icons/$svg?ver=<plugin version> as its icon" );
	ok( ! preg_match( "/->icon\(\s*'dashicons-|'icon'\s*=>\s*'dashicons-(shield-alt|megaphone|chart-area)'/", $src ), "$file no longer registers the stock dashicon" );
}

foreach ( array( 'sn-home', 'signal-noise', 'sn-analytics' ) as $name ) {
	$path = "$root/assets/icons/$name.svg";
	$src  = is_file( $path ) ? (string) file_get_contents( $path ) : '';
	$xml  = '' !== $src ? @simplexml_load_string( $src ) : false;
	ok( false !== $xml && 'svg' === $xml->getName() && '' !== (string) $xml['viewBox'], "$name.svg is a well-formed SVG with a viewBox" );
	if ( false === $xml ) {
		continue;
	}
	$xml->registerXPathNamespace( 's', 'http://www.w3.org/2000/svg' );
	$low = strtolower( $src );
	ok( '' !== trim( (string) ( $xml->xpath( '/s:svg/s:title' )[0] ?? '' ) ), "$name.svg has a <title>" );
	ok( false === strpos( $low, '<script' ) && ! preg_match( '/\son[a-z]+\s*=/i', $src ) && ! preg_match( '/href\s*=\s*["\'](?!#)/i', $src ) && false === strpos( $low, 'url(' ), "$name.svg has no script, handler or external reference" );
	// The glyph alone: OpenStation's own tile, plate or glass shows behind it.
	ok( false === strpos( $low, '#3a3a3a' ) && false === strpos( $low, 'fill="#161616"' ), "$name.svg carries no charcoal tile (transparent background)" );
	ok( 1 === substr_count( $low, '#ff4c47' ), "$name.svg keeps exactly one red mark" );
	ok( false !== strpos( $low, '#ffffff' ), "$name.svg keeps its white strokes" );
	// Fixed colors, never currentColor: OpenStation masks currentColor art to
	// one tint, which would erase the red mark. A #161616 underlay keeps the
	// white strokes legible on a light surface (Legacy's #f0f0f1 title bar).
	ok( false === stripos( $src, 'currentColor' ) && false !== strpos( $low, 'stroke="#161616"' ), "$name.svg is fixed-color art with a dark underlay for light surfaces" );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
