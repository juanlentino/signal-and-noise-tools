<?php
/**
 * Tests: this plugin's stylesheets stay on the theme's tokens.
 *
 * ── What this measures, and the four things it must NOT count ──
 *
 * A "governance" scan of CSS is easy to write and easy to get wrong. The hard
 * part is not finding hex literals — grep does that — it is knowing which
 * literals are SUPPOSED to be literals. Four categories are legitimate, and a
 * scan that counts any of them produces a number so alarming and so wrong that
 * the check gets switched off within a week. All four were found the hard way
 * (2026-08-26), each after a measurement that looked like a finding:
 *
 *   1. COMMENTS. Prose paints nothing, and this estate's CSS comments quote hex
 *      values constantly while explaining past contrast bugs.
 *   2. TOKEN DEFINITIONS. `--sn-panel: #161616` is where a literal belongs. The
 *      theme defines its whole DARK palette this way, and a scan comparing
 *      those against the LIGHT palette reports the dark scheme as drift.
 *   3. SELECTOR TEXT. `[fill="#222"] { fill: var(--token) }` matches on a
 *      literal in order to REMAP it. The selector paints nothing; the
 *      declaration beside it is a token. (Theme v12.7.4.)
 *   4. WP-ADMIN CHROME. admin.css styles wp-admin and should match WordPress's
 *      own palette, not this theme's brand.
 *   5. var() FALLBACKS. `var(--token, #fff)` is governed BY the token; the
 *      literal applies only if it resolves to nothing.
 *
 * With all four excluded, front-end coverage measured 94.2% rather than the
 * 73.0% a naive scan reported.
 *
 * ── Why a RATCHET and not a gate ──
 *
 * 73 findings predate this file. Failing on all of them would make the check
 * permanently red and therefore ignored. It fails on an INCREASE instead, so
 * the number can only go down. A decrease is reported and the baseline must be
 * lowered, which is what stops the ratchet slipping.
 *
 * Run: php tests/token-governance.php
 * @since 13.6.1
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * The theme's LIGHT palette, PINNED — the same cross-repo copy, and the same
 * skew debt, that tests/front-end-css-contrast.php carries. Reconciled against
 * a sibling theme checkout below when one exists, and it says so out loud when
 * it cannot, rather than passing quietly on a stale copy.
 */
$palette = array(
	'void' => '#ffffff', 'asphalt' => '#f5f5f5', 'concrete' => '#d9d9d9',
	'rust' => '#666666', 'bone'    => '#000000', 'blood'    => '#e00404',
	'signal' => '#ff4c47',
);

/** WordPress's own admin chrome. Correct in an admin surface, not drift. */
$wp_admin = array(
	'#2271b1', '#135e96', '#1d2327', '#f0f0f1', '#c3c4c7', '#8c8f94', '#787c82',
	'#646970', '#d63638', '#b32d2e', '#00a32a', '#dba617', '#f6f7f7', '#dcdcde',
	'#3582c4', '#72aee6', '#a7aaad', '#50575e', '#2c3338', '#f6f7f8',
);

function sn_tg_norm( $h ) {
	$h = strtolower( trim( $h ) );
	if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $h, $m ) ) {
		return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
	}
	return $h;
}

/**
 * Every literal that can actually PAINT, with all four exclusions applied.
 *
 * @param string $css
 * @param bool   $is_admin Admin surfaces may use WordPress's chrome palette.
 * @return string[] normalised hex literals
 */
function sn_tg_literals( $css, $is_admin = false ) {
	global $wp_admin;
	// 1. comments paint nothing
	$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
	// 2. token definitions are where a literal belongs
	$css = preg_replace( '/--[a-zA-Z0-9-]+\s*:[^;}]*/', '', $css );
	// 3. keep ONLY what can paint: the innermost declaration bodies. Extraction
	//    rather than selector-stripping — consuming an opening brace leaves the
	//    next selector unanchored, so the first rule inside an at-rule survives.
	preg_match_all( '/\{([^{}]*)\}/', $css, $blocks );
	$css = implode( ';', $blocks[1] );
	// 5. var() FALLBACKS. `var(--token, #fff)` is a safety net, not drift: the
	//    token governs and the literal only applies if it resolves to nothing.
	//    The estate uses this heavily and the theme's own inverts test exempts
	//    it too. (Whether a fallback AGREES with its token is a real and
	//    separate question -- see the literal-beats-token finding -- and not
	//    what this ratchet measures.)
	$css = preg_replace( '/var\(\s*--[a-zA-Z0-9-]+\s*,[^()]*\)/', '', $css );

	preg_match_all( '/#[0-9a-fA-F]{3}\b|#[0-9a-fA-F]{6}\b/', $css, $m );
	$out = array();
	foreach ( $m[0] as $lit ) {
		$n = sn_tg_norm( $lit );
		// 4. wp-admin chrome, admin surfaces only
		if ( $is_admin && in_array( $n, $wp_admin, true ) ) { continue; }
		$out[] = $n;
	}
	return $out;
}

