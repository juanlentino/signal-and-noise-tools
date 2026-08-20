<?php
/**
 * Tests: front-end text clears AA on the surface it actually sits on, in EVERY
 * palette the theme serves.
 *
 * WHY THIS EXISTS. tests/front-end-css-inverts.php closes the LITERAL class —
 * a hardcoded colour cannot invert, by construction. It says so itself that it
 * cannot see the next class up: a token used in the wrong ROLE. The theme's
 * "ink as chrome" bug is the canonical case — an INK token used to mean "a
 * surface that contrasts with the page", which then inverts twice and vanishes.
 * That passes a literal sweep cleanly, because every value involved is a token.
 *
 * The theme caught its instance (the command palette, v12.0.3) with a
 * SCREENSHOT, not a test. This ports the fix it then wrote: resolve each token
 * per scheme and compute the REAL contrast of ink against its surface, so the
 * finding is mechanical instead of dependent on someone looking at that screen
 * in that mode.
 *
 * WHAT THIS CANNOT SEE, stated plainly:
 *   - Non-text contrast. Borders, rules and icons are checked by nobody here;
 *     the 3:1 non-text minimum is out of scope and is NOT implied by a pass.
 *   - Surfaces declared in a DIFFERENT rule from the ink. A selector cannot
 *     tell you what it is sitting on, so anything not declaring its own
 *     background is assumed to sit on the page ground (`void`) unless it is
 *     named in $on_surface below. A wrong entry there is a wrong answer here.
 *   - Opacity, blend modes, gradients, images behind text.
 *   - Whether the rule is reachable at all. Dead CSS is checked and passes.
 *
 * @since 12.3.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "front-end text clears AA on its real surface, in every served palette\n\n";

/**
 * The theme's palettes, PINNED. Three of them, keyed by palette IDENTITY the
 * way sn_health_theme_palettes() keys them: `root` and `high-contrast` are
 * light-scheme VARIATIONS, `dark` is a scheme that overrides whichever
 * variation is active. They are orthogonal axes, so all three must clear.
 *
 * This is a cross-repo copy and therefore skew debt. sn_fecc_drift_check()
 * below reconciles it against a sibling theme checkout when one exists, and
 * says out loud when it could not.
 */
$palettes = array(
	'root'          => array( 'void' => '#ffffff', 'asphalt' => '#f5f5f5', 'concrete' => '#d9d9d9', 'rust' => '#666666', 'bone' => '#000000', 'blood' => '#e00404', 'signal' => '#ff4c47' ),
	'high-contrast' => array( 'void' => '#ffffff', 'asphalt' => '#e0e0e0', 'concrete' => '#9e9e9e', 'rust' => '#333333', 'bone' => '#000000', 'blood' => '#e00404', 'signal' => '#ff4c47' ),
	'dark'          => array( 'void' => '#0a0a0a', 'asphalt' => '#171717', 'concrete' => '#383838', 'rust' => '#9e9e9e', 'bone' => '#ffffff', 'blood' => '#ff4c47', 'signal' => '#ff6b66' ),
);

/** Selectors whose surface is NOT the page ground, listed by name. */
$on_surface = array();

