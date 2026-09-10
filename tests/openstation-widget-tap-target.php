<?php
/**
 * Signal & Noise Tools — the TAP TARGET floor for widget doorway links.
 *
 * WCAG 2.2 SC 2.5.8 (Target Size, Minimum) puts a 24x24 CSS-pixel floor under
 * pointer targets. The "Open X ->" links at the foot of every OpenStation
 * widget are STANDALONE links, not links inside a sentence, so the spec's
 * inline exception does not cover them.
 *
 * Measured in the phone layer on the running product 2026-09-10: eight of them
 * rendered 17-18px tall with `padding: 0` — the natural line box of 11px text
 * and nothing else. The same eight measure 18px at 1920px wide, so this was
 * never a mobile-only defect; the phone is only where it bites.
 *
 * These links are built in JS with an inline `style:` string, one per widget
 * file and no shared helper, which is precisely the shape a convention drifts
 * out of. So the floor is asserted here per link rather than trusted per file.
 *
 * Run: php tests/openstation-widget-tap-target.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** The WCAG 2.2 SC 2.5.8 minimum, in CSS pixels. */
const SNT_TAP_TARGET_MIN = 24;

/**
 * Every `el( 'a', { … } )` whose text is a standalone doorway (any label ending in an arrow).
 *
 * `style:` and `text:` appear in either order across these files, so the
 * object literal is read as a whole rather than scanned forward from one key.
 *
 * @param string $js Source.
 * @return array<int,array{text:string,style:string,line:int}>
 */
function snt_tap_doorway_links( $js ) {
	$out = array();
	if ( ! preg_match_all( '/el\(\s*[\'"]a[\'"]\s*,\s*\{(.*?)\}\s*\)/s', $js, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $m as $hit ) {
		$body = $hit[1][0];
		// Any doorway, not just the "Open …" ones: `Cron events →` is the
		// same control and an `Open`-prefixed pattern walked straight past it.
		if ( ! preg_match( '/text:\s*[\'"]\s*([^\'"]*?→)\s*[\'"]/u', $body, $t ) ) {
			continue;
		}
		preg_match( '/style:\s*[\'"]([^\'"]*)[\'"]/', $body, $s );
		$out[] = array(
			'text'  => $t[1],
			'style' => isset( $s[1] ) ? $s[1] : '',
			'line'  => substr_count( substr( $js, 0, $hit[0][1] ), "\n" ) + 1,
		);
	}
	return $out;
}

/** The declared min-height in px, or 0 when absent. */
function snt_tap_min_height( $style ) {
	return preg_match( '/min-height\s*:\s*([\d.]+)px/', $style, $m ) ? (float) $m[1] : 0.0;
}

$files = glob( __DIR__ . '/../assets/desktop-mode-widget*.js' );
ok( count( $files ) >= 5, 'the widget scripts are found (' . count( $files ) . ') — a glob that matched nothing would pass every pin below vacuously' );

$links = array();
foreach ( $files as $f ) {
	foreach ( snt_tap_doorway_links( (string) file_get_contents( $f ) ) as $l ) {
		$l['file'] = basename( $f );
		$links[]   = $l;
	}
}
ok( count( $links ) >= 9, 'the scan finds the doorway links (' . count( $links ) . ' found, 9 measured live) — a regex that stopped matching would report a clean sweep over nothing' );

$short = array();
foreach ( $links as $l ) {
	if ( snt_tap_min_height( $l['style'] ) < SNT_TAP_TARGET_MIN ) {
		$short[] = $l['file'] . ':' . $l['line'] . ' "' . $l['text'] . '" (' . ( snt_tap_min_height( $l['style'] ) ?: 'none' ) . ')';
	}
}
ok( array() === $short,
	'every doorway link declares min-height >= ' . SNT_TAP_TARGET_MIN . 'px — they are standalone links, so SC 2.5.8\'s inline exception does not apply'
	. ( $short ? ":\n    " . implode( "\n    ", $short ) : '' ) );

// ── the CSS side ────────────────────────────────────────────────────────
// Two interactive controls in S&N Home carry the same 18px line box, and are
// styled in the sheet rather than inline. `.snt-sys__v` is deliberately NOT
// pinned as a whole: it also labels static values, which are not targets.
$dash = (string) file_get_contents( __DIR__ . '/../apps/sn-dashboard/sn-dashboard.css' );

/** The declared min-height of the FIRST rule matching a selector, or 0. */
function snt_tap_rule_min_height( $css, $selector ) {
	if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', preg_replace( '#/\*.*?\*/#s', '', $css ), $m, PREG_SET_ORDER ) ) {
		return 0.0;
	}
	foreach ( $m as $rule ) {
		if ( false === strpos( $rule[1], $selector ) ) {
			continue;
		}
		if ( preg_match( '/min-height\s*:\s*([\d.]+)px/', $rule[2], $h ) ) {
			return (float) $h[1];
		}
	}
	return 0.0;
}

foreach ( array( '.snt-home__view-all', 'os-button.snt-sys__v' ) as $sel ) {
	ok( snt_tap_rule_min_height( $dash, $sel ) >= SNT_TAP_TARGET_MIN,
		"$sel declares min-height >= " . SNT_TAP_TARGET_MIN . 'px — measured 18px live, and it is a control, not a label' );
}
ok( 0.0 === snt_tap_rule_min_height( $dash, '.snt-sys__meta' ),
	'   ...and a NON-target rule is left alone — a floor applied to every value would pad static text for no one' );

// Negative controls: the scanner must see the shape that shipped, and must not
// invent a pass from a link that simply has no style at all.
$probe_bad  = "el( 'a', { style: 'display:inline-block;font-size:11px;', text: 'Open Analytics →', href: u } )";
$probe_good = "el( 'a', { style: 'display:inline-flex;min-height:24px;', text: 'Open Analytics →', href: u } )";
$probe_other = "el( 'a', { style: 'font-size:11px;', text: 'Dismiss', href: u } )";
$bad  = snt_tap_doorway_links( $probe_bad );
$good = snt_tap_doorway_links( $probe_good );
ok( 1 === count( $bad ) && snt_tap_min_height( $bad[0]['style'] ) < SNT_TAP_TARGET_MIN,
	'   ...the scanner DETECTS the exact shape that shipped — the pre-fix declaration, fed back in, is seen and judged short' );
ok( 1 === count( $good ) && snt_tap_min_height( $good[0]['style'] ) >= SNT_TAP_TARGET_MIN,
	'   ...and accepts a compliant one, so the pin is not simply always-red' );
ok( array() === snt_tap_doorway_links( $probe_other ),
	'   ...and ignores a link that is not a doorway — the floor is asserted where it applies, not everywhere' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
