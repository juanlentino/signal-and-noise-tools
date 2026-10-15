<?php
/**
 * Signal & Noise: contrast usage scan, the report (one 123-line function, moved whole).
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
