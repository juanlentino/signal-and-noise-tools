<?php
/**
 * WCAG AA contrast for the plugin's own front-end provenance CSS.
 *
 * WHY THIS EXISTS AS ITS OWN SUITE. The token-level contrast report
 * (inc/health-contrast-tokens.php) scores theme TOKEN PAIRS. Every TEXT colour
 * in assets/provenance-front.css is a hardcoded hex, so the report structurally
 * cannot see any of it — its own coverage sentence says so. That blind spot is
 * not theoretical: the status chip shipped at 3.49:1 (confirmed) and 2.95:1
 * (pending) on plain white, live in every note's byline, and TWO sessions
 * corrected the link colours in this very file on the same day while looking
 * straight past the chip beside them. A handoff note said "status chips
 * deliberately untouched (functional state colours)" and that was accepted —
 * but functional colour still has to meet contrast WHEN IT IS THE TEXT COLOUR.
 *
 * The chip also used to set no background, so its contrast was a property of
 * PLACEMENT rather than of the component: unscoreable in isolation, and
 * different in a byline than in a page brow. It now pins its own surface, which
 * is what makes the ratios below true wherever it is used.
 *
 * This suite reads the SHIPPED stylesheet — not a copy of the values — so a
 * colour edited in the CSS without checking is caught here rather than by a
 * reader with low vision.
 *
 * Thresholds: WCAG 2.2 AA, 4.5:1. The chip is .72rem and the action links are
 * .7rem — both SMALLER than normal text, so the 3:1 large-text allowance does
 * not apply to any of them.
 *
 * @since plugin v10.89.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$css = file_get_contents( __DIR__ . '/../assets/provenance-front.css' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

/** WCAG relative luminance of an #rrggbb colour. */
function pfc_lum( $hex ) {
	$hex = ltrim( trim( $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$chan = array();
	foreach ( array( 0, 2, 4 ) as $i ) {
		$c = hexdec( substr( $hex, $i, 2 ) ) / 255;
		$chan[] = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
}

/** WCAG contrast ratio between two #rrggbb colours. */
function pfc_ratio( $a, $b ) {
	$la = pfc_lum( $a );
	$lb = pfc_lum( $b );
	$hi = max( $la, $lb );
	$lo = min( $la, $lb );
	return ( $hi + 0.05 ) / ( $lo + 0.05 );
}

/**
 * The LIGHT value of a plugin-owned token, read from this stylesheet's own
 * `:root` block.
 *
 * As of v12.3.0 the three-tier epistemic palette is tokenised so it can invert.
 * The tokens are declared in this same file, so resolving them here keeps this
 * suite reading the SHIPPED value rather than a copy — which was the whole
 * point of it. The dark declarations are deliberately NOT read: every assertion
 * below is a light-scheme claim, and silently averaging two schemes into one
 * number is the false green this file's own commentary warns about.
 */
function pfc_token_light( $css, $name ) {
	if ( ! preg_match( '/:root\s*\{([^}]*)\}/', $css, $m ) ) {
		return '';
	}
	return preg_match( '/' . preg_quote( $name, '/' ) . '\s*:\s*(#[0-9a-fA-F]{3,6})/', $m[1], $c ) ? $c[1] : '';
}

/** The `color:` value declared for a selector in the shipped stylesheet. */
function pfc_color_of( $css, $selector ) {
	$q = preg_quote( $selector, '#' );
	if ( ! preg_match( '#(?:^|[},\s])' . $q . '\s*\{([^}]*)\}#m', $css, $m ) ) {
		return '';
	}
	if ( preg_match( '/(?<![-\w])color\s*:\s*(#[0-9a-fA-F]{3,6})/', $m[1], $c ) ) {
		return $c[1];
	}
	// A token reference resolves to its declaration in this file. An UNKNOWN
	// token returns '' and fails the assertion loudly — it is never quietly
	// resolved to its fallback, because the fallback is not what ships.
	if ( preg_match( '/(?<![-\w])color\s*:\s*var\(\s*(--sn-[a-zA-Z0-9-]+)/', $m[1], $c ) ) {
		return pfc_token_light( $css, $c[1] );
	}
	return '';
}

echo "Provenance front-end contrast — AA on the SHIPPED stylesheet (v10.89.1)\n\n";

/* ═══════════════════════════════════════════════════════════════════
 * 1. The chip pins its own surface
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * The palette values this suite is allowed to resolve a token to.
 *
 * Pinned as literals, and identical in BOTH shipped palettes (theme.json root
 * and styles/high-contrast.json) — verified against the theme's origin/main, not
 * a working tree. A token whose value differs per palette must never be resolved
 * to one number here; that is the exact false green the usage tier exists for.
 */
$PALETTE_VOID = '#ffffff';

/**
 * Expand a 3-digit hex to 6 so `#fff` and `#ffffff` compare equal.
 *
 * The agreement check below is about COLOUR, not about spelling. Comparing the
 * raw strings would red a correct stylesheet for using CSS shorthand — a test
 * failing on a difference that no reader can perceive.
 */
function pfc_hex6( $hex ) {
	$h = strtolower( trim( (string) $hex ) );
	if ( preg_match( '/^#[0-9a-f]{3}$/', $h ) ) {
		return '#' . $h[1] . $h[1] . $h[2] . $h[2] . $h[3] . $h[3];
	}
	return $h;
}

/**
 * Read a `background` declaration as a hex, accepting the token-with-fallback
 * form the usage scan was widened to score.
 *
 * Returns array{form:'literal'|'token', hex:string, fallback:string} or null.
 * A token this suite does not know is NOT resolved to a default — an unknown
 * token means the assertion below fails loudly rather than scoring white and
 * reporting a pass.
 */
function pfc_background_of( $decl ) {
	if ( preg_match( '/background\s*:\s*var\(\s*--wp--preset--color--([a-z0-9-]+)\s*(?:,\s*(#[0-9a-fA-F]{3,6})\s*)?\)/', (string) $decl, $m ) ) {
		$known = array( 'void' => '#ffffff' );
		$slug  = strtolower( $m[1] );
		if ( ! isset( $known[ $slug ] ) ) {
			return null;
		}
		return array(
			'form'     => 'token',
			'hex'      => $known[ $slug ],
			'fallback' => isset( $m[2] ) ? pfc_hex6( $m[2] ) : '',
		);
	}
	if ( preg_match( '/background\s*:\s*(#[0-9a-fA-F]{3,6})/', (string) $decl, $m ) ) {
		return array(
			'form'     => 'literal',
			'hex'      => pfc_hex6( $m[1] ),
			'fallback' => '',
		);
	}
	return null;
}

preg_match( '/\.sn-prov-chip\s*\{([^}]*)\}/', $css, $chip_rule );
$chip_decl = $chip_rule[1] ?? '';
$chip_read = pfc_background_of( $chip_decl );
ok( null !== $chip_read,
	'THE CHIP DECLARES ITS OWN BACKGROUND — without one its contrast is a property of placement, not of the component, and it is unscoreable in isolation' );

// The chip's background is the TOKEN-WITH-FALLBACK form. Both halves are
// load-bearing and neither is decoration:
//   - the TOKEN is what makes the pairing visible to the contrast usage scan
//     (inc/health-contrast-usage.php), which resolves preset slugs per palette;
//   - the FALLBACK is what keeps the surface painted if the theme ever drops the
//     preset, so the chip can never silently revert to a placement property.
// A bare literal loses the first; a bare token loses the second. The scan's
// regex had to be widened before this form was adoptable at all.
ok( $chip_read && 'token' === $chip_read['form'],
	'the chip background is declared as a PRESET TOKEN, so the usage scan resolves it per palette instead of reading a hardcoded colour' );
ok( $chip_read && '' !== $chip_read['fallback'],
	'and it carries a FALLBACK, so the surface survives the preset being dropped — safe and scoreable, not one or the other' );

// The fallback must AGREE with the token. `var(--…--void, #000)` would render
// white normally and black in the very situation the fallback exists for — a
// divergence no palette scan can see, because only one branch is ever in the CSS
// the scanner reads.
ok( $chip_read && $chip_read['fallback'] === $PALETTE_VOID,
	sprintf( 'the fallback (%s) equals the token\'s value in every shipped palette (%s) — a fallback that disagrees is a colour nobody can scan', $chip_read['fallback'] ?: '(none)', $PALETTE_VOID ) );

$chip_bg = $chip_read ? $chip_read['hex'] : $PALETTE_VOID;

/* ═══════════════════════════════════════════════════════════════════
 * 2. Every status colour clears AA on that surface
 * ═══════════════════════════════════════════════════════════════════ */

$statuses = array(
	'.sn-prov-confirmed' => 'confirmed',
	'.sn-prov-pending'   => 'pending',
	'.sn-prov-genesis'   => 'genesis / muted',
);
foreach ( $statuses as $sel => $label ) {
	$fg = pfc_color_of( $css, $sel );
	ok( '' !== $fg, "status colour for $label is readable from the stylesheet ($sel)" );
	if ( '' === $fg ) { continue; }
	$r = pfc_ratio( $fg, $chip_bg );
	ok( $r >= 4.5, sprintf( '%s chip text %s on %s = %.2f:1 (AA needs 4.5 — .72rem is below the large-text allowance)', $label, $fg, $chip_bg, $r ) );
}

// A chip whose background is ever overridden away must still be legible on the
// darkest surface the shipped palettes can put under it (High Contrast asphalt).
// Not a hard AA gate — the pinned background is the contract — but a loud
// warning line, because this is the exact way the original failure hid.
$HC_ASPHALT = '#e0e0e0';
$soft = 0;
foreach ( $statuses as $sel => $label ) {
	$fg = pfc_color_of( $css, $sel );
	if ( '' === $fg ) { continue; }
	if ( pfc_ratio( $fg, $HC_ASPHALT ) < 4.5 ) { ++$soft; }
}
ok( 0 === $soft,
	'every status colour ALSO clears AA on High Contrast asphalt (#e0e0e0) — so an overridden background cannot silently reintroduce the failure' );

/* ═══════════════════════════════════════════════════════════════════
 * 3. The action links (fixed in v10.88.0) stay fixed
 * ═══════════════════════════════════════════════════════════════════ */

// The panel's surface, read the same way. Previously this defaulted to
// '#ffffff' when the regex missed, which meant a changed panel background would
// have been scored against white anyway and every ratio below would have stayed
// green while describing a surface that no longer existed. A default that
// happens to equal the right answer is not an answer.
preg_match( '/\.sn-prov-panel\s*\{([^}]*)\}/', $css, $panel_rule );
$panel_read = pfc_background_of( $panel_rule[1] ?? '' );
ok( null !== $panel_read, 'the panel background is readable from the stylesheet — not assumed when the read fails' );
ok( $panel_read && 'token' === $panel_read['form'] && $panel_read['fallback'] === $PALETTE_VOID,
	'the panel background is the same token-with-fallback form as the chip, with an agreeing fallback' );
$panel_bg = $panel_read ? $panel_read['hex'] : $PALETTE_VOID;
foreach ( array( '.sn-prov-links a' => 'action link (rest)', '.sn-prov-chip-verify' => 'chip verify link (rest)' ) as $sel => $label ) {
	$fg = pfc_color_of( $css, $sel );
	if ( '' === $fg ) { continue; }
	$r = pfc_ratio( $fg, $panel_bg );
	ok( $r >= 4.5, sprintf( '%s %s on %s = %.2f:1', $label, $fg, $panel_bg, $r ) );
}

// The hover/focus pair specifically — v10.88.0's fix. #ff4c47 measured 3.29:1
// here, and it is the :focus-visible colour, which is where a keyboard user
// lands. A regression to a lighter red must red this suite.
if ( preg_match( '/\.sn-prov-links a:hover[^{]*\{[^}]*color\s*:\s*(#[0-9a-fA-F]{3,6})/', $css, $hv ) ) {
	$r = pfc_ratio( $hv[1], $panel_bg );
	ok( $r >= 4.5, sprintf( 'action link HOVER/FOCUS %s on %s = %.2f:1 — the state a keyboard user lands on', $hv[1], $panel_bg, $r ) );
}

/* ═══════════════════════════════════════════════════════════════════
 * 4. The measurement itself is trustworthy
 * ═══════════════════════════════════════════════════════════════════ */

// Anchor the maths on values with published answers, so a broken luminance
// function cannot quietly report everything as passing.
ok( abs( pfc_ratio( '#000000', '#ffffff' ) - 21.0 ) < 0.01, 'black on white is 21:1 (the spec boundary — proves the ratio maths)' );
ok( abs( pfc_ratio( '#ffffff', '#ffffff' ) - 1.0 ) < 0.001, 'a colour against itself is 1:1' );
ok( abs( pfc_ratio( '#767676', '#ffffff' ) - 4.54 ) < 0.01, '#767676 on white is 4.54:1 (the canonical just-passes grey)' );
// And prove the gate can FAIL: the colour this release removed must not pass.
ok( pfc_ratio( '#1f9d55', '#ffffff' ) < 4.5, 'the REMOVED confirmed green (#1f9d55) still measures below AA — the gate is falsifiable' );

/* ═══════════════════════════════════════════════════════════════════
 * The DARK half, and where it lives
 * ═══════════════════════════════════════════════════════════════════
 * Every assertion above is a LIGHT-scheme claim. Dark is not checked here on
 * purpose — it is checked in tests/front-end-css-contrast.php, which resolves
 * each token per palette and sweeps all three. What is asserted here is that
 * the handoff EXISTS: a suite that quietly stopped covering half the schemes
 * looks exactly like one that never covered them.
 */
$dark_suite = __DIR__ . '/front-end-css-contrast.php';
ok( is_file( $dark_suite ), 'the per-palette contrast suite exists to carry the dark half' );
ok( is_file( $dark_suite ) && false !== strpos( (string) file_get_contents( $dark_suite ), '*front*.css' ),
	'and it sweeps the front-end stylesheets by glob, so this file is in its scope by construction' );
foreach ( array( '--sn-prov-verified', '--sn-prov-asserted', '--sn-prov-unattributed', '--sn-signal-ink' ) as $tok ) {
	ok( '' !== pfc_token_light( $css, $tok ), "$tok has a light declaration in this stylesheet" );
	ok( preg_match( '/:root\[data-theme="dark"\]\s*\{[^}]*' . preg_quote( $tok, '/' ) . '\s*:/', $css ) === 1,
		"$tok has a dark declaration too — a tier that cannot invert is the defect this file was widened for" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
