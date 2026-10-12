<?php
/**
 * Re-derive OpenStation's brand constants from an OpenStation checkout.
 *
 * NOT a suite (tests/run.sh globs tests/*.php only). Used by
 * tests/openstation-desktop-theme.php when SNT_OPENSTATION_SRC points at an
 * OpenStation source tree, so an upstream upgrade that adds a purple or pink
 * constant fails that suite instead of quietly showing through the theme.
 *
 * The rule, applied to `assets/css/variables.css`, base block `body.os-active`:
 *   A. every colour-bearing token whose value is a gradient or whose name is
 *      mesh, holo, crown or sheen, and every plain `var()` alias of one;
 *   B. every token whose own value carries a pink or purple literal;
 * less the five tokens a setting writes (the accent family and `--os-bg`),
 * everything whose value reaches one of them through `var()`, and the
 * light-wallpaper ink block. Plus:
 *   C. every UNDECLARED token the shell or app CSS reads with a pink or purple
 *      literal fallback that no accent token feeds.
 *
 * @since 2026.10.5
 */

/** CSS with comments blanked (newlines kept, so line numbers survive). */
function snt_osbc_strip( $css ) {
	return (string) preg_replace_callback(
		'#/\*.*?\*/#s',
		static function ( $m ) {
			return str_repeat( "\n", substr_count( $m[0], "\n" ) );
		},
		(string) $css
	);
}

/** The declarations of the first rule whose selector is exactly $selector. */
function snt_osbc_block( $css, $selector ) {
	$at = strpos( $css, $selector . ' {' );
	if ( false === $at ) {
		return array();
	}
	$open  = strpos( $css, '{', $at );
	$depth = 0;
	$len   = strlen( $css );
	for ( $i = $open; $i < $len; $i++ ) {
		$depth += ( '{' === $css[ $i ] ) - ( '}' === $css[ $i ] );
		if ( 0 === $depth ) {
			break;
		}
	}
	preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]*);/s', substr( $css, $open + 1, $i - $open - 1 ), $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $d ) {
		$out[ $d[1] ] = trim( (string) preg_replace( '/\s+/', ' ', $d[2] ) );
	}
	return $out;
}

/** Whether a value carries a pink or purple colour literal (hue 240 to 345, not a tinted gray). */
function snt_osbc_has_pink( $value ) {
	$rgbs = array();
	preg_match_all( '/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $value, $hex );
	foreach ( $hex[1] as $h ) {
		$h      = 3 === strlen( $h ) ? $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2] : $h;
		$rgbs[] = array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
	}
	preg_match_all( '/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $value, $fn, PREG_SET_ORDER );
	foreach ( $fn as $f ) {
		$rgbs[] = array( (int) $f[1], (int) $f[2], (int) $f[3] );
	}
	foreach ( $rgbs as $c ) {
		$max = max( $c );
		$min = min( $c );
		if ( $max < 80 || ( $max - $min ) / $max < 0.3 ) {
			continue;
		}
		$d = $max - $min;
		if ( $max === $c[0] ) {
			$hue = 60 * fmod( ( $c[1] - $c[2] ) / $d + 6, 6 );
		} elseif ( $max === $c[1] ) {
			$hue = 60 * ( ( $c[2] - $c[0] ) / $d + 2 );
		} else {
			$hue = 60 * ( ( $c[0] - $c[1] ) / $d + 4 );
		}
		if ( $hue >= 240 && $hue <= 345 ) {
			return true;
		}
	}
	return false;
}

/**
 * The constant set, derived from an OpenStation checkout.
 *
 * @param string $src OpenStation source root.
 * @return string[]|null Sorted token names, or null when the tree is unreadable.
 */
function snt_osbc_derive( $src ) {
	$file = rtrim( (string) $src, '/' ) . '/assets/css/variables.css';
	if ( ! is_readable( $file ) ) {
		return null;
	}
	$css      = snt_osbc_strip( file_get_contents( $file ) );
	$base     = snt_osbc_block( $css, 'body.os-active' );
	$light    = array_keys( snt_osbc_block( $css, 'body.os-active.os-wallpaper-light' ) );
	$declared = array();
	preg_match_all( '/(--[a-z0-9-]+)\s*:/', $css, $all );
	foreach ( $all[1] as $n ) {
		$declared[ $n ] = true;
	}

	// Setting-owned, and everything that reaches one through var().
	$owned = array_flip( array( '--wp-admin-theme-color', '--os-ui-accent', '--os-ui-accent-ink', '--os-ui-accent-dim', '--os-bg' ) );
	do {
		$grew = false;
		foreach ( $base as $name => $value ) {
			if ( isset( $owned[ $name ] ) || ! preg_match_all( '/var\(\s*(--[a-z0-9-]+)/', $value, $refs ) ) {
				continue;
			}
			if ( array_intersect( $refs[1], array_keys( $owned ) ) ) {
				$owned[ $name ] = true;
				$grew           = true;
			}
		}
	} while ( $grew );
	$skip = $owned + array_flip( $light );

	$set = array();
	foreach ( $base as $name => $value ) {
		if ( isset( $skip[ $name ] ) ) {
			continue;
		}
		$colour = 'none' === $value || preg_match( '/#|rgb|hsl|gradient\(|var\(|transparent/', $value );
		$rule_a = $colour && ( false !== strpos( $value, 'gradient(' ) || preg_match( '/mesh|holo|crown|sheen/', $name ) );
		if ( $rule_a || snt_osbc_has_pink( $value ) ) {
			$set[ $name ] = true;
		}
	}
	// Plain aliases of a constant (`--os-ui-step-chip-bg: var(--os-mesh-holo)`).
	foreach ( $base as $name => $value ) {
		if ( ! isset( $skip[ $name ] ) && preg_match( '/^var\(\s*(--[a-z0-9-]+)\s*\)$/', $value, $m ) && isset( $set[ $m[1] ] ) ) {
			$set[ $name ] = true;
		}
	}

	// C. Undeclared tokens read with a pink literal fallback in shell or app CSS.
	$sheets = array_merge(
		(array) glob( dirname( $file ) . '/*.css' ),
		(array) glob( rtrim( (string) $src, '/' ) . '/apps/*/*.css' )
	);
	foreach ( $sheets as $sheet ) {
		$text = snt_osbc_strip( (string) file_get_contents( $sheet ) );
		$pos  = 0;
		while ( false !== ( $pos = strpos( $text, 'var(', $pos ) ) ) {
			$depth = 0;
			$end   = strlen( $text );
			for ( $i = $pos + 3; $i < $end; $i++ ) {
				$depth += ( '(' === $text[ $i ] ) - ( ')' === $text[ $i ] );
				if ( 0 === $depth ) {
					break;
				}
			}
			$inner = substr( $text, $pos + 4, $i - $pos - 4 );
			$pos  += 4;
			if ( ! preg_match( '/^\s*(--os-[a-z0-9-]+)\s*,(.*)$/s', $inner, $m ) || isset( $declared[ $m[1] ] ) ) {
				continue;
			}
			preg_match_all( '/var\(\s*(--[a-z0-9-]+)/', $m[2], $refs );
			if ( ! array_intersect( $refs[1], array_keys( $owned ) ) && snt_osbc_has_pink( $m[2] ) ) {
				$set[ $m[1] ] = true;
			}
		}
	}

	$names = array_keys( $set );
	sort( $names );
	return $names;
}
