<?php
/**
 * Signal & Noise: contrast usage scan, the CSS reading (the at-rule spans, the print-only test, the rule parser, the color reader).
 *
 * Split from inc/health-contrast-usage.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Split a stylesheet into (selector, body) rules.
 *
 * Intentionally a regex and not a parser. The scan needs to know which
 * declarations sit together under which selector; it does not need to
 * understand at-rules, nesting or specificity, and a hand-rolled parser that
 * half-understands them is a worse lie than a regex that plainly does not.
 *
 * @param string $css Raw stylesheet.
 * @return array<int,array{sel:string,body:string}>
 */
/**
 * Byte spans of every at-rule BLOCK, innermost resolvable, with its prelude.
 *
 * The rule regex below matches innermost `{…}` pairs, which means it happily
 * lifts a rule out of `@media` and discards the condition. That is invisible
 * until a conditional declaration gets scored as if it always applied.
 *
 * A depth scan is the honest way to know the enclosing context. At-rules
 * without a block (`@import`, `@charset` — a `;` before the next `{`) are
 * skipped, since they enclose nothing.
 *
 * @param string $css Comment-stripped stylesheet.
 * @return array<int,array{start:int,end:int,at:string}>
 */
function sn_health_contrast_usage_at_spans( $css ) {
	$spans = array();
	$len   = strlen( $css );
	for ( $i = 0; $i < $len; $i++ ) {
		if ( '@' !== $css[ $i ] ) {
			continue;
		}
		$brace = strpos( $css, '{', $i );
		if ( false === $brace ) {
			break;
		}
		$semi = strpos( $css, ';', $i );
		if ( false !== $semi && $semi < $brace ) {
			continue;
		}
		$depth = 0;
		$end   = $len - 1;
		for ( $j = $brace; $j < $len; $j++ ) {
			if ( '{' === $css[ $j ] ) {
				++$depth;
			} elseif ( '}' === $css[ $j ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $j;
					break;
				}
			}
		}
		$spans[] = array(
			'start' => $brace,
			'end'   => $end,
			'at'    => trim( substr( $css, $i, $brace - $i ) ),
		);
	}
	return $spans;
}

/**
 * Is this at-rule prelude PRINT-ONLY?
 *
 * This tier measures what a reader meets ON SCREEN. A print colour scored
 * against a screen surface is a failure nobody can encounter — demonstrated:
 * `@media print{.card{color:#cccccc}}` over a white card reads 1.61:1 and would
 * have been reported.
 *
 * A query listing print ALONGSIDE a screen context (`@media screen,print`) is
 * NOT print-only: dropping it would lose a rule that genuinely applies. Every
 * comma-separated component has to be print for the block to go.
 *
 * @param string $at Prelude, e.g. `@media only print`.
 * @return bool
 */
function sn_health_contrast_usage_is_print_only( $at ) {
	if ( '' === $at || 0 !== stripos( $at, '@media' ) ) {
		return false;
	}
	$query = trim( substr( $at, 6 ) );
	if ( '' === $query ) {
		return false;
	}
	foreach ( explode( ',', $query ) as $part ) {
		if ( ! preg_match( '/^\s*(only\s+)?print\b/i', $part ) ) {
			return false;
		}
	}
	return true;
}

function sn_health_contrast_usage_rules( $css ) {
	$css   = preg_replace( '!/\*.*?\*/!s', '', (string) $css );
	$spans = sn_health_contrast_usage_at_spans( $css );
	$rules = array();
	if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', (string) $css, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches as $match ) {
			// #1185: `end( $lines )` kept only the LAST selector line, so a
			// multi-line list (`.card-title,\n.card-note{…}`) scored only
			// `.card-note`. Strip a leading @import/@charset statement (the
			// case the last-line hack existed for -- it shares this capture
			// with the real selector below it) instead, and keep the FULL
			// selector list.
			$sel = trim( (string) preg_replace( '/^.*;\s*/s', '', trim( $match[1][0] ) ) );
			if ( '' === $sel || '@' === $sel[0] ) {
				continue;
			}

			// The INNERMOST enclosing at-rule wins: `@supports{@media print{…}}`
			// is print, not merely "supported".
			$offset  = $match[1][1];
			$at      = '';
			$tightest = PHP_INT_MAX;
			foreach ( $spans as $span ) {
				if ( $offset > $span['start'] && $offset < $span['end'] ) {
					$width = $span['end'] - $span['start'];
					if ( $width < $tightest ) {
						$tightest = $width;
						$at       = $span['at'];
					}
				}
			}
			if ( sn_health_contrast_usage_is_print_only( $at ) ) {
				continue;
			}

			$rules[] = array(
				'sel'  => $sel,
				'body' => $match[2][0],
				'at'   => $at,
			);
		}
	}
	return $rules;
}

/**
 * Read one colour declaration as either a token slug or a literal hex.
 *
 * Returns array{kind:'token'|'literal', value:string} or null when the value
 * is not scoreable — `transparent`, `inherit`, `currentColor`, `color-mix()`
 * and friends. Skipping those is not laziness: a component whose colour is
 * `currentColor` has no colour of its own to score, and guessing one is how a
 * report starts inventing defects.
 *
 * @param string $body     Declaration block.
 * @param string $property 'color' or 'background'.
 * @return array{kind:string,value:string}|null
 */
function sn_health_contrast_usage_read_color( $body, $property ) {
	$prop = 'background' === $property ? 'background(?:-color)?' : '(?<![a-z-])color';

	// The optional `(?:,[^;]*)?` is the var() FALLBACK, matched and then thrown
	// away. `var(--wp--preset--color--void, #fff)` is the only form that is both
	// safe and scoreable — safe because it still paints when the preset is
	// undefined, scoreable because this scan can see the token. Demanding a bare
	// `var(--token)` forced a component to pick one or the other.
	//
	// The TOKEN wins, always. Every sheet sn_health_contrast_usage_sources()
	// reads loads in theme context, where the presets ARE defined, so the
	// fallback is the branch no reader takes — scoring it would report a colour
	// nobody sees. `[^;]*` is bounded by the declaration separator so the match
	// can never run into the next declaration, and it backtracks correctly over
	// a nested `var()` in the fallback.
	if ( preg_match( '/' . $prop . ':\s*var\(\s*--wp--preset--color--([a-z0-9-]+)\s*(?:,[^;]*)?\)/i', (string) $body, $m ) ) {
		return array(
			'kind'  => 'token',
			'value' => strtolower( $m[1] ),
		);
	}
	if ( preg_match( '/' . $prop . ':\s*(#[0-9a-f]{3,8})\b/i', (string) $body, $m ) ) {
		$hex = sn_health_normalize_hex( $m[1] );
		if ( '' !== $hex ) {
			return array(
				'kind'  => 'literal',
				'value' => $hex,
			);
		}
	}
	return null;
}