// ── colour maths (WCAG 2.x relative luminance) ─────────────────────────────
function sn_fecc_rgb( $v ) {
	$v = trim( strtolower( $v ) );
	if ( preg_match( '/^#([0-9a-f]{3})$/', $v, $m ) ) {
		$v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
	}
	if ( preg_match( '/^#([0-9a-f]{6})$/', $v, $m ) ) {
		return array( hexdec( substr( $m[1], 0, 2 ) ), hexdec( substr( $m[1], 2, 2 ) ), hexdec( substr( $m[1], 4, 2 ) ) );
	}
	if ( preg_match( '/^rgba?\(\s*([0-9]+)[,\s]+([0-9]+)[,\s]+([0-9]+)/', $v, $m ) ) {
		return array( (int) $m[1], (int) $m[2], (int) $m[3] );
	}
	return null;
}
function sn_fecc_lum( $rgb ) {
	$c = array();
	foreach ( $rgb as $ch ) {
		$s   = $ch / 255;
		$c[] = ( $s <= 0.03928 ) ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function sn_fecc_ratio( $a, $b ) {
	$ra = sn_fecc_rgb( $a ); $rb = sn_fecc_rgb( $b );
	if ( null === $ra || null === $rb ) { return null; }
	$l1 = sn_fecc_lum( $ra ); $l2 = sn_fecc_lum( $rb );
	if ( $l1 < $l2 ) { $t = $l1; $l1 = $l2; $l2 = $t; }
	return ( $l1 + 0.05 ) / ( $l2 + 0.05 );
}

/**
 * Resolve a CSS value to a literal for one token map.
 *
 * A var() reference resolves to the token's value when the map defines it and
 * to the FALLBACK when it does not — which is exactly what a browser does, and
 * is why the fallback is not dead code on a site running an older theme.
 */
function sn_fecc_resolve( $value, $tokens, $depth = 0 ) {
	$value = trim( $value );
	if ( $depth > 6 ) { return null; }
	if ( preg_match( '/^var\(\s*(--[a-zA-Z0-9-]+)\s*(?:,\s*(.*))?\)$/s', $value, $m ) ) {
		$name = $m[1];
		$slug = preg_replace( '/^--wp--preset--color--/', '', $name );
		if ( isset( $tokens[ $slug ] ) ) { return sn_fecc_resolve( $tokens[ $slug ], $tokens, $depth + 1 ); }
		if ( isset( $tokens[ $name ] ) ) { return sn_fecc_resolve( $tokens[ $name ], $tokens, $depth + 1 ); }
		return isset( $m[2] ) && '' !== trim( $m[2] ) ? sn_fecc_resolve( $m[2], $tokens, $depth + 1 ) : null;
	}
	return sn_fecc_rgb( $value ) ? $value : null;
}

/** Flatten a stylesheet to [ selector, body ] pairs, descending into at-rules. */
function sn_fecc_rules( $css ) {
	$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	$out   = array();
	$n     = strlen( $css );
	$i     = 0;
	$start = 0;
	while ( $i < $n ) {
		if ( '{' === $css[ $i ] ) {
			$sel   = trim( substr( $css, $start, $i - $start ) );
			$depth = 0;
			for ( $j = $i; $j < $n; $j++ ) {
				if ( '{' === $css[ $j ] ) { ++$depth; }
				if ( '}' === $css[ $j ] ) { --$depth; if ( 0 === $depth ) { break; } }
			}
			$body = substr( $css, $i + 1, $j - $i - 1 );
			if ( '' !== $sel && '@' === $sel[0] ) {
				// An at-rule wraps rules; recurse so @media is not a blind spot.
				// The CONDITION is carried onto each inner selector: dropping it
				// makes a `prefers-color-scheme: dark` block indistinguishable
				// from a light one, which is how this file first mis-read its
				// own fixture.
				foreach ( sn_fecc_rules( $body ) as $inner ) {
					$out[] = array( $sel . ' ' . $inner[0], $inner[1] );
				}
			} else {
				$out[] = array( $sel, $body );
			}
			$i     = $j + 1;
			$start = $i;
			continue;
		}
		++$i;
	}
	return $out;
}

/** slug => value for one declaration body. */
function sn_fecc_decls( $body ) {
	$out = array();
	foreach ( explode( ';', (string) $body ) as $d ) {
		$at = strpos( $d, ':' );
		if ( false === $at ) { continue; }
		$prop = trim( substr( $d, 0, $at ) );
		// A var() value contains its own colons only inside parens; property
		// names never do, so the FIRST colon is always the separator.
		$out[ strtolower( $prop ) ] = trim( substr( $d, $at + 1 ) );
	}
	return $out;
}

// ── the pin must not drift from the theme it copies ────────────────────────
// A cross-repo copy that nobody reconciles is how a guard starts measuring a
// palette the site stopped serving. When a sibling theme checkout is present
// this compares against the real source; when it is not, it SAYS SO rather
// than passing quietly, because an unchecked pin and a correct pin look
// identical from inside this file.
echo "Group: the pinned palettes match the theme\n";
$theme_root = dirname( dirname( __DIR__ ) ) . '/signal-and-noise';
$checked    = 0;
if ( is_dir( $theme_root ) ) {
	foreach ( array( 'root' => '/theme.json', 'high-contrast' => '/styles/high-contrast.json' ) as $id => $rel ) {
		$json = json_decode( (string) @file_get_contents( $theme_root . $rel ), true );
		$live = array();
		foreach ( (array) ( $json['settings']['color']['palette'] ?? array() ) as $entry ) {
			$live[ (string) ( $entry['slug'] ?? '' ) ] = strtolower( (string) ( $entry['color'] ?? '' ) );
		}
		if ( $live ) {
			++$checked;
			ok( $live === $palettes[ $id ], "pinned `$id` palette matches $rel" );
		}
	}
	$crit = (string) @file_get_contents( $theme_root . '/assets/css/critical.css' );
	if ( '' !== $crit && preg_match( '/:root\[data-theme="dark"\][^{]*\{(.*?)\}/s', $crit, $m ) ) {
		$live = array();
		foreach ( sn_fecc_decls( $m[1] ) as $prop => $val ) {
			if ( 0 === strpos( $prop, '--wp--preset--color--' ) ) {
				$live[ substr( $prop, 21 ) ] = strtolower( $val );
			}
		}
		if ( $live ) {
			++$checked;
			ok( $live === $palettes['dark'], 'pinned `dark` palette matches the theme critical.css override' );
		}
	}
}
if ( 0 === $checked ) {
	echo "SKIP: no sibling theme checkout at $theme_root — the pinned palettes were NOT reconciled this run.\n";
	echo "      They are still what the contrast maths below uses, so a pass here means \"correct GIVEN the pin\".\n";
}
ok( 3 === count( $palettes ), 'three palettes are pinned — root and high-contrast are light VARIATIONS, dark is a SCHEME over either' );

// ── the sweep ──────────────────────────────────────────────────────────────
// Ink whose surface this file cannot know, named with the reason. An entry here
// is a claim that the pair was checked by a human; it is not a way to silence a
// finding.
$allow = array();

$skip_ink = array( 'currentcolor', 'inherit', 'transparent', 'unset', 'initial', 'revert' );

/**
 * @return array<int,string> Human-readable failures for one stylesheet.
 */
function sn_fecc_audit( $file, $palettes, $on_surface, $allow, $skip_ink ) {
	$css   = (string) file_get_contents( $file );
	$rules = sn_fecc_rules( $css );
	$root  = dirname( __DIR__ ) . '/';
	$rel   = ( 0 === strpos( $file, $root ) ) ? substr( $file, strlen( $root ) ) : $file;
	$bad   = array();

	// Tokens the stylesheet defines ITSELF, per scheme. A plugin-owned token
	// is resolved from the file under test rather than from the pin, so a
	// stylesheet that carries its own dark override is measured with it.
	$local = array( 'light' => array(), 'dark' => array() );
	foreach ( $rules as $rule ) {
		list( $sel, $body ) = $rule;
		$is_dark = ( false !== strpos( $sel, 'data-theme="dark"' ) ) || ( false !== strpos( $sel, 'prefers-color-scheme' ) );
		if ( false === strpos( $sel, ':root' ) && ! $is_dark ) { continue; }
		foreach ( sn_fecc_decls( $body ) as $prop => $val ) {
			if ( 0 === strpos( $prop, '--' ) ) {
				$local[ $is_dark ? 'dark' : 'light' ][ $prop ] = $val;
			}
		}
	}

	foreach ( $rules as $rule ) {
		list( $sel, $body ) = $rule;
		if ( false !== strpos( $sel, ':root' ) ) { continue; }
		$decls = sn_fecc_decls( $body );
		$ink   = $decls['color'] ?? '';
		if ( '' === $ink || in_array( strtolower( $ink ), $skip_ink, true ) ) { continue; }

		$surface = $decls['background-color'] ?? ( $decls['background'] ?? '' );
		if ( '' === $surface || null === sn_fecc_rgb( preg_replace( '/^var\([^,]*,\s*|\)$/', '', $surface ) ) && false === strpos( $surface, 'var(' ) ) {
			// Nothing usable declared here: it sits on whatever its ancestor
			// painted. The page ground is `void` unless named otherwise.
			$surface = $on_surface[ $sel ] ?? 'var(--wp--preset--color--void)';
		}

		foreach ( $palettes as $id => $tokens ) {
			$scheme = ( 'dark' === $id ) ? 'dark' : 'light';
			$map    = array_merge( $tokens, $local['light'], 'dark' === $scheme ? $local['dark'] : array() );
			$a      = sn_fecc_resolve( $ink, $map );
			$b      = sn_fecc_resolve( $surface, $map );
			if ( null === $a || null === $b ) { continue; }
			$r = sn_fecc_ratio( $a, $b );
			if ( null === $r || $r >= 4.5 ) { continue; }
			$key = $rel . ' :: ' . $sel;
			if ( isset( $allow[ $key ] ) ) { continue; }
			$bad[] = sprintf( '%s [%s] %s on %s = %.2f:1', $key, $id, $a, $b, $r );
		}
	}
	return $bad;
}

echo "\nGroup: every front-end ink/surface pair clears AA (4.5:1) in all three palettes\n";
$files = glob( dirname( __DIR__ ) . '/assets/*front*.css' );
ok( count( $files ) > 5, 'the sweep finds the front-end stylesheets (guard: a glob matching nothing would pass vacuously)' );

$findings = array();
foreach ( $files as $file ) {
	$findings = array_merge( $findings, sn_fecc_audit( $file, $palettes, $on_surface, $allow, $skip_ink ) );
}
foreach ( $findings as $f ) { echo "  -> $f\n"; }
ok( empty( $findings ), sprintf( 'no front-end text falls below AA on its surface (%d finding(s))', count( $findings ) ) );

// ── a fallback must be a FALLBACK, not the value ───────────────────────────
// The literal sweep exempts `var(--x, #lit)` because a fallback literal is the
// point of a fallback. That exemption is only sound if `--x` is DEFINED
// somewhere: a reference to a token nothing declares renders the literal every
// time, on every site, in every scheme. It is a hardcoded colour wearing a
// var() costume, and it passes the literal guard by construction.
//
// Found by this file on 2026-08-20: `--sn-signal-ink` was referenced four
// times across two stylesheets and declared nowhere in EITHER repo.
echo "\nGroup: every --sn-* token referenced is also defined\n";
$defined = array();
$referenced = array();
foreach ( $files as $file ) {
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file ) );
	foreach ( sn_fecc_rules( $css ) as $rule ) {
		foreach ( sn_fecc_decls( $rule[1] ) as $prop => $val ) {
			if ( 0 === strpos( $prop, '--sn-' ) ) {
				$defined[ $prop ][ 'assets/' . basename( $file ) . ' :: ' . $rule[0] ] = $val;
			}
		}
	}
	if ( preg_match_all( '/var\(\s*(--sn-[a-zA-Z0-9-]+)/', $css, $m ) ) {
		foreach ( $m[1] as $name ) { $referenced[ $name ][] = 'assets/' . basename( $file ); }
	}
}
$undefined = array_diff( array_keys( $referenced ), array_keys( $defined ) );
foreach ( $undefined as $name ) {
	echo '  -> ' . $name . ' referenced in ' . implode( ', ', array_unique( $referenced[ $name ] ) ) . ' but declared nowhere' . "\n";
}
ok( empty( $undefined ), 'no front-end stylesheet references a --sn-* token that nothing defines (' . count( $undefined ) . ' undefined)' );

