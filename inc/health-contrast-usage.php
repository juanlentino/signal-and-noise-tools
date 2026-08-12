<?php
/**
 * Signal & Noise Tools -- Content Health: the contrast USAGE tier, report only.
 *
 * The second half of what contrast_tokens promises. That check scores every
 * unordered token PAIR and says so in its own coverage sentence: "which pairs
 * ARE rendered together is not measured here". This module measures a large
 * part of that — the pairings actually DECLARED in stylesheets — and hands the
 * result back to the same check, as a second block in the same report. It is
 * deliberately NOT a nineteenth health check: the panel already exists, the
 * owner already reads it, and the honest fix for a check that declares itself
 * incomplete is to complete it rather than to sit a rival beside it.
 *
 * WHY THIS TIER EXISTS AT ALL (theme v11.7.0, 2026-08-11). The theme's own
 * suite reported 20 passed / 0 failed while the live site carried real AA
 * failures, because it scored the palette in theme.json and THE SITE SERVES A
 * STYLE VARIATION. blood-on-asphalt is 4.60:1 at root and 3.80:1 under High
 * Contrast. Every number was right; the palette was the wrong one. So this
 * scan scores under EVERY palette the site can present, and names them.
 *
 * WHAT IT COVERS, precisely, because a coverage sentence that overclaims is
 * worse than no coverage sentence:
 *   - Pairings DECLARED in CSS: a rule setting `color`, resolved against the
 *     background of the nearest enclosing rule that sets one.
 *   - Both token references (`var(--wp--preset--color--rust)`) AND literal
 *     hexes. The literal half is not an afterthought: the plugin's own
 *     provenance chip is entirely hardcoded, so a token-only scan — which is
 *     what the theme-side original was — would not see the component this
 *     module was written to catch.
 *   - Token references CARRYING A FALLBACK (`var(--wp--preset--color--void,
 *     #fff)`), scored as the token. Before that the regex demanded a
 *     bare `var(--token)`, which made the safe form and the scoreable form
 *     mutually exclusive: a component could paint reliably when the preset was
 *     missing, or be visible to this scan, never both. The fallback itself is
 *     deliberately NOT scored — every sheet listed by
 *     sn_health_contrast_usage_sources() loads in theme context, where the
 *     presets are defined, so the fallback is the branch no reader takes.
 * WHAT IT DOES NOT COVER:
 *   - INDIRECTION through a non-preset custom property. This plugin's own
 *     sheets do `--sn-signal: var(--wp--preset--color--signal,#ff4c47)` and then
 *     `color: var(--sn-signal)`; the scan sees a definition it does not score
 *     and a reference it cannot resolve, so the pairing is invisible rather than
 *     wrong. Resolving it means following the cascade, which is the render
 *     tier's job. A non-preset var is never guessed from its fallback: when the
 *     property IS defined, the fallback is not what renders, and inventing a
 *     colour is the failure mode this whole module exists to avoid.
 *   - Colours inlined in BLOCK MARKUP (`has-blood-color` on a paragraph).
 *     Those live in templates, not stylesheets. Still the render tier's job.
 *   - The computed cascade: specificity, overrides, inherited `color`.
 *   - Non-resting states. See the note on sn_health_contrast_usage_surfaces().
 *
 * @package SignalNoiseTools
 * @since 10.90.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cap on reported pairings, mirroring SN_HEALTH_CONTRAST_MAX_ROWS. Worst-first,
 * so the tail is the safe end.
 */
const SN_HEALTH_CONTRAST_USAGE_MAX_ROWS = 25;

/**
 * Stylesheets to scan: this plugin's FRONT-END css plus the active theme's.
 *
 * Admin sheets are excluded on purpose — wp-admin supplies its own palette and
 * its own background, so scoring admin rules against theme tokens would invent
 * failures that no reader can meet.
 *
 * @return array<string,string> label => absolute path.
 */
