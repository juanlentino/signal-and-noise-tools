<?php
/**
 * Signal & Noise Tools -- Content Health check: token-level contrast, REPORT ONLY.
 *
 * The Accessibility planned row's first half: "Contrast audited at the token
 * level ... landing report-first, findings published before any fix ships."
 * R3 owns fixes; this check deliberately raises ZERO findings — its whole
 * output is the `report` payload: every unordered pair of theme palette
 * tokens with its WCAG 2.2 contrast ratio and the AA verdicts at both
 * thresholds (4.5:1 body text, 3:1 large text / UI components).
 *
 * THE COVERAGE HONESTY (docs/r2-prep.md §2C, and the trap that already bit
 * this repo — a rule's presence is not its application): this is the
 * ARITHMETIC tier. It answers "which token pairs would fail if rendered
 * together", not "which failing pairs a reader actually sees" — block
 * templates inline their own colours, so the rendered-pair tier needs
 * computed styles from a real render (the headless harness), which this
 * check does not run. The report SAYS SO in its own `coverage` field, so a
 * clean arithmetic sweep can never be mistaken for a clean site, and a
 * failing pair here is "would fail", never "fails".
 *
 * Pure math helpers first (testable with hand-derived ratios, per the prep
 * doc: the test pins known values, never recomputes them with the code
 * under test), palette read reusing the color-drift check's origin logic
 * but keeping slugs — a token-level report that says "#333333 on #e00404"
 * instead of "rust on blood" is a hex dump, not an audit.
 *
 * @package SignalNoiseTools
 * @since 10.82.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_HEALTH_CONTRAST_AA_BODY  = 4.5;
const SN_HEALTH_CONTRAST_AA_LARGE = 3.0;

/**
 * WCAG 2.x relative luminance of a '#rrggbb' color. Channels are linearized
 * per the spec's sRGB transfer curve, then weighted 0.2126/0.7152/0.0722.
 *
 * @param string $hex Normalized '#rrggbb' (sn_health_normalize_hex output).
 * @return float|null Luminance in [0,1], or null for a malformed input.
 */
function sn_health_relative_luminance( $hex ) {
	if ( ! preg_match( '/^#[0-9a-f]{6}$/', (string) $hex ) ) {
		return null;
	}
	$weights = array( 0.2126, 0.7152, 0.0722 );
	$sum     = 0.0;
	for ( $i = 0; $i < 3; $i++ ) {
		$channel = hexdec( substr( $hex, 1 + 2 * $i, 2 ) ) / 255.0;
		$linear  = $channel <= 0.04045 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
		$sum    += $weights[ $i ] * $linear;
	}
	return $sum;
}

/**
 * WCAG contrast ratio between two colors: (L_lighter + 0.05)/(L_darker + 0.05),
 * in [1, 21]. Order-independent.
 *
 * @param string $hex_a '#rrggbb'.
 * @param string $hex_b '#rrggbb'.
 * @return float|null Ratio, or null when either color is malformed.
 */
function sn_health_contrast_ratio( $hex_a, $hex_b ) {
	$la = sn_health_relative_luminance( $hex_a );
	$lb = sn_health_relative_luminance( $hex_b );
	if ( null === $la || null === $lb ) {
		return null;
	}
	$lighter = max( $la, $lb );
	$darker  = min( $la, $lb );
	return ( $lighter + 0.05 ) / ( $darker + 0.05 );
}

/**
 * The theme palette WITH slugs — the color-drift check's origin traversal
 * (theme + custom only; defaultPalette:false makes core defaults drift, so
 * they are not tokens here either), keeping slug => hex instead of a set.
 *
 * @return array<string,string> slug => '#rrggbb', duplicate hexes kept
 *                              under each slug (aliases like void/white are
 *                              both real names the templates use).
 */
