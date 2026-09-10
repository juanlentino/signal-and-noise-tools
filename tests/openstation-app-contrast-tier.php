<?php
/**
 * Signal & Noise Tools — the CONTRAST TIER of the native app stylesheets.
 *
 * OpenStation ships two muted text tokens and they are not interchangeable.
 * Measured on the running product 2026-09-10, against the app surface
 * rgb(26, 23, 33):
 *
 *   --os-ui-fg-muted  #b3afb5  8.18:1  — safe for any text
 *   --os-ui-fg-faint  #66636b  3.00:1  — the 3:1 tier: large text, borders,
 *                                        icons, rules. NOT body text.
 *   --os-ui-accent    #e11d48  3.76:1  — same tier, same limit.
 *
 * `fg-faint` landing on exactly 3.00 is the tell: it is built for the
 * non-text/large-text tier. Two Dashboard rules used it for 11px labels
 * (`.snt-home__pulse-group-label`, `.snt-home__timestamp`) and measured
 * 3.00:1 against a 4.5:1 requirement — a token used exactly as named, at a
 * size it was never rated for. This pins the RULE rather than the two rules,
 * because the next one will be written by someone reading the token name.
 *
 * Large text per WCAG: >= 24px, or >= 18.66px when bold (>= 700).
 *
 * Run: php tests/openstation-app-contrast-tier.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** Strip comments so a token named in prose never counts as a use. */
function snt_tier_strip( $css ) {
	return preg_replace( '#/\*.*?\*/#s', '', $css );
}

/**
 * Every rule whose `color` is the 3:1 tier, with the size it renders at.
 *
 * @param string $css Stylesheet source.
 * @return array<int,array{sel:string,px:float,bold:bool}>
 */
function snt_tier_small_text_uses( $css ) {
	$out = array();
	if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', snt_tier_strip( $css ), $m, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $m as $rule ) {
		$sel  = trim( $rule[1] );
		$body = $rule[2];
		// `color:` only — the same token is legitimate on border-color/fill.
		if ( ! preg_match( '/(?<![-\w])color\s*:[^;]*--os-ui-fg-faint/', $body ) ) {
			continue;
		}
		$px   = 0.0;   // 0 = inherited, which we cannot prove is large.
		$bold = false;
		if ( preg_match( '/(?<![-\w])font-size\s*:\s*([\d.]+)px/', $body, $f ) ) {
			$px = (float) $f[1];
		} elseif ( preg_match( '/(?<![-\w])font\s*:\s*(\d+)\s+([\d.]+)px/', $body, $f ) ) {
			$bold = (int) $f[1] >= 700;
			$px   = (float) $f[2];
		}
		if ( preg_match( '/font-weight\s*:\s*(\d+)/', $body, $w ) ) {
			$bold = (int) $w[1] >= 700;
		}
		$large = ( $px >= 24.0 ) || ( $px >= 18.66 && $bold );
		if ( ! $large ) {
			$out[] = array( 'sel' => $sel, 'px' => $px, 'bold' => $bold );
		}
	}
	return $out;
}

$sheets = glob( __DIR__ . '/../apps/*/*.css' );
ok( count( $sheets ) >= 3, 'the app stylesheets are found (' . count( $sheets ) . ') — a glob that matched nothing would pass every pin below vacuously' );

$offenders = array();
foreach ( $sheets as $sheet ) {
	foreach ( snt_tier_small_text_uses( (string) file_get_contents( $sheet ) ) as $use ) {
		$offenders[] = basename( $sheet ) . ' — ' . substr( $use['sel'], 0, 60 ) . ' @ ' . ( $use['px'] ?: 'inherited' ) . 'px';
	}
}
ok( array() === $offenders,
	'--os-ui-fg-faint is never the `color` of small text in an app sheet — it is the 3:1 tier (3.00:1 measured) and small text needs 4.5:1'
	. ( $offenders ? ": \n    " . implode( "\n    ", $offenders ) : '' ) );

// The instrument must be able to see an offender, or the pin above is decoration.
$probe = ".x { font: 600 11px/1.2 mono; color: var( --os-ui-fg-faint, #66636b ); }";
ok( 1 === count( snt_tier_small_text_uses( $probe ) ),
	'   ...and the scanner DETECTS that shape — the exact rule that shipped, fed back in, is seen' );
$probe_ok = ".x { font-size: 28px; color: var( --os-ui-fg-faint, #66636b ); }\n.y { border-color: var( --os-ui-fg-faint ); }";
ok( 0 === count( snt_tier_small_text_uses( $probe_ok ) ),
	'   ...and does NOT flag large text or a border — the token is correct at its own tier' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