function sn_health_contrast_usage_sources() {
	$sources = array();

	foreach ( (array) glob( SNT_PATH . 'assets/*.css' ) as $file ) {
		$base = basename( (string) $file );
		if ( false !== strpos( $base, 'admin' ) ) {
			continue;
		}
		$sources[ 'plugin/' . $base ] = (string) $file;
	}

	if ( function_exists( 'get_stylesheet_directory' ) ) {
		$theme_root = (string) get_stylesheet_directory();
		foreach ( array( '/assets/css/*.css', '/blocks/*/style.css', '/style.css' ) as $pattern ) {
			foreach ( (array) glob( $theme_root . $pattern ) as $file ) {
				$sources[ 'theme/' . basename( dirname( (string) $file ) ) . '/' . basename( (string) $file ) ] = (string) $file;
			}
		}
	}

	return $sources;
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
function sn_health_contrast_usage_rules( $css ) {
	$css   = preg_replace( '!/\*.*?\*/!s', '', (string) $css );
	$rules = array();
	if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', (string) $css, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$lines = preg_split( '/\R/', trim( $match[1] ) );
			$sel   = trim( (string) end( $lines ) );
			if ( '' !== $sel && '@' !== $sel[0] ) {
				$rules[] = array(
					'sel'  => $sel,
					'body' => $match[2],
				);
			}
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

/**
 * Selectors that paint a real surface, mapped to their background colour.
 *
 * TWO EXCLUSIONS, both bought with false positives on the theme-side original:
 *
 * 1. PSEUDO-ELEMENTS. `.sn-notes-pillar::before` paints a 4px decorative rail
 *    in blood. Treating that as the card's background scored every child
 *    against blood and produced a screenful of nonsense — "blood on blood,
 *    1.00:1". A surface is an element's own background, not an accent drawn
 *    on top of it.
 *
 * 2. PSEUDO-CLASSES. An earlier cut stripped `:hover` so it could match more
 *    pairs, and promptly paired a hover BACKGROUND with a resting TEXT colour:
 *    `.sn-cmdk-trigger` was reported as bone-on-blood when it is in fact
 *    bone-on-transparent, its blood arriving only on hover along with a
 *    different text colour. ~60 findings, all fictional. State matters, and
 *    modelling it properly is a CSS engine, not a health check. So: resting
 *    state only, and the coverage sentence says so.
 *
 * @param array<int,array{sel:string,body:string}> $rules Parsed rules.
 * @return array<string,array{kind:string,value:string}> selector => colour.
 */
function sn_health_contrast_usage_surfaces( $rules ) {
	$surfaces = array();
	foreach ( $rules as $rule ) {
		if ( false !== strpos( $rule['sel'], ':' ) ) {
			continue;
		}
		$bg = sn_health_contrast_usage_read_color( $rule['body'], 'background' );
		if ( null === $bg ) {
			continue;
		}
		foreach ( explode( ',', $rule['sel'] ) as $one ) {
			$one = trim( $one );
			if ( '' !== $one ) {
				$surfaces[ $one ] = $bg;
			}
		}
	}
	return $surfaces;
}

/**
 * Does $selector sit inside $surface?
 *
 * Containment by selector text: the same element, a descendant, or a BEM-ish
 * child (`.card` owning `.card-title`). Crude, and deliberately so — anything
 * cleverer needs the DOM.
 *
 * @param string $selector Text rule's selector.
 * @param string $surface  Surface rule's selector.
 * @return bool
 */
function sn_health_contrast_usage_contains( $selector, $surface ) {
	return $selector === $surface
		|| 0 === strpos( $selector, $surface . ' ' )
		|| 0 === strpos( $selector, $surface . '-' )
		|| false !== strpos( $selector, ' ' . $surface . ' ' );
}

/**
 * Every declared text-on-surface pairing in one stylesheet.
 *
 * Text with no enclosing surface is scored against the DOCUMENT background,
 * not skipped and not scored against every token in the palette. This is the
 * case that mattered: the provenance chip sets no background of its own, so
 * its contrast is a property of PLACEMENT rather than of the component — which
 * is exactly why the arithmetic tier cannot see it, and why an earlier
 * theme-side version missed it too (it required an enclosing surface, and a
 * component that has none simply fell out of the scan). The document
 * background is where such a component actually lands by default. A component
 * placed on some OTHER surface is still the render tier's problem, and the
 * coverage sentence says so rather than pretending otherwise.
 *
 * @param array<int,array{sel:string,body:string}>       $rules      Parsed rules.
 * @param array<string,array{kind:string,value:string}>  $surfaces   Surface map.
 * @param array{kind:string,value:string}                $document   Document background.
 * @param string                                         $label      Source label.
 * @return array<int,array<string,mixed>>
 */
function sn_health_contrast_usage_pairings( $rules, $surfaces, $document, $label ) {
	// Longest selector first, so a child's own surface beats an ancestor's.
	uksort( $surfaces, function ( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	} );

	$pairings = array();
	foreach ( $rules as $rule ) {
		if ( false !== strpos( $rule['sel'], ':' ) ) {
			continue;
		}
		$fg = sn_health_contrast_usage_read_color( $rule['body'], 'color' );
		if ( null === $fg ) {
			continue;
		}

		$bg       = sn_health_contrast_usage_read_color( $rule['body'], 'background' );
		$anchored = true;
		if ( null === $bg ) {
			$bg = null;
			foreach ( $surfaces as $surface => $colour ) {
				if ( sn_health_contrast_usage_contains( $rule['sel'], $surface ) ) {
					$bg = $colour;
					break;
				}
			}
		}
		if ( null === $bg ) {
			$bg       = $document;
			$anchored = false;
		}
		if ( null === $bg || ( $fg['kind'] === $bg['kind'] && $fg['value'] === $bg['value'] ) ) {
			continue;
		}

		$pairings[] = array(
			'selector' => $rule['sel'],
			'source'   => $label,
			'fg'       => $fg,
			'bg'       => $bg,
			'anchored' => $anchored,
		);
	}
	return $pairings;
}

/**
 * Resolve a colour under one palette. Literals are palette-invariant, which is
 * itself worth seeing: a hardcoded hex renders identically under every
 * variation, so a monochrome variation still gets a red chip. That is a
 * fidelity bug wearing a contrast bug's clothes, and the `kind` column is
 * what makes it legible.
 *
 * @param array{kind:string,value:string} $colour  Colour.
 * @param array<string,string>            $palette slug => '#rrggbb'.
 * @return string|null '#rrggbb' or null when the token is absent.
 */
function sn_health_contrast_usage_resolve( $colour, $palette ) {
	if ( 'literal' === $colour['kind'] ) {
		return $colour['value'];
	}
	return isset( $palette[ $colour['value'] ] ) ? $palette[ $colour['value'] ] : null;
}

/**
 * Every palette the site can present: the SERVED one first, then each shipped
 * style variation.
 *
 * The served palette leads because it is what readers meet today — and because
 * assuming theme.json was the served palette is the exact mistake this whole
 * tier exists to prevent. Variations follow because a reader can be shown one
 * at any time, and a pairing that passes at root while failing under a shipped
 * variation is a live defect for whoever is looking at that variation.
 *
 * Each variation is merged OVER the served palette: a variation that redefines
 * only `rust` inherits the rest, and reading it alone would give it a
 * one-colour palette that silently drops out of scoring.
 *
 * @return array<string,array<string,string>> label => (slug => '#rrggbb').
 */
function sn_health_contrast_usage_palettes() {
	$served = sn_health_contrast_named_palette();
	if ( empty( $served ) ) {
		return array();
	}

	$palettes = array( 'served (active style)' => $served );

	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! method_exists( 'WP_Theme_JSON_Resolver', 'get_style_variations' ) ) {
		return $palettes;
	}

	foreach ( (array) WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
		if ( ! is_array( $variation ) ) {
			continue;
		}
		$entries = $variation['settings']['color']['palette']['theme']
			?? $variation['settings']['color']['palette']
			?? array();
		if ( ! is_array( $entries ) || empty( $entries ) ) {
			continue;
		}

		$merged = $served;
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) ) {
				$hex = sn_health_normalize_hex( (string) $entry['color'] );
				if ( '' !== $hex ) {
					$merged[ (string) $entry['slug'] ] = $hex;
				}
			}
		}

		$title = isset( $variation['title'] ) ? (string) $variation['title'] : '';
		if ( '' === $title || $merged === $served ) {
			continue;
		}
		$palettes[ 'variation: ' . $title ] = $merged;
	}

	return $palettes;
}

