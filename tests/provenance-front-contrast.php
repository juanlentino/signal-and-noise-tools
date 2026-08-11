<?php
/**
 * WCAG AA contrast for the plugin's own front-end provenance CSS.
 *
 * WHY THIS EXISTS AS ITS OWN SUITE. The token-level contrast report
 * (inc/health-contrast-tokens.php) scores theme TOKEN PAIRS. Every colour in
 * assets/provenance-front.css is a hardcoded hex, so the report structurally
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

/** The `color:` value declared for a selector in the shipped stylesheet. */
function pfc_color_of( $css, $selector ) {
	$q = preg_quote( $selector, '#' );
	if ( ! preg_match( '#(?:^|[},\s])' . $q . '\s*\{([^}]*)\}#m', $css, $m ) ) {
		return '';
	}
	return preg_match( '/(?<![-\w])color\s*:\s*(#[0-9a-fA-F]{3,6})/', $m[1], $c ) ? $c[1] : '';
}

echo "Provenance front-end contrast — AA on the SHIPPED stylesheet (v10.89.1)\n\n";

/* ═══════════════════════════════════════════════════════════════════
 * 1. The chip pins its own surface
 * ═══════════════════════════════════════════════════════════════════ */

preg_match( '/\.sn-prov-chip\s*\{([^}]*)\}/', $css, $chip_rule );
$chip_decl = $chip_rule[1] ?? '';
ok( preg_match( '/background\s*:\s*(#[0-9a-fA-F]{3,6})/', $chip_decl, $bg ) === 1,
	'THE CHIP DECLARES ITS OWN BACKGROUND — without one its contrast is a property of placement, not of the component, and it is unscoreable in isolation' );
$chip_bg = $bg[1] ?? '#ffffff';

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

$panel_bg = preg_match( '/\.sn-prov-panel\s*\{[^}]*background\s*:\s*(#[0-9a-fA-F]{3,6})/', $css, $pb ) ? $pb[1] : '#ffffff';
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
