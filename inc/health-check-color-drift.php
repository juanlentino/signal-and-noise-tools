<?php
/**
 * Signal & Noise Tools -- Content Health check: color drift.
 *
 * Check 6: palette color drift -- inline hex/rgb colors in post_content outside the theme.json allowed palette.
 *
 * Split VERBATIM out of inc/health-checks.php in v9.81.0 (mirroring the
 * analytics-render-*.php split); every function name is unchanged. Loaded
 * by the inc/health-checks.php orchestrator, which owns the shared
 * constants and sn_health_pack_check().
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize a CSS hex color: lowercase, 3-digit expanded to 6. '' for anything
 * that is not a #hex color (named colors, rgb(), malformed).
 *
 * @param string $color Raw color token.
 * @return string '#rrggbb' or ''.
 */
function sn_health_normalize_hex( $color ) {
	$c = strtolower( trim( (string) $color ) );
	if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $c, $m ) ) {
		return '';
	}
	$h = $m[1];
	if ( 3 === strlen( $h ) ) {
		$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
	}
	return '#' . $h;
}

/**
 * The allowed palette as a normalized hex set, read DEFENSIVELY from
 * wp_get_global_settings(): merged data presents either a FLAT entry list (the
 * shape the theme's design-tokens ability reads) or an ORIGIN-KEYED array.
 * When keyed, only theme+custom origins count — the theme sets
 * defaultPalette:false, so core-default colors are drift here.
 *
 * @return array<string,true> Set keyed by '#rrggbb'.
 */
function sn_health_allowed_palette_hexes() {
	// UNION across every palette the theme serves — the opposite treatment from
	// the contrast panel, and for the opposite reason. A dark-palette hex IS a
	// theme colour, so flagging it as drift is a false positive; here the seven
	// shared slugs are irrelevant because this is a membership SET keyed by hex,
	// not by slug, so nothing can overwrite anything. (The contrast panel must
	// NOT do this: scoring needs the palettes kept apart.)
	//
	// Guarded: the accessor lives in inc/health-contrast-tokens.php, which the
	// loader pulls in alongside this file, but color_drift is also exercised in
	// isolation by its own suite.
	if ( function_exists( 'sn_health_theme_palettes' ) ) {
		$union = array();
		foreach ( sn_health_theme_palettes() as $scheme_named ) {
			foreach ( (array) $scheme_named as $hex ) {
				$norm = sn_health_normalize_hex( (string) $hex );
				if ( '' !== $norm ) {
					$union[ $norm ] = true;
				}
			}
		}
		if ( ! empty( $union ) ) {
			return $union;
		}
	}

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
	$allowed = array();
	foreach ( $entries as $entry ) {
		if ( is_array( $entry ) && isset( $entry['color'] ) ) {
			$hex = sn_health_normalize_hex( (string) $entry['color'] );
			if ( '' !== $hex ) {
				$allowed[ $hex ] = true;
			}
		}
	}
	return $allowed;
}

/**
 * Zero-AI color-drift check (v7.3.0, the v4.1.0-era "cheap zero-AI check"):
 * published posts/pages whose content carries inline hex colors outside the
 * theme palette. Read-only; the fix is editorial (use palette presets) or a
 * deliberate theme.json addition.
 *
 * @return array Packed check (sn_health_pack_check shape).
 */
function sn_health_check_color_drift() {
	$label    = 'Color drift';
	$fix_hint = 'Replace inline hex colors with theme palette presets, or add the color to theme.json if it is genuinely part of the design system.';

	$allowed = sn_health_allowed_palette_hexes();
	if ( empty( $allowed ) ) {
		// Declared, not narrated: the reason used to live only in this prose,
		// where the tally could not see it and counted the check as passed.
		return sn_health_pack_check( $label, array(), 'Theme palette unavailable: skipping (never flags everything on a missing palette). ' . $fix_hint, 'theme palette unavailable' );
	}

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_content
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_content REGEXP '#[0-9a-fA-F]{3}'
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	$findings = array();
	foreach ( $rows as $r ) {
		// v7.3.1: inline SVG figures are ARTWORK, not text styling — their
		// fills/strokes (grayscale tones, semantic diagram red/green) are
		// deliberate and flagged every diagram-carrying post as permanent
		// drift (alarm fatigue). Strip <svg>…</svg> spans before extracting
		// hexes so the check stays about prose/styling drift. Non-greedy per
		// block; nested <svg> inside <svg> would leave the outer tail, which
		// only risks a FLAGGED nested tail (never hides prose drift outside).
		$content = (string) preg_replace( '#<svg\b[^>]*>.*?</svg\s*>#is', '', (string) $r['post_content'] );
		// v9.21.2: numeric HTML character references are NOT hex colors, but the
		// hex regex below reads the "#039" inside "&#039;" (esc_html'd apostrophe)
		// as the 3-digit hex #039 → #003399, phantom-flagging every clean post
		// whose prose has an apostrophe (the live /now dossier: "this site&#039;s
		// theme"). Same class: &#160; (nbsp), &#233; (é), any 3-digit &#NNN;.
		// Strip decimal (&#NNN;) and hex (&#xNN;) references before extraction;
		// real inline hexes (style="color:#039") carry no leading &/trailing ;.
		$content = (string) preg_replace( '/&#(?:x[0-9a-f]+|[0-9]+);/i', ' ', $content );
		if ( ! preg_match_all( '/#(?:[0-9a-f]{6}|[0-9a-f]{3})\b/i', $content, $m ) ) {
			continue;
		}
		$offending = array();
		foreach ( array_unique( $m[0] ) as $raw ) {
			$hex = sn_health_normalize_hex( $raw );
			if ( '' !== $hex && ! isset( $allowed[ $hex ] ) ) {
				$offending[ $hex ] = true;
			}
		}
		if ( empty( $offending ) ) {
			continue;
		}
		$findings[] = array(
			'subject_type'  => 'post',
			'subject_id'    => (int) $r['ID'],
			'subject_url'   => (string) get_permalink( (int) $r['ID'] ),
			'subject_label' => (string) $r['post_title'],
			'edit_url'      => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
			'note'          => 'Off-palette inline colors: ' . implode( ', ', array_slice( array_keys( $offending ), 0, 8 ) ) . '.',
		);
	}
	return sn_health_pack_check( $label, $findings, $fix_hint );
}