/* ════════════════════════════════════════════════════════════════════════
 * Negative controls FIRST — every exclusion is a hole, and a hole nobody
 * probes is how a scanner ends up reporting zero because it reads nothing.
 * ════════════════════════════════════════════════════════════════════════ */
echo "Group: the four exclusions, each probed in both directions\n";
ok( array( '#ff0000' ) === sn_tg_literals( '.x{color:#ff0000}' ), 'a bare literal in a declaration IS counted' );
ok( array() === sn_tg_literals( '.x{color:var(--wp--preset--color--bone)}' ), 'a token reference is not' );
ok( array() === sn_tg_literals( '/* the row went #161616 in dark */ .x{color:var(--a)}' ), 'EXCLUSION 1: a hex quoted in a comment is not a paint' );
ok( array( '#ff0000' ) === sn_tg_literals( '/* note #eeeeee */ .x{color:#ff0000}' ), 'and a comment nearby does not hide a real one' );
ok( array() === sn_tg_literals( ':root{--sn-panel:#161616}' ), 'EXCLUSION 2: a token DEFINITION is not drift — it is the definition' );
ok( array( '#ff0000' ) === sn_tg_literals( ':root{--sn-panel:#161616;color:#ff0000}' ), 'but a naked literal in the same rule still counts' );
ok( array() === sn_tg_literals( '[fill="#222222"]{fill:var(--a)}' ), 'EXCLUSION 3: a literal in a SELECTOR is not a paint' );
ok( array( '#ffffff' ) === sn_tg_literals( '[fill="#222222"]{fill:#ffffff}' ), 'and the selector exemption cannot smuggle a paint past' );
ok( array( '#123456' ) === sn_tg_literals( '@media (x){.y{color:#123456}}' ), 'a literal in the FIRST rule inside an at-rule is still caught' );
ok( array() === sn_tg_literals( '.x{color:#2271b1}', true ), 'EXCLUSION 4: wp-admin chrome is fine on an admin surface' );
ok( array( '#2271b1' ) === sn_tg_literals( '.x{color:#2271b1}', false ), 'but the SAME colour on a front-end surface is not exempt' );
ok( array( '#ff0000' ) === sn_tg_literals( '.x{color:#f00}' ), 'a 3-digit hex normalises to 6 so the baseline cannot be dodged by shorthand' );
ok( array() === sn_tg_literals( '.x{background:var(--wp--preset--color--void,#fff)}' ), 'EXCLUSION 5: a var() FALLBACK is governed by its token, not drift' );
ok( array( '#ff0000' ) === sn_tg_literals( '.x{background:var(--a,#fff);color:#ff0000}' ), 'and a fallback does not hide a naked literal in the same rule' );
ok( array( '#ff0000' ) === sn_tg_literals( '.x{color:var(--a)#ff0000}' ), 'a bare var() with no fallback shields nothing' );

/* ════════════════════════════════════════════════════════════════════════
 * Cross-repo palette reconciliation (the front-end-css-contrast.php idiom).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nGroup: the pinned palette is reconciled, or the gap is stated\n";
$theme_root = dirname( dirname( __DIR__ ) ) . '/signal-and-noise';
if ( is_dir( $theme_root ) && is_readable( $theme_root . '/theme.json' ) ) {
	$tj   = json_decode( (string) file_get_contents( $theme_root . '/theme.json' ), true );
	$live = array();
	foreach ( (array) ( $tj['settings']['color']['palette'] ?? array() ) as $c ) {
		$live[ (string) $c['slug'] ] = sn_tg_norm( (string) $c['color'] );
	}
	ok( ! empty( $live ), 'the sibling theme checkout yields a palette (' . count( $live ) . ' colours)' );
	ksort( $live ); $p = $palette; ksort( $p );
	ok( $live === $p, 'the PINNED palette matches the sibling theme checkout — no cross-repo skew' );
} else {
	echo "SKIP: no sibling theme checkout at $theme_root — the pinned palette was NOT reconciled this run.\n";
	ok( true, 'palette reconciliation SKIPPED and said so (never silently assumed current)' );
}

/* ════════════════════════════════════════════════════════════════════════
 * The ratchet.
 * ════════════════════════════════════════════════════════════════════════ */
