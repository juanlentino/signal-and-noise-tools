<?php
/**
 * Tests: the "Signal through noise" OpenStation desktop theme
 * (inc/openstation-desktop-theme.php).
 *
 * The theme is a LIGHT personalization, and the owner's warning was that
 * "some things are OpenStation settings or components". So most of what is
 * pinned here is what the manifest must NOT touch: every token outside the
 * brand constants and the title font, every accent-owned or accent-derived
 * token, the toolbar, and the recommendations block that would write into
 * the owner's settings.
 *
 * THE 22 BRAND CONSTANTS are measured from OpenStation v1.1.13 (the version
 * the live site runs), `assets/css/variables.css`, base block
 * `body.os-active`: every token whose value is a gradient or whose name is
 * mesh, holo, crown or sheen, less `--os-bg` (the wallpaper owns it) and less
 * the six the accent derives (`--os-ui-holo-glow`, `-glow-strong`,
 * `--os-ui-tab-edge`, `-wash`, `-bloom`, `--os-tabs-rail`), plus the three
 * `var(--os-mesh-holo)` aliases that paint fills. Read with
 * `git show v1.1.13:assets/css/variables.css`; the line and the value
 * (shortened) are recorded below because this repo cannot read OpenStation
 * at test time. When upstream adds a constant, add it here, and the
 * "every constant is answered" check fails until the theme maps it.
 *
 * Run: php tests/openstation-desktop-theme.php
 *
 * @since 2026.10.5
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );

$GLOBALS['snt_dt_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['snt_dt_actions'][] = array( $hook, $cb, $prio );
	}
}

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

$root   = dirname( __DIR__ );
$module = $root . '/inc/openstation-desktop-theme.php';
ok( is_file( $module ), 'inc/openstation-desktop-theme.php exists' );
if ( ! is_file( $module ) ) {
	echo "\n$pass passed, $fail failed\n";
	exit( 1 );
}
require_once $module;

// ── The 22 constants at v1.1.13: name => line: value (shortened). ────────
$constants = array(
	'--os-mesh-holo'                => '613: radial-gradient(ellipse 21.7% 39.6% at 96.5% 13.5%, rgba(159, 214, 255, 0.6) 0%',
	'--os-mesh-pulse'               => '628: radial-gradient(ellipse 39.1% 48.6% at 92% 94.5%, rgba(255, 253, 255, 0.95) 0%',
	'--os-mesh-auro'                => '638: linear-gradient(80deg, #d1c6f8 9.5%, #cefada 50%, #b8ffff 90.5%)',
	'--os-mesh-star'                => '641: radial-gradient(ellipse 72.7% 72.7% at 50% 12.5%, #fffbff 0%, #fffbff 67.8%, #ccc8ce 100%)',
	'--os-mesh-mio'                 => '645: linear-gradient(90deg, #f252fc 7%, #aa67ff 48.3%, #a580ff 70.7%, #4b3eff 93%)',
	'--os-ui-holo-fill'             => '664: var(--os-mesh-holo)',
	'--os-ui-hero-mesh'             => '698: radial-gradient(ellipse 62% 38% at 34% 20%, rgba(170, 103, 255, 0.58) 0%',
	'--os-ui-holo-ink'              => '711: #0c0b0f',
	'--os-ui-holo-sheen'            => '721: linear-gradient( 124deg, rgba(159, 214, 255, 0.1) 0%, rgba(236, 155, 255, 0.12) 34%',
	'--os-ui-holo-edge'             => '733: linear-gradient( 124deg, rgba(154, 242, 255, 0.7) 0%, rgba(236, 155, 255, 0.85) 38%',
	'--os-ui-holo-edge-quiet'       => '741: linear-gradient( 124deg, rgba(154, 242, 255, 0.22) 0%, rgba(236, 155, 255, 0.28) 38%',
	'--os-ui-holo-track'            => '778: rgba(255, 251, 255, 0.16)',
	'--os-ui-holo-track-edge'       => '786: #66636b',
	'--os-ui-segmented-hover-sheen' => '891: none',
	'--os-ui-step-chip-bg'          => '1095: var(--os-mesh-holo)',
	'--os-ui-ribbon-bg'             => '1103: var(--os-mesh-holo)',
	'--os-ui-progress-fill'         => '1151: var(--os-mesh-holo)',
	'--os-ui-rating-fill'           => '1153: linear-gradient(90deg, #f8f2b6 0%, #fffbff 100%)',
	'--os-dock-badge-bg'            => '1365: linear-gradient(180deg, #f97dff 0%, #f252fc 100%)',
	'--os-icon-badge-bg'            => '1367: linear-gradient(180deg, #f97dff 0%, #f252fc 100%)',
	'--os-tabs-active-frost'        => '1762: linear-gradient( 180deg, #f4eff9 0%, #ffffff 58% )',
	'--os-tabs-active-crown'        => '1774: none',
);
ok( 22 === count( $constants ), 'the fixture lists the 22 brand constants measured at v1.1.13' );

/** The value half of a fixture entry. */
function snt_dt_fixture_value( $entry ) {
	return trim( substr( $entry, strpos( $entry, ':' ) + 1 ) );
}