// The same token declared in two files must AGREE, per scheme. Plain CSS gives
// two stylesheets no way to share a declaration, so the identity is asserted
// rather than hoped for — the theme learned this with its two dark blocks.
$drifted = array();
foreach ( $defined as $name => $sites ) {
	foreach ( array( 'dark' => true, 'light' => false ) as $scheme => $want_dark ) {
		$vals = array();
		foreach ( $sites as $where => $val ) {
			$is_dark = ( false !== strpos( $where, 'data-theme="dark"' ) ) || ( false !== strpos( $where, 'prefers-color-scheme' ) );
			if ( $is_dark === $want_dark ) { $vals[ strtolower( $val ) ] = $where; }
		}
		if ( count( $vals ) > 1 ) { $drifted[] = "$name ($scheme): " . implode( ' | ', array_keys( $vals ) ); }
	}
}
foreach ( $drifted as $d ) { echo "  -> $d\n"; }
ok( empty( $drifted ), 'a --sn-* token declared in more than one stylesheet has the SAME value in each, per scheme' );

// A plugin-owned colour token whose light value is a LITERAL must also be
// declared for dark. Without that rule the exemption this file grants to token
// DEFINITIONS (see tests/front-end-css-inverts.php) would be a way to launder
// any hardcoded colour: declare it once at :root, reference it everywhere, and
// the literal sweep goes quiet while nothing inverts.
//
// A token whose light value is itself a var() to an inverting palette token
// needs no dark twin — it already inverts through the thing it points at.
function sn_fecc_needs_dark( $defined ) {
	$out = array();
	foreach ( $defined as $name => $sites ) {
		$light_literal = false;
		$has_dark      = false;
		foreach ( $sites as $where => $val ) {
			$is_dark = ( false !== strpos( $where, 'data-theme="dark"' ) ) || ( false !== strpos( $where, 'prefers-color-scheme' ) );
			if ( $is_dark ) { $has_dark = true; continue; }
			if ( null !== sn_fecc_rgb( trim( $val ) ) ) { $light_literal = true; }
		}
		if ( $light_literal && ! $has_dark ) { $out[] = $name; }
	}
	return $out;
}
echo "\nGroup: a literal-valued token is declared for BOTH schemes\n";
$no_dark = sn_fecc_needs_dark( $defined );
foreach ( $no_dark as $n ) { echo "  -> $n has a literal light value and no dark declaration\n"; }
ok( empty( $no_dark ), 'every literal-valued --sn-* token has a dark declaration too (' . count( $no_dark ) . ' without)' );
ok( array( '--sn-fake' ) === sn_fecc_needs_dark( array( '--sn-fake' => array( ':root' => '#123456' ) ) ),
	'NEGATIVE CONTROL: a literal token with no dark twin IS caught (the check above is not vacuous)' );
