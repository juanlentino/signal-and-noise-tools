<?php
/**
 * Tests: the OpenStation PWA manifest icon override (issue #1017).
 *
 * The bug this replaces was not a missing icon — it was a manifest that
 * DESCRIBED its icons wrongly. So the assertions below read the PNG headers and
 * compare them against what the manifest claims, rather than trusting either
 * side on its own. A test that only checked "four entries exist" would have
 * passed against the broken manifest too.
 *
 * Run: php tests/openstation-pwa-icons.php
 * @since 13.96.4
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );

$GLOBALS['snt_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['snt_filters'][ $hook ][] = $cb; }
}
// Drives the admin/non-admin branch of the apple-touch-icon seam.
$GLOBALS['snt_is_admin'] = true;
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { return (bool) $GLOBALS['snt_is_admin']; }
}

require_once __DIR__ . '/../inc/openstation-pwa-icons.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * Read a PNG's real width, height and colour type from its IHDR.
 *
 * Deliberately not getimagesize(): this must also say whether the file carries
 * an alpha channel, which is the property that turned the home-screen tile
 * black and which a size check cannot see.
 *
 * @param string $path
 * @return array{w:int,h:int,ctype:int}|null
 */
function snt_pwa_png_header( $path ) {
	$fh = @fopen( $path, 'rb' );
	if ( ! $fh ) {
		return null;
	}
	$head = (string) fread( $fh, 26 );
	fclose( $fh );
	if ( 26 !== strlen( $head ) || "\x89PNG\r\n\x1a\n" !== substr( $head, 0, 8 ) ) {
		return null;
	}
	$ihdr = unpack( 'Nw/Nh/Cdepth/Cctype', substr( $head, 16, 10 ) );

	return array( 'w' => (int) $ihdr['w'], 'h' => (int) $ihdr['h'], 'ctype' => (int) $ihdr['ctype'] );
}

echo "openstation-pwa-icons — plugin v13.96.4\n\nGroup 1: the filter is actually attached\n";
ok( isset( $GLOBALS['snt_filters']['openstation_pwa_manifest'] ), 'registers on openstation_pwa_manifest (the name is documented Stable upstream — an invented hook is silent)' );
ok( in_array( 'snt_openstation_pwa_manifest_icons', (array) ( $GLOBALS['snt_filters']['openstation_pwa_manifest'] ?? array() ), true ), 'the callback attached is ours' );

echo "\nGroup 2: it replaces icons and nothing else\n";
$before = array(
	'name'             => 'Upstream Name',
	'theme_color'      => '#0c0b0f',
	'background_color' => '#0c0b0f',
	'scope'            => '/wp-admin/',
	'icons'            => array( array( 'src' => 'https://example.test/site-icon-300x300.png', 'sizes' => '192x192', 'purpose' => 'any' ) ),
);
$after = snt_openstation_pwa_manifest_icons( $before );
foreach ( array( 'name', 'theme_color', 'background_color', 'scope' ) as $key ) {
	ok( $before[ $key ] === $after[ $key ], "leaves $key to OpenStation — a filter that rewrites more than it fixes reverts upstream improvements silently" );
}
ok( $before['icons'] !== $after['icons'], 'the icon array IS replaced' );
ok( array() === array_filter( $after['icons'], static function ( $i ) { return false !== strpos( (string) $i['src'], 'site-icon' ); } ), 'no Site-Icon-derived entry survives' );

// A non-array payload must pass through untouched rather than fatal.
ok( null === snt_openstation_pwa_manifest_icons( null ), 'a non-array manifest passes through' );

echo "\nGroup 3: every declared size matches the REAL file\n";
$icons = snt_openstation_pwa_icons();
ok( 4 === count( $icons ), 'four entries: 192 and 512, each as any and maskable' );

$purposes = array();
foreach ( $icons as $icon ) {
	$purposes[ (string) $icon['purpose'] ][] = (string) $icon['sizes'];
}
ok( array( '192x192', '512x512' ) === ( $purposes['any'] ?? array() ), 'purpose=any covers 192 and 512' );
ok( array( '192x192', '512x512' ) === ( $purposes['maskable'] ?? array() ), 'purpose=maskable covers 192 and 512 — without it Android draws its own backdrop behind a shrunken tile' );

$checked = 0;
foreach ( $icons as $icon ) {
	$name = basename( (string) $icon['src'] );
	$path = dirname( __DIR__ ) . '/assets/pwa/' . $name;
	ok( is_file( $path ), "$name is shipped" );
	$hdr = snt_pwa_png_header( $path );
	if ( ! is_array( $hdr ) ) {
		ok( false, "$name is a readable PNG" );
		continue;
	}
	++$checked;

	// THE pin. The live manifest declared 192x192 on a 300x300 file.
	$declared = (string) $icon['sizes'];
	ok(
		sprintf( '%dx%d', $hdr['w'], $hdr['h'] ) === $declared,
		sprintf( '%s: manifest says %s and the file IS %dx%d', $name, $declared, $hdr['w'], $hdr['h'] )
	);

	// Colour type 2 is RGB with no alpha. 4 and 6 carry it; iOS composites
	// transparency to black, and this mark measures luminance 23/255.
	ok(
		2 === $hdr['ctype'],
		sprintf( '%s has no alpha channel (PNG colour type %d; 4 and 6 render black on an iOS home screen)', $name, $hdr['ctype'] )
	);

	ok( $hdr['w'] === $hdr['h'], "$name is square" );
}
ok( 4 === $checked, "VACUITY: all four headers were actually read (read $checked) — a scan that opened nothing reports the same clean bill as one that found no faults" );

echo "\nGroup 4: the iOS tile comes from apple-touch-icon, not the manifest\n";
// #1017 replaced the MANIFEST icons, which is Android's path, and the iPhone
// tile stayed black. iOS reads <link rel="apple-touch-icon">, which OpenStation
// prints into admin_head from get_site_icon_url( 180 ). That function has no
// filter of its own, so the seam is core's.
ok( isset( $GLOBALS['snt_filters']['get_site_icon_url'] ), 'registers on core get_site_icon_url - openstation_pwa_apple_touch_icon_url() has no filter to hook' );

$site_url = 'https://example.test/wp-content/uploads/cropped-logo-300x300.png';
$GLOBALS['snt_is_admin'] = true;
ok(
	SNT_URL . 'assets/pwa/icon-180.png' === snt_openstation_apple_touch_icon_url( $site_url, 180 ),
	'admin + size 180 -> our opaque tile'
);
foreach ( array( 32, 180.0, 192, 512 ) as $other ) {
	if ( 180 === (int) $other ) {
		continue;
	}
	ok( $site_url === snt_openstation_apple_touch_icon_url( $site_url, $other ), "size $other is left alone" );
}
$GLOBALS['snt_is_admin'] = false;
ok( $site_url === snt_openstation_apple_touch_icon_url( $site_url, 180 ), 'front-end is left alone - the browser-tab favicon keeps its transparency' );
$GLOBALS['snt_is_admin'] = true;

$tile = dirname( __DIR__ ) . '/assets/pwa/icon-180.png';
ok( is_file( $tile ), 'assets/pwa/icon-180.png is shipped' );
$th = snt_pwa_png_header( $tile );
ok( is_array( $th ) && 180 === $th['w'] && 180 === $th['h'], 'the tile really is 180x180' );
ok( is_array( $th ) && 2 === $th['ctype'], 'the tile has NO alpha channel - the black square was iOS compositing transparency (colour type ' . ( is_array( $th ) ? $th['ctype'] : -1 ) . ')' );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