/**
 * EVERY palette the theme serves, keyed by palette IDENTITY.
 *
 * WHY THIS EXISTS. Two Health call sites read
 * `wp_get_global_settings( array( 'color', 'palette' ) )`, which returns the
 * palette WordPress RESOLVED for this request — one palette, whichever is
 * served. That was correct by luck while the theme served one; it has never
 * been able to see the others, and as of theme v12.0.0 it cannot see dark at
 * all, because dark lives in CSS and no global-settings read reaches it.
 *
 * KEYED BY IDENTITY, WITH `scheme` AS A FIELD — corrected in 12.1.1, and the
 * correction is the point. v12.1.0 keyed these `light`/`dark`, which conflates
 * a VARIATION with a SCHEME. High Contrast is a light-scheme variation;
 * dark overrides whichever variation is active. They are orthogonal axes, so a
 * High Contrast reader on a dark OS gets dark, not a blend — and a flat
 * {light, dark, high-contrast} namespace would assert they are alternatives to
 * one another. Identity keys also make a fourth palette additive.
 *
 * THE MERGE TRAP, restated because it is why this returns a map of maps: the
 * palettes share every slug. A flat merge does not union them, it OVERWRITES —
 * you would score one palette's values believing you scored the theme. And a
 * pair drawn across palettes never co-occurs on a screen. Consumers that SCORE
 * must iterate; only a consumer wanting a membership SET (color_drift's allowed
 * hexes) may flatten, because that set is keyed by hex and cannot collide.
 *
 * DELIBERATELY NOT MEMOIZED — a static cache served a stale palette to callers
 * that legitimately re-read after global settings change. The theme's own
 * accessor holds its cache; core caches wp_get_global_settings().
 *
 * @since 12.1.0
 * @return array<string,array{scheme:string,source:string,colors:array<string,string>}>
 */
function sn_health_theme_palettes() {
	// The theme knows its own palettes — including the two no WordPress read can
	// reach: a style variation's overrides, and the dark layer that lives in CSS.
	if ( function_exists( 'sn_theme_all_palettes' ) ) {
		$out = array();
		foreach ( (array) sn_theme_all_palettes() as $id => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['colors'] ) || ! is_array( $entry['colors'] ) ) {
				continue;
			}
			$colors = array();
			foreach ( $entry['colors'] as $slug => $hex ) {
				$norm = sn_health_normalize_hex( (string) $hex );
				if ( '' !== $norm ) {
					$colors[ (string) $slug ] = $norm;
				}
			}
			if ( empty( $colors ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'scheme' => (string) ( $entry['scheme'] ?? '' ),
				'source' => (string) ( $entry['source'] ?? '' ),
				'colors' => $colors,
			);
		}
		if ( ! empty( $out ) ) {
			return $out;
		}
	}

	// Theme absent or older than v12.0.0. All we have is what WordPress
	// resolved, and we do NOT know which palette that is or what scheme it
	// belongs to — so it is keyed `resolved` and its scheme is empty. Naming it
	// `light` (as 12.1.0 did) asserts something unmeasured; if the served
	// palette is a variation, that label is simply wrong.
	$resolved = sn_health_contrast_named_palette();
	if ( empty( $resolved ) ) {
		return array();
	}
	return array(
		'resolved' => array(
			'scheme' => '',
			'source' => 'wp_get_global_settings',
			'colors' => $resolved,
		),
	);
}

/**
 * Which palette the site is actually serving, when the theme can say.
 *
 * @since 12.1.1
 * @return string Palette id, 'custom', or '' when it cannot be determined.
 */
function sn_health_served_palette_id() {
	if ( function_exists( 'sn_theme_served_palette_id' ) ) {
		return (string) sn_theme_served_palette_id();
	}
	return '';
}

function sn_health_contrast_named_palette() {
	if ( ! function_exists( 'wp_get_global_settings' ) ) {
		return array();
	}
	$palette = wp_get_global_settings( array( 'color', 'palette' ) );
	if ( ! is_array( $palette ) ) {
		return array();
	}
	$entries = array();
	if ( isset( $palette['theme'] ) || isset( $palette['custom'] ) || isset( $palette['default'] ) ) {
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			if ( isset( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
				$entries = array_merge( $entries, $palette[ $origin ] );
			}
		}
	} else {
		$entries = $palette;
	}
	$named = array();
	foreach ( $entries as $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['color'], $entry['slug'] ) ) {
			continue;
		}
		$hex = sn_health_normalize_hex( (string) $entry['color'] );
		if ( '' !== $hex ) {
			$named[ (string) $entry['slug'] ] = $hex;
		}
	}
	return $named;
}

/**
 * The pair table: every unordered token pair, ratio rounded to 2 decimals,
 * AA verdicts at both thresholds (computed on the UNROUNDED ratio — 4.4954
 * must fail body AA even though it displays as 4.50), sorted worst-first so
 * the reader meets the risk before the reassurance.
 *
 * @param array<string,string> $named slug => '#rrggbb'.
 * @return array<int,array{pair:string,ratio:float,aa_body:bool,aa_large:bool}>
 */
function sn_health_contrast_pair_table( $named ) {
	$slugs = array_keys( (array) $named );
	$rows  = array();
	$n     = count( $slugs );
	for ( $i = 0; $i < $n; $i++ ) {
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$ratio = sn_health_contrast_ratio( $named[ $slugs[ $i ] ], $named[ $slugs[ $j ] ] );
			if ( null === $ratio ) {
				continue;
			}
			$rows[] = array(
				'pair'     => $slugs[ $i ] . ' / ' . $slugs[ $j ],
				'ratio'    => round( $ratio, 2 ),
				'aa_body'  => $ratio >= SN_HEALTH_CONTRAST_AA_BODY,
				'aa_large' => $ratio >= SN_HEALTH_CONTRAST_AA_LARGE,
			);
		}
	}
	usort( $rows, function ( $a, $b ) {
		return $a['ratio'] <=> $b['ratio'];
	} );
	return $rows;
}

