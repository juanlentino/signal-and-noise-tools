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
 * EVERY palette the theme serves, keyed by scheme.
 *
 * WHY THIS EXISTS. Two Health call sites read
 * `wp_get_global_settings( array( 'color', 'palette' ) )`, which returns the
 * ROOT palette — correct while the theme served one, wrong the moment it served
 * two. Theme v12.0.0 adds `:root[data-theme="dark"]`, where all SEVEN slugs are
 * redefined, so the site paints two palettes and Health measured one.
 *
 * THE MERGE TRAP, stated because it is the whole reason this returns a MAP OF
 * MAPS rather than one flat array: light and dark share every slug. A flat
 * merge does not union them, it OVERWRITES — you would score seven dark values
 * believing you scored the theme. And a pair drawn across palettes
 * (light `void` against dark `bone`) never co-occurs on a screen, so scoring it
 * invents failures. Consumers that SCORE must iterate palettes; only a consumer
 * that wants a membership SET (color_drift's allowed hexes) may flatten.
 *
 * The dark palette comes from the theme's own `sn_theme_dark_palette()`, which
 * parses the block that ships it. function_exists-guarded because the theme may
 * be absent or older than v12.0.0 — and when it is, callers must SAY they
 * measured one palette rather than implying completeness. A guard that silently
 * degrades a coverage claim is the bug this whole file is about.
 *
 * DELIBERATELY NOT MEMOIZED. A static cache here served a stale palette to any
 * caller that legitimately re-reads after the global settings change — which
 * the color-drift suite does between assertions, and which a filter could do at
 * runtime. There is nothing to gain: wp_get_global_settings() is cached by core
 * and the theme's own sn_theme_dark_palette() holds its own static.
 *
 * @since 12.1.0
 * @return array<string,array<string,string>> scheme => (slug => '#rrggbb'),
 *                                            always at least 'light'.
 */
function sn_health_theme_palettes() {
	$palettes = array( 'light' => sn_health_contrast_named_palette() );

	if ( function_exists( 'sn_theme_dark_palette' ) ) {
		$dark = sn_theme_dark_palette();
		if ( is_array( $dark ) && ! empty( $dark ) ) {
			$clean = array();
			foreach ( $dark as $slug => $hex ) {
				$norm = sn_health_normalize_hex( (string) $hex );
				if ( '' !== $norm ) {
					$clean[ (string) $slug ] = $norm;
				}
			}
			if ( ! empty( $clean ) ) {
				$palettes['dark'] = $clean;
			}
		}
	}

	return $palettes;
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

	foreach ( $palettes as $scheme => $scheme_named ) {
		$scheme_pairs = sn_health_contrast_pair_table( $scheme_named );
		$scheme_fail  = 0;
		foreach ( $scheme_pairs as $row ) {
			if ( ! $row['aa_body'] ) {
				$scheme_fail++;
			}
		}
		$by_palette[ $scheme ] = array(
			'tokens'          => $scheme_named,
			'pairs'           => $scheme_pairs,
			'would_fail_body' => $scheme_fail,
		);
		$would_fail += $scheme_fail;
	}

	// The light palette stays the top-level `tokens`/`pairs` payload so existing
	// consumers keep working unchanged; the per-palette breakdown is additive.
	$named = isset( $palettes['light'] ) ? $palettes['light'] : array();
	$pairs = isset( $by_palette['light']['pairs'] ) ? $by_palette['light']['pairs'] : array();

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
		'palettes_complete' => isset( $by_palette['dark'] ),
		'usage'             => sn_health_contrast_usage_report(),
	);

	return $packed;
}