$BASELINE = array(
	// Pinned 2026-08-26. 64 ungoverned literals across FOUR sheets; the other
	// twelve stylesheets are clean and are deliberately absent (a file not
	// listed has a baseline of 0, and the check above catches 0 -> 1).
	//
	// An earlier pin read 103 because the scan counted var() fallbacks. Those
	// are governed BY their token, and excluding them removed 39 phantom
	// findings and took ten files to zero. The number a governance scan reports
	// is mostly a statement about its exclusions.
	'assets/admin.css'            => 43,
	'assets/uptime-status.css'    => 13,
	'assets/css/prov-verify.css'  => 4,
	'assets/machine-readers.css'  => 3,
	'assets/provenance-admin.css' => 1,
);

echo "\nGroup: ungoverned literals per stylesheet (ratchet)\n";
$root  = dirname( __DIR__ );
$files = array_merge( (array) glob( $root . '/assets/*.css' ), (array) glob( $root . '/assets/css/*.css' ) );
sort( $files );
ok( count( $files ) > 8, 'the sweep finds the stylesheets (' . count( $files ) . ') — a glob matching nothing would pass vacuously' );

$actual = array();
foreach ( $files as $f ) {
	$base     = str_replace( $root . '/', '', $f );
	$is_admin = ( false !== strpos( $base, 'admin' ) || false !== strpos( $base, 'audit-log' ) );
	$lits     = sn_tg_literals( (string) file_get_contents( $f ), $is_admin );
	if ( $lits ) { $actual[ $base ] = count( $lits ); }
}
ksort( $actual );

if ( isset( $BASELINE['__PLACEHOLDER__'] ) ) {
	echo "\n  BASELINE NOT YET PINNED. Current counts:\n";
	foreach ( $actual as $b => $n ) { echo "\t'$b' => $n,\n"; }
	echo "  total: " . array_sum( $actual ) . "\n";
} else {
	foreach ( $actual as $base => $n ) {
		$was = $BASELINE[ $base ] ?? 0;
		if ( $n > $was ) { echo "  -> $base: $was -> $n\n"; }
		ok( $n <= $was, sprintf( '%s: %d ungoverned (baseline %d) — the count may only go down', $base, $n, $was ) );
	}
	// An IMPROVEMENT must fail too, and that is deliberate. A ratchet whose
	// baseline is allowed to sit above reality has already slipped: the next
	// regression is absorbed by the slack instead of being reported. Clearing
	// a literal is a two-line change -- fix the CSS, lower the number.
	//
	// The first version of this loop asserted `$now >= $was || $now === $actual[$base]`,
	// where the right side is $now compared with itself and therefore always
	// true. It could not fail. Caught by reading, not by running.
	foreach ( $BASELINE as $base => $was ) {
		$now = $actual[ $base ] ?? 0;
		if ( $now < $was ) { echo "  -> IMPROVED $base: $was -> $now. Lower its baseline in this file.\n"; }
		ok( $now === $was, sprintf( '%s: %d matches its pinned baseline exactly (%d)', $base, $now, $was ) );
	}
	ok( array_sum( $actual ) <= array_sum( $BASELINE ), sprintf( 'estate total %d is at or below the %d baseline', array_sum( $actual ), array_sum( $BASELINE ) ) );
}