/**
 * The report-only check. ZERO findings by design — R3 owns fixes, and an
 * arithmetic "fail" is a would-fail, not a reader-facing defect. The report
 * rides the packed check as extra keys (the pack helper's shape is open).
 *
 * @return array Packed check + report{coverage, pairs, tokens, would_fail_body}.
 */
function sn_health_check_contrast_tokens() {
	// Score EACH palette on its own. Not merged: light and dark share all seven
	// slugs, so a flat merge overwrites rather than unions, and a pair drawn
	// across palettes never co-occurs on screen. See sn_health_theme_palettes().
	$palettes   = sn_health_theme_palettes();
	$by_palette = array();
	$would_fail = 0;

	foreach ( $palettes as $palette_id => $palette ) {
		$scheme_named = isset( $palette['colors'] ) ? $palette['colors'] : array();
		$scheme_pairs = sn_health_contrast_pair_table( $scheme_named );
		$scheme_fail  = 0;
		foreach ( $scheme_pairs as $row ) {
			if ( ! $row['aa_body'] ) {
				$scheme_fail++;
			}
		}
		$by_palette[ $palette_id ] = array(
			'scheme'          => isset( $palette['scheme'] ) ? $palette['scheme'] : '',
			'source'          => isset( $palette['source'] ) ? $palette['source'] : '',
			'tokens'          => $scheme_named,
			'pairs'           => $scheme_pairs,
			'would_fail_body' => $scheme_fail,
		);
		$would_fail += $scheme_fail;
	}

	// Top-level `tokens`/`pairs` follow the SERVED palette — the one a reader
	// actually sees. 12.1.0 pointed them at a palette it called `light`, which
	// on a site running a style variation is not what is on screen. Falls back
	// to the first entry when the theme cannot say which is served.
	$served = sn_health_served_palette_id();
	$primary = ( '' !== $served && isset( $by_palette[ $served ] ) )
		? $served
		: (string) array_key_first( $by_palette );
	$named = isset( $by_palette[ $primary ]['tokens'] ) ? $by_palette[ $primary ]['tokens'] : array();
	$pairs = isset( $by_palette[ $primary ]['pairs'] ) ? $by_palette[ $primary ]['pairs'] : array();

	$packed = sn_health_pack_check(
		'Contrast (token arithmetic, report only)',
		array(), // report-only: findings land in R3, with the rendered-pair tier.
		'Report only — no action from this check. Fixes are a later, separate step, taken against pairs a reader actually sees.'
	);

	$packed['report'] = array(
		// The coverage sentence IS the contract. It used to end "until that tier
		// exists a clean sweep here is not a clean site" — half of that tier now
		// exists, below, so the sentence says which half and what is still missing.
		'coverage'        => 'Arithmetic tier: every theme-token pair scored as WOULD-fail/pass if rendered together — a red row here is a "would fail", not a live defect, because nothing here knows which pairs meet on screen. The usage tier below answers that for pairings declared in stylesheets. Colours inlined in block markup and the computed cascade still need a real render, so a clean sweep across both tiers is still not proof of a clean site.',
		'thresholds'      => array( 'aa_body' => SN_HEALTH_CONTRAST_AA_BODY, 'aa_large' => SN_HEALTH_CONTRAST_AA_LARGE ),
		'tokens'          => $named,
		'pairs'           => $pairs,
		'would_fail_body' => $would_fail,
		// Coverage, stated rather than implied. `palettes_complete` is FALSE when
		// the theme did not supply its dark palette (absent, or older than
		// v12.0.0) — the reader is told the sweep is partial instead of reading a
		// clean panel as a clean site. Same rule as v11.33.0's skipped checks: a
		// palette that could not be measured is not a palette that passed.
		'by_palette'        => $by_palette,
		'palettes_measured' => count( $by_palette ),
		// Complete only when the THEME enumerated its palettes. The fallback can
		// see exactly one and cannot know what it missed, so it must not claim a
		// full sweep — v11.33.0's rule that what could not be measured is not
		// something that passed.
		'palettes_complete' => ! isset( $by_palette['resolved'] ) && ! empty( $by_palette ),
		'served'            => $served,
		'usage'             => sn_health_contrast_usage_report(),
	);

	return $packed;
}