/**
 * The document background — the surface unanchored text lands on.
 *
 * Read from global styles rather than assumed, because the whole premise of
 * this tier is that assuming a palette value is how the last false green
 * happened.
 *
 * @return array{kind:string,value:string}|null
 */
function sn_health_contrast_usage_document_background() {
	if ( ! function_exists( 'wp_get_global_styles' ) ) {
		return null;
	}
	$styles = wp_get_global_styles( array( 'color', 'background' ) );
	$value  = is_string( $styles ) ? $styles : '';
	if ( '' === $value ) {
		return null;
	}
	// Same fallback tolerance as sn_health_contrast_usage_read_color(). These two
	// regexes are one concept written twice; widening only the other would leave
	// the surface that every unanchored pairing is scored AGAINST blind to the
	// syntax its own stylesheets had just been cleared to use.
	if ( preg_match( '/var\(\s*--wp--preset--color--([a-z0-9-]+)\s*(?:,[^;]*)?\)/i', $value, $m ) ) {
		return array(
			'kind'  => 'token',
			'value' => strtolower( $m[1] ),
		);
	}
	$hex = sn_health_normalize_hex( $value );
	return '' === $hex ? null : array(
		'kind'  => 'literal',
		'value' => $hex,
	);
}

/**
 * The usage scan: every declared pairing, scored under every shipped palette,
 * worst-first.
 *
 * A pairing is reported once per palette it fails under, because "fails under
 * High Contrast" and "fails everywhere" are different facts and collapsing
 * them loses the one that tells you whether readers are affected today.
 *
 * @return array<string,mixed> report block.
 */