ok( array() === sn_fecc_needs_dark( array( '--sn-fake' => array( ':root' => '#123456', ':root[data-theme="dark"]' => '#abcdef' ) ) ),
	'and one WITH a dark twin is not' );
ok( array() === sn_fecc_needs_dark( array( '--sn-fake' => array( ':root' => 'var(--wp--preset--color--blood,#e00404)' ) ) ),
	'and a token pointing at an inverting palette token needs no dark twin' );

// ── negative controls ──────────────────────────────────────────────────────
// The instrument must be able to emit a POSITIVE. Each control drives the real
// resolver and the real maths, not a fixture of what they are assumed to say.
echo "\nGroup: negative controls\n";
$dark = $palettes['dark'];
$lite = $palettes['root'];
ok( abs( sn_fecc_ratio( '#12703a', '#ffffff' ) - 6.17 ) < 0.01, 'the maths agrees with the published figure for #12703a on white (6.17:1)' );
ok( sn_fecc_ratio( '#12703a', '#0a0a0a' ) < 4.5, 'NEGATIVE CONTROL: the reported defect — verified-green on dark void — IS below AA' );
ok( '#0a0a0a' === sn_fecc_resolve( 'var(--wp--preset--color--void)', $dark ), 'a token resolves to its DARK value when the dark map is used' );
ok( '#ffffff' === sn_fecc_resolve( 'var(--wp--preset--color--void)', $lite ), 'and to its light value under a light palette' );
ok( '#123456' === sn_fecc_resolve( 'var(--sn-not-a-token,#123456)', $dark ), 'an unknown token falls back exactly as a browser would' );
ok( '#ff4c47' === sn_fecc_resolve( 'var(--wp--preset--color--blood,#e00404)', $dark ), 'and a KNOWN token beats its fallback — the fallback is not what ships' );
$ruleset = sn_fecc_rules( '@media (min-width:40em){.a{color:red}}.b{color:blue}' );
ok( 2 === count( $ruleset ), 'the parser descends into @media — an at-rule is not a blind spot' );
$probe = sn_fecc_audit( __DIR__ . '/fixtures/fecc-probe.css', $palettes, $on_surface, $allow, $skip_ink );
ok( 3 === count( $probe ), 'NEGATIVE CONTROL: a planted role error (ink token used as a surface) IS caught — once per palette, ' . count( $probe ) . ' finding(s)' );
ok( 1 === count( preg_grep( '/\[dark\]/', $probe ) ) && 1 === count( preg_grep( '/\[high-contrast\]/', $probe ) ),
	'and the planted error is reported in EVERY palette, not just the first — a per-palette count of one proves the loop is not short-circuiting' );
$probe_media = sn_fecc_rules( '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--x:#fff}}' );
ok( false !== strpos( $probe_media[0][0], 'prefers-color-scheme' ), 'an at-rule CONDITION survives the descent — a dark media block is not read as a light one' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