/* ════════════════════════════════════════════════════════════════════════
 * FONT STACKS (v13.6.2). Colour was only half the estate: 52 declarations
 * across 13 front-end sheets carried a SECOND monospace vocabulary
 * (ui-monospace, SFMono-Regular, ...) against the theme's declared DM Mono.
 * Nobody decided to have two; it accumulated.
 *
 * Scoped by SURFACE, exactly as the colour ratchet is:
 *   - theme-dependent FRONT-END sheets must reference the font token
 *   - wp-admin sheets may use the system stack, which is correct chrome
 *   - prov-verify.css is EXEMPT and must stay exempt: /verify is a
 *     standalone document that owns its own values on purpose, and the file
 *     says so. Reaching into --wp--preset--* from it broke a pre-existing
 *     guard in provenance-verify-page.php ("every custom property the
 *     stylesheet READS must also be DECLARED in it") the moment it was tried.
 *
 * A fallback must AGREE with the token it guards, or the two disagree
 * exactly when the fallback matters -- two `heading` fallbacks were missing
 * 'Bebas Neue' entirely and would have dropped to Impact.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nGroup: front-end font stacks reference the token\n";

function sn_tg_fonts( $css ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
	preg_match_all( '/font-family\s*:\s*([^;}\n]+)/i', $css, $m );
	$out = array();
	foreach ( $m[1] as $d ) {
		$d = trim( preg_replace( '/\s+/', ' ', $d ), " \t;" );
		if ( '' === $d || 0 === strpos( $d, 'var(' ) ) { continue; }
		if ( in_array( strtolower( $d ), array( 'inherit', 'initial', 'unset' ), true ) ) { continue; }
		$out[] = $d;
	}
	return $out;
}
ok( array( 'Comic Sans' ) === sn_tg_fonts( '.x{font-family:Comic Sans}' ), 'a literal font stack IS counted' );
ok( array() === sn_tg_fonts( '.x{font-family:var(--wp--preset--font-family--body,\'DM Mono\')}' ), 'a token reference is not' );
ok( array() === sn_tg_fonts( '/* we dropped ui-monospace, SFMono-Regular here */ .x{color:red}' ), 'a stack named in a COMMENT is not a declaration' );
ok( array() === sn_tg_fonts( '.x{font-family:inherit}' ), 'inherit is not a stack' );

$FONT_EXEMPT = array( 'assets/css/prov-verify.css' );
$font_bad = array();
foreach ( $files as $f ) {
	$base = str_replace( $root . '/', '', $f );
	if ( false !== strpos( $base, 'admin' ) || false !== strpos( $base, 'audit-log' ) ) { continue; }
	if ( in_array( $base, $FONT_EXEMPT, true ) ) { continue; }
	$lit = sn_tg_fonts( (string) file_get_contents( $f ) );
	if ( $lit ) { $font_bad[ $base ] = $lit; }
}
foreach ( $font_bad as $b => $l ) { echo "  -> $b: " . implode( ' | ', array_slice( array_unique( $l ), 0, 3 ) ) . "\n"; }
ok( empty( $font_bad ), sprintf( 'every theme-dependent front-end sheet uses the font token (%d sheet(s) with a literal stack)', count( $font_bad ) ) );
ok( ! empty( sn_tg_fonts( (string) file_get_contents( $root . '/assets/css/prov-verify.css' ) ) ), 'and prov-verify.css STILL owns its own stacks — the exemption is real, not a file that happens to conform' );

// A fallback that disagrees with its token is worse than no fallback: the two
// differ exactly when the fallback is the thing being used.
$theme_json = dirname( dirname( __DIR__ ) ) . '/signal-and-noise/theme.json';
if ( is_readable( $theme_json ) ) {
	$tj = json_decode( (string) file_get_contents( $theme_json ), true );
	$fams = array();
	foreach ( (array) ( $tj['settings']['typography']['fontFamilies'] ?? array() ) as $f ) {
		$fams[ (string) $f['slug'] ] = preg_replace( '/\s*,\s*/', ',', trim( (string) $f['fontFamily'] ) );
	}
	ok( ! empty( $fams ), 'theme.json yields font families (' . implode( ', ', array_keys( $fams ) ) . ')' );
	$disagree = array();
	foreach ( $files as $f ) {
		$css = (string) file_get_contents( $f );
		if ( preg_match_all( '/var\(\s*--wp--preset--font-family--([a-z]+)\s*,\s*([^)]*)\)/i', $css, $ms, PREG_SET_ORDER ) ) {
			foreach ( $ms as $set ) {
				$fb = preg_replace( '/\s*,\s*/', ',', trim( $set[2] ) );
				if ( ! isset( $fams[ $set[1] ] ) || $fb !== $fams[ $set[1] ] ) {
					$disagree[] = str_replace( $root . '/', '', $f ) . ": --{$set[1]} => $fb";
				}
			}
		}
	}
	foreach ( array_unique( $disagree ) as $d ) { echo "  -> $d\n"; }
	ok( empty( $disagree ), sprintf( 'every font-family var() fallback AGREES with theme.json (%d disagreeing)', count( $disagree ) ) );
} else {
	echo "SKIP: no sibling theme checkout — font fallbacks were NOT reconciled this run.\n";
	ok( true, 'font fallback reconciliation SKIPPED and said so' );
}


echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