function sn_health_contrast_usage_report() {
	$palettes = sn_health_contrast_usage_palettes();
	$document = sn_health_contrast_usage_document_background();
	$sources  = sn_health_contrast_usage_sources();

	if ( empty( $palettes ) || null === $document || empty( $sources ) ) {
		return array(
			'scanned'     => 0,
			'pairings'    => 0,
			'failures'    => array(),
			'conditional' => array(),
			'palettes'    => array_keys( $palettes ),
		);
	}

	$pairings    = array();
	$scanned     = 0;
	$inheritable = array();
	foreach ( $sources as $label => $path ) {
		$css = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme/plugin asset, not a remote fetch.
		if ( '' === $css ) {
			continue;
		}
		$scanned++;
		$rules    = sn_health_contrast_usage_rules( $css );
		$surfaces = sn_health_contrast_usage_surfaces( $rules );
		$pairings = array_merge( $pairings, sn_health_contrast_usage_pairings( $rules, $surfaces, $document, $label ) );

		// The surfaces a backgroundless component could land on. Drawn from
		// what the design system ACTUALLY paints rather than from the whole
		// palette — "could fail on some token nobody uses as a background" is
		// the arithmetic tier's kind of noise, and this tier exists to not be
		// that.
		foreach ( $surfaces as $colour ) {
			$inheritable[ $colour['kind'] . ':' . $colour['value'] ] = $colour;
		}
	}

	$failures = array();
	foreach ( $pairings as $pairing ) {
		foreach ( $palettes as $palette_label => $palette ) {
			$fg = sn_health_contrast_usage_resolve( $pairing['fg'], $palette );
			$bg = sn_health_contrast_usage_resolve( $pairing['bg'], $palette );
			if ( null === $fg || null === $bg ) {
				continue;
			}
			$ratio = sn_health_contrast_ratio( $fg, $bg );
			if ( null === $ratio || $ratio >= SN_HEALTH_CONTRAST_AA_BODY ) {
				continue;
			}
			$failures[] = array(
				'selector' => $pairing['selector'],
				'source'   => $pairing['source'],
				'palette'  => $palette_label,
				'pair'     => $pairing['fg']['value'] . ' on ' . $pairing['bg']['value'],
				'literal'  => 'literal' === $pairing['fg']['kind'] || 'literal' === $pairing['bg']['kind'],
				'anchored' => $pairing['anchored'],
				'ratio'    => round( $ratio, 2 ),
			);
		}
	}

	// CONDITIONAL failures: a backgroundless component clears the page
	// background but would fail on another surface this design system actually
	// paints. Kept OUT of the headline count deliberately — whether such a
	// component is ever placed there is a question no stylesheet can answer, so
	// counting it as a live defect would repeat the arithmetic tier's mistake
	// one level down. Reported separately because it is still the cheapest
	// warning available: the provenance chip's `muted` state passes on white at
	// 4.83:1 and fails on the served asphalt at 3.66:1, and that is precisely
	// the kind of thing that ships unnoticed.
	$definite   = array();
	foreach ( $failures as $row ) {
		$definite[ $row['selector'] . '|' . $row['palette'] ] = true;
	}

	$conditional = array();
	foreach ( $pairings as $pairing ) {
		if ( $pairing['anchored'] ) {
			continue;
		}
		foreach ( $palettes as $palette_label => $palette ) {
			if ( isset( $definite[ $pairing['selector'] . '|' . $palette_label ] ) ) {
				continue;
			}
			$fg = sn_health_contrast_usage_resolve( $pairing['fg'], $palette );
			if ( null === $fg ) {
				continue;
			}
			foreach ( $inheritable as $surface ) {
				$bg = sn_health_contrast_usage_resolve( $surface, $palette );
				if ( null === $bg || $bg === $fg ) {
					continue;
				}
				$ratio = sn_health_contrast_ratio( $fg, $bg );
				if ( null === $ratio || $ratio >= SN_HEALTH_CONTRAST_AA_BODY ) {
					continue;
				}
				$conditional[] = array(
					'selector' => $pairing['selector'],
					'source'   => $pairing['source'],
					'palette'  => $palette_label,
					'pair'     => $pairing['fg']['value'] . ' on ' . $surface['value'],
					'ratio'    => round( $ratio, 2 ),
				);
			}
		}
	}

	$sort_by_ratio = function ( $a, $b ) {
		return $a['ratio'] <=> $b['ratio'];
	};
	usort( $failures, $sort_by_ratio );
	usort( $conditional, $sort_by_ratio );

	return array(
		'scanned'     => $scanned,
		'pairings'    => count( $pairings ),
		'failures'    => $failures,
		'conditional' => $conditional,
		'palettes'    => array_keys( $palettes ),
	);
}
