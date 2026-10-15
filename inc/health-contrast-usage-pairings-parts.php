<?php
/**
 * Signal & Noise: contrast usage scan, the pairings (the surfaces, the selector containment test, the pairings, the color resolver, the palettes, the document background).
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
		// The surface carries the at-rule context it was declared under, so a
		// pairing can refuse a background that cannot co-occur with its colour.
		$bg['at'] = isset( $rule['at'] ) ? $rule['at'] : '';
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
				if ( ! sn_health_contrast_usage_contains( $rule['sel'], $surface ) ) {
					continue;
				}
				// An UNCONDITIONAL surface always applies, so it can back a
				// conditional colour. A surface declared under a DIFFERENT
				// at-rule cannot: `@media (max-width:600px)` and
				// `@media (min-width:601px)` never hold at once, and pairing
				// across them invents a combination that renders nowhere.
				// Equality is deliberately literal — proving two media queries
				// overlap means evaluating them, which this scan does not do,
				// and guessing is how a report starts inventing defects.
				$surface_at = isset( $colour['at'] ) ? $colour['at'] : '';
				$rule_at    = isset( $rule['at'] ) ? $rule['at'] : '';
				if ( '' !== $surface_at && $surface_at !== $rule_at ) {
					continue;
				}
				$bg = array(
					'kind'  => $colour['kind'],
					'value' => $colour['value'],
				);
				break;
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