/**
 * Every colour a value paints that is NOT the theme's: anything but a grey
 * (r = g = b, any alpha) or the one red, #ff4c47. A `var()` alias counts as
 * foreign, because it paints whatever the brand points it at.
 *
 * @param string $value CSS value.
 * @return string[] The offending literals; empty when the value is ours.
 */
function snt_dt_foreign_colors( $value ) {
	$bad = array();
	$v   = strtolower( $value );
	if ( false !== strpos( $v, 'var(' ) ) {
		$bad[] = 'var() alias';
	}
	preg_match_all( '/#([0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})\b/', $v, $hex );
	foreach ( $hex[1] as $h ) {
		if ( strlen( $h ) <= 4 ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		$rgb = array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
		if ( 'ff4c47' !== substr( $h, 0, 6 ) && ! ( $rgb[0] === $rgb[1] && $rgb[1] === $rgb[2] ) ) {
			$bad[] = '#' . $h;
		}
	}
	preg_match_all( '/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $v, $fn, PREG_SET_ORDER );
	foreach ( $fn as $m ) {
		$is_red = '255' === $m[1] && '76' === $m[2] && '71' === $m[3];
		if ( ! $is_red && ! ( $m[1] === $m[2] && $m[2] === $m[3] ) ) {
			$bad[] = $m[0] . ')';
		}
	}
	// No colour by keyword: every word must be grammar, never a name.
	$grammar = array( 'linear', 'radial', 'gradient', 'calc', 'rgb', 'rgba', 'deg', 'px', 'ellipse', 'circle', 'at', 'to', 'transparent', 'none' );
	preg_match_all( '/[a-z]+/', preg_replace( '/#[0-9a-f]+/', '', $v ), $words );
	foreach ( array_unique( $words[0] ) as $w ) {
		if ( ! in_array( $w, $grammar, true ) ) {
			$bad[] = 'keyword ' . $w;
		}
	}
	return $bad;
}

// A guard that cannot fail is worse than none: the detector must flag every
// non-`none` upstream value, the purple, the pinks and the tinted greys alike.
$blind = array();
foreach ( $constants as $name => $entry ) {
	$upstream = snt_dt_fixture_value( $entry );
	if ( 'none' !== $upstream && array() === snt_dt_foreign_colors( $upstream ) ) {
		$blind[] = $name;
	}
}
ok( array() === $blind, 'the colour check flags every upstream value it replaces' . ( $blind ? ' (blind to: ' . implode( ', ', $blind ) . ')' : '' ) );

// ── Shape and registration ──────────────────────────────────────────────
ok( function_exists( 'snt_desktop_theme_manifest' ), 'snt_desktop_theme_manifest() is defined' );
ok( function_exists( 'snt_desktop_theme_register' ), 'snt_desktop_theme_register() is defined' );
$theme  = function_exists( 'snt_desktop_theme_manifest' ) ? snt_desktop_theme_manifest() : array();
$id     = isset( $theme['id'] ) ? (string) $theme['id'] : '';
$args   = isset( $theme['args'] ) && is_array( $theme['args'] ) ? $theme['args'] : array();
$tokens = isset( $args['tokens'] ) && is_array( $args['tokens'] ) ? $args['tokens'] : array();

ok( 1 === preg_match( '#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#', $id ) && strlen( $id ) <= 64, 'the id passes upstream\'s fatal id grammar' );
// Upstream flattens with sanitize_key( str_replace( '/', '-', $id ) ); activation is keyed by that.
$flat = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( str_replace( '/', '-', $id ) ) );
ok( 'sn-signal-through-noise' === $flat, 'the id flattens to the activation slug sn-signal-through-noise (got ' . $flat . ')' );
ok( 'Signal through noise' === ( $args['name'] ?? '' ), 'the theme is named "Signal through noise"' );
ok( ! array_key_exists( 'recommendedOsSettings', $args ), 'no recommendedOsSettings: the owner\'s layout, accent and wallpaper are not this file\'s to recommend' );
ok( ! array_key_exists( 'iconColor', $args ), 'no iconColor: a tint would mask the icons and erase their red mark' );
ok( ! array_key_exists( 'textures', $args ), 'no textures: component art stays upstream\'s' );

$hooked = array();
foreach ( $GLOBALS['snt_dt_actions'] as $a ) {
	if ( 'init' === $a[0] && 'snt_desktop_theme_register' === $a[1] ) {
		$hooked[] = (int) $a[2];
	}
}
ok( 1 === count( $hooked ) && $hooked[0] >= 10, 'registers on init at priority 10 or later, after OpenStation\'s Legacy (5)' );

$text = (string) file_get_contents( $module );
ok( false !== strpos( $text, "function_exists( 'openstation_register_desktop_theme' )" ), 'registration is guarded with function_exists' );
ok( false !== strpos( (string) file_get_contents( $root . '/signal-and-noise-tools.php' ), "inc/openstation-desktop-theme.php'" ), 'the main plugin file requires the module' );

// Guarded: with OpenStation absent the call is a no-op, not a fatal.
ok( false === snt_desktop_theme_register(), 'with OpenStation absent, registration does nothing' );
// Declared inside a block so they exist only from here on: a top-level
// function is hoisted, and would make the "absent" check above a lie.
$GLOBALS['snt_dt_calls'] = array();
$GLOBALS['snt_dt_gate']  = false;
if ( ! function_exists( 'openstation_register_desktop_theme' ) ) {
	function openstation_register_desktop_theme( $id, $args = array() ) {
		$GLOBALS['snt_dt_calls'][] = array( $id, $args );
		return true;
	}
	function openstation_request_needs_admin_modules() {
		return $GLOBALS['snt_dt_gate'];
	}
}
snt_desktop_theme_register();
ok( array() === $GLOBALS['snt_dt_calls'], 'on a request OpenStation would not paint (the front end), nothing registers' );
$GLOBALS['snt_dt_gate'] = true;
ok( true === snt_desktop_theme_register(), 'with OpenStation present, registration returns its result' );
ok( 1 === count( $GLOBALS['snt_dt_calls'] ) && $id === $GLOBALS['snt_dt_calls'][0][0], 'registers once, under the manifest id' );

// ── Tokens: the allowlist ───────────────────────────────────────────────
$allowed = array_merge( array_keys( $constants ), array( '--os-titlebar-font' ) );
$extra   = array_values( array_diff( array_keys( $tokens ), $allowed ) );
ok( array() === $extra, 'every token is a brand constant or the title font' . ( $extra ? ' (also: ' . implode( ', ', $extra ) . ')' : '' ) );

$unanswered = array();
foreach ( $constants as $name => $entry ) {
	if ( ! isset( $tokens[ $name ] ) && 'none' !== snt_dt_fixture_value( $entry ) ) {
		$unanswered[] = $name;
	}
}
ok( array() === $unanswered, 'every constant that paints at v1.1.13 is answered; only the two that are none are left' . ( $unanswered ? ' (left purple: ' . implode( ', ', $unanswered ) . ')' : '' ) );
ok( ! isset( $tokens['--os-tabs-active-crown'] ), '--os-tabs-active-crown is none at v1.1.13 and stays unset' );

$foreign = array();
foreach ( $tokens as $name => $value ) {
	if ( '--os-titlebar-font' === $name ) {
		continue;
	}
	$bad = snt_dt_foreign_colors( (string) $value );
	if ( $bad ) {
		$foreign[] = $name . ' [' . implode( ' ', $bad ) . ']';
	}
}
ok( array() === $foreign, 'every colour in the theme is a grey or #ff4c47: nothing is left purple' . ( $foreign ? ' (' . implode( '; ', $foreign ) . ')' : '' ) );

// ── Tokens: the deny list (the owner's "settings or components") ───────
$deny = array(
	// Written inline by a setting, so a theme value loses or breaks it.
	'--wp-admin-theme-color', '--os-ui-accent', '--os-ui-accent-ink', '--os-ui-accent-dim', '--os-bg',
	'--os-window-radius', '--os-dock-width', '--os-dock-icon-size', '--os-admin-bar-height',
	'--os-work-area-inset-top', '--os-work-area-inset-right', '--os-work-area-inset-bottom', '--os-work-area-inset-left',
	'--os-work-area-width', '--os-work-area-height',
	// Derived from the accent (or a radius or dock-size setting) in the base block.
	'--os-mio-chat-border', '--os-mio-chat-user-bg', '--os-mio-chat-glow', '--os-mio-callout-border',
	'--os-titlebar-activity-color', '--os-titlebar-activity-saved-color', '--os-ui-accent-soft',
	'--os-ui-holo-glow', '--os-ui-holo-glow-strong', '--os-ui-focus-ring', '--os-ui-focus-ring-field',
	'--os-ui-tab-edge', '--os-ui-tab-wash', '--os-ui-tab-bloom', '--os-ui-color-accent', '--os-dock-indicator-bg',
	'--os-dock-item-outline', '--os-dock-divider', '--os-cn-beam', '--os-my-wordpress-card-border', '--os-tabs-rail',
	'--os-tile-focus-ring', '--os-window-link-color', '--os-window-link-color-active', '--os-window-link-accent',
	'--os-mio-chat-radius', '--os-window-corner-inset', '--os-dock-badge-size', '--os-dock-recycle-badge-size',
	// Undeclared, and follow the accent through a consumer's fallback.
	'--os-cn-head-icon-shadow', '--os-dock-active-indicator', '--os-dock-exit-ring-hover', '--os-dock-separator-bg',
	'--os-dock-separator-shadow', '--os-selection-marquee-bg', '--os-tabs-hover-color', '--os-taskbar-focus-outline',
	'--os-taskbar-menu-separator', '--os-ui-button-bg', '--os-ui-button-fg', '--os-ui-card-border',
	'--os-ui-card-border-selected', '--os-ui-card-shadow-selected', '--os-ui-cat-check-border', '--os-ui-cat-guide-color',
	'--os-ui-cat-pop-border', '--os-ui-cat-pop-divider', '--os-ui-cat-trigger-border', '--os-ui-cat-uncat-border',
	'--os-ui-chip-bg', '--os-ui-chip-fg', '--os-ui-code-copy-color-hover', '--os-ui-key-bg', '--os-ui-key-border',
	'--os-ui-notice-accent', '--os-ui-notice-border', '--os-ui-rating-border', '--os-ui-rating-track',
	'--os-ui-repeater-row-border', '--os-ui-save-status-bg', '--os-ui-save-status-idle-color',
	'--os-ui-save-status-ring-color', '--os-ui-save-status-saved-bg', '--os-ui-segmented-selected-bg',
	'--os-ui-segmented-selected-fg', '--os-ui-spinner-color', '--os-ui-stat-border', '--os-ui-stat-value-color',
	'--os-ui-tabs-floor', '--os-ui-tag-input-add-border', '--os-ui-tag-input-pop-border', '--os-ui-tag-input-pop-divider',
	'--os-ui-text-field-border', '--os-ui-token-field-border',
	// The light-wallpaper ink block (body.os-active.os-wallpaper-light).
	'--os-desk-fg', '--os-icon-label-bg', '--os-icon-label-shadow', '--os-icon-glyph-shadow', '--os-icon-hover-bg',
	'--os-tile-hover-bg', '--os-tile-selected-bg', '--os-tile-fg-muted', '--os-tile-label-shadow',
	// The widget card's body ink, overloaded as "light ink on any dark thing".
	'--os-ui-fg-on-accent',
);
ok( 99 === count( array_unique( $deny ) ), 'the deny list carries all 99 owned tokens (got ' . count( array_unique( $deny ) ) . ')' );
$hit = array_values( array_intersect( $deny, array_keys( $tokens ) ) );
foreach ( array_keys( $tokens ) as $name ) {
	if ( preg_match( '/^--os-toolbar-|radius|corner|^--os-window-bg$|^--os-ui-(surface|bg|fg|text|color-)/', $name ) ) {
		$hit[] = $name;
	}
}
ok( array() === $hit, 'no setting-owned, accent-derived, toolbar, geometry, surface or text-colour token' . ( $hit ? ' (found: ' . implode( ', ', $hit ) . ')' : '' ) );

// ── Tokens: upstream's value grammar, mirrored (manifest.php:76-111) ────
$rejected = array();
foreach ( $tokens as $name => $value ) {
	$v      = trim( (string) $value );
	$lower  = strtolower( $v );
	$depth  = 0;
	$closed = true;
	foreach ( str_split( $v ) as $c ) {
		$depth += ( '(' === $c ) - ( ')' === $c );
		$closed = $closed && $depth >= 0;
	}
	$safe = '' !== $v && strlen( $v ) <= 256
		&& preg_match( '~^[A-Za-z0-9\s#%.,()/*+\-_\'"]+$~', $v )
		&& false === strpos( $v, '/*' ) && false === strpos( $v, '*/' )
		&& $closed && 0 === $depth;
	foreach ( array( 'url(', 'image-set(', 'element(', 'attr(', 'var(', 'expression', 'javascript' ) as $needle ) {
		$safe = $safe && false === strpos( $lower, $needle );
	}
	if ( ! $safe ) {
		$rejected[] = $name;
	}
}
ok( array() === $rejected, 'every value passes upstream\'s grammar, so none is silently dropped' . ( $rejected ? ' (rejected: ' . implode( ', ', $rejected ) . ')' : '' ) );
$aliased = array_keys( array_filter( $tokens, static function ( $v ) {
	return false !== stripos( (string) $v, 'var(' );
} ) );
ok( array() === $aliased, 'no value uses var(): every value is a literal' );

// Read through background-image or a non-final background layer: a bare hex there is invalid.
$image_slots = array( '--os-ui-holo-fill', '--os-ui-hero-mesh', '--os-ui-holo-sheen', '--os-ui-holo-edge', '--os-ui-holo-edge-quiet', '--os-tabs-active-frost', '--os-mesh-holo' );
$not_image   = array();
foreach ( $image_slots as $name ) {
	if ( isset( $tokens[ $name ] ) && 0 !== strpos( (string) $tokens[ $name ], 'linear-gradient(' ) ) {
		$not_image[] = $name;
	}
}
ok( array() === $not_image, 'tokens painted as background-image are gradients, not bare colours' );

ok( '"Bebas Neue", "Geist", system-ui, sans-serif' === ( $tokens['--os-titlebar-font'] ?? '' ), 'window titles read Bebas Neue, falling back to the shell\'s own face' );
ok( ! isset( $tokens['--os-ui-font-mono'] ) && ! isset( $tokens['--os-font'] ) && ! isset( $tokens['--os-ui-font'] ), 'body and mono type stay as they are' );

// ── Assets ──────────────────────────────────────────────────────────────
/** Map a manifest URL back to the plugin file it serves. */
function snt_dt_path( $url ) {
	return 0 === strpos( (string) $url, SNT_URL ) ? dirname( __DIR__ ) . '/' . substr( $url, strlen( SNT_URL ) ) : '';
}

/** Whether a file is a self-contained SVG with a title. */
function snt_dt_svg_ok( $path, &$why ) {
	$src = is_file( $path ) ? (string) file_get_contents( $path ) : '';
	$xml = '' !== $src ? @simplexml_load_string( $src ) : false;
	if ( false === $xml ) {
		$why = 'not well-formed XML';
		return false;
	}
	$xml->registerXPathNamespace( 's', 'http://www.w3.org/2000/svg' );
	$checks = array(
		'root is <svg>'          => 'svg' === $xml->getName(),
		'has a viewBox'          => '' !== (string) $xml['viewBox'],
		'has a <title>'          => '' !== trim( (string) ( $xml->xpath( '/s:svg/s:title' )[0] ?? '' ) ),
		'no <script>'            => false === stripos( $src, '<script' ),
		'no event handlers'      => ! preg_match( '/\son[a-z]+\s*=/i', $src ),
		'no external references' => ! preg_match( '/(xlink:)?href\s*=\s*["\'](?!#)/i', $src ) && false === stripos( $src, 'url(http' ),
	);
	foreach ( $checks as $label => $pass_check ) {
		if ( ! $pass_check ) {
			$why = $label . ' failed';
			return false;
		}
	}
	return true;
}

$icons = isset( $args['icons'] ) && is_array( $args['icons'] ) ? $args['icons'] : array();
$want  = array( 'APP:sn-dashboard', 'APP:sn-icon-dashboard', 'APP:signal-noise', 'APP:sn-analytics' );
ok( $want === array_keys( $icons ), 'icons answer exactly the S&N slots: the S&N Home window and its desktop icon, Signal & Noise, S&N Analytics' );
ok( isset( $icons['APP:sn-dashboard']['path'], $icons['APP:sn-icon-dashboard']['path'] ) && $icons['APP:sn-dashboard']['path'] === $icons['APP:sn-icon-dashboard']['path'], 'the S&N Home window and its desktop icon wear the same faders' );
foreach ( $icons as $slot => $icon ) {
	$why = '';
	ok( 'image' === ( $icon['type'] ?? '' ) && ! isset( $icon['color'] ) && snt_dt_svg_ok( snt_dt_path( $icon['path'] ?? '' ), $why ), "$slot is an untinted image icon, a valid SVG with a title" . ( $why ? " ($why)" : '' ) );
	$svg = (string) @file_get_contents( snt_dt_path( $icon['path'] ?? '' ) );
	ok( false !== stripos( $svg, '#161616' ) && false !== stripos( $svg, '#3a3a3a' ) && 1 === substr_count( strtolower( $svg ), '#ff4c47' ), "$slot is drawn on charcoal with a #3a3a3a edge and exactly one red mark" );
}

$walls = isset( $args['wallpapers'] ) && is_array( $args['wallpapers'] ) ? $args['wallpapers'] : array();
ok( array( 'signal', 'quiet' ) === array_keys( $walls ), 'two wallpapers are offered, Signal and Quiet' );
foreach ( $walls as $wid => $wall ) {
	$why = '';
	ok( snt_dt_svg_ok( snt_dt_path( $wall['path'] ?? '' ), $why ), "the $wid wallpaper is a valid SVG with a title" . ( $why ? " ($why)" : '' ) );
	$svg = strtolower( (string) @file_get_contents( snt_dt_path( $wall['path'] ?? '' ) ) );
	ok( false !== strpos( $svg, '#0a0a0a' ), "the $wid wallpaper sits on #0a0a0a" );
	ok( ( 'signal' === $wid ) === ( false !== strpos( $svg, '#ff4c47' ) ), "the $wid wallpaper " . ( 'signal' === $wid ? 'carries the one red line' : 'is noise only' ) );
}
ok( isset( $walls['signal']['path'] ) && ( $args['preview'] ?? '' ) === $walls['signal']['path'], 'the theme preview is the Signal wallpaper, not another file' );
$sig = strtolower( (string) @file_get_contents( snt_dt_path( $walls['signal']['path'] ?? '' ) ) );
ok( false === strpos( $sig, '@keyframes' ) || false !== strpos( $sig, 'prefers-reduced-motion: no-preference' ), 'the Signal line moves only when the reader has not asked for reduced motion' );

$fonts = isset( $args['fonts'] ) && is_array( $args['fonts'] ) ? $args['fonts'] : array();
ok( 1 === count( $fonts ) && 'Bebas Neue' === ( $fonts[0]['family'] ?? '' ), 'one face is loaded: Bebas Neue' );
$woff = snt_dt_path( $fonts[0]['src'][0] ?? '' );
ok( is_file( $woff ) && 'wOF2' === (string) file_get_contents( $woff, false, null, 0, 4 ), 'the Bebas Neue source is a WOFF2 file the plugin ships' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
