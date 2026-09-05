<?php
/**
 * Signal & Noise Tools — Health check 26 (v13.98.0): theme.json declares a
 * preset the site does not serve.
 *
 * WHY. Since WordPress 6.6, WP_Theme_JSON::merge() drops a THEME-origin
 * preset whose slug collides with a core default unless the family's
 * `default*` flag is false. The theme keeps its declaration; the site serves
 * core's value; nothing warns. This theme served core's geometric spacing
 * scale and core's four named font sizes for its entire life that way, and
 * every visual calibration since was made against values theme.json does not
 * contain (theme #284, fixed in v12.18.9). The theme now has a static guard;
 * this check is the LIVE half: it compares what theme.json declares with what
 * the running site's theme origin actually holds, so a future core default, a
 * theme update, or a child theme can not reopen the gap unnoticed.
 *
 * WHAT. For each preset family core guards with a prevent_override flag,
 * the slugs declared in the active theme's theme.json minus the slugs present
 * in the merged settings' `theme` origin. Any remainder is a finding naming
 * the family, the slugs, and the flag to set.
 *
 * Skips (never a pass): theme.json unreadable, wp_get_global_settings absent,
 * or settings that come back FLAT rather than origin-keyed -- a flat list can
 * not tell theme from default, and pretending it can would be the same
 * mistake this check exists to catch.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The families core guards, as family => [ settings path, flag path ].
 * Mirrors WP_Theme_JSON::PRESETS_METADATA (7.1).
 *
 * @return array<string,array{0:array<int,string>,1:array<int,string>}>
 */
function sn_health_theme_preset_families() {
	return array(
		'color.palette'           => array( array( 'color', 'palette' ), array( 'color', 'defaultPalette' ) ),
		'color.gradients'         => array( array( 'color', 'gradients' ), array( 'color', 'defaultGradients' ) ),
		'color.duotone'           => array( array( 'color', 'duotone' ), array( 'color', 'defaultDuotone' ) ),
		'typography.fontSizes'    => array( array( 'typography', 'fontSizes' ), array( 'typography', 'defaultFontSizes' ) ),
		'spacing.spacingSizes'    => array( array( 'spacing', 'spacingSizes' ), array( 'spacing', 'defaultSpacingSizes' ) ),
		'dimensions.aspectRatios' => array( array( 'dimensions', 'aspectRatios' ), array( 'dimensions', 'defaultAspectRatios' ) ),
		'shadow.presets'          => array( array( 'shadow', 'presets' ), array( 'shadow', 'defaultPresets' ) ),
	);
}

/** @return mixed */
function sn_health_theme_preset_get( $tree, array $path, $default = null ) {
	foreach ( $path as $k ) {
		if ( ! is_array( $tree ) || ! array_key_exists( $k, $tree ) ) {
			return $default;
		}
		$tree = $tree[ $k ];
	}
	return $tree;
}

/** @return array<int,string> */
function sn_health_theme_preset_slugs( $list ) {
	$out = array();
	foreach ( (array) $list as $entry ) {
		if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
			$out[] = (string) $entry['slug'];
		}
	}
	return $out;
}

/**
 * The declared theme.json, parsed; null when unreadable.
 *
 * @return array|null
 */
function sn_health_theme_json_declared() {
	if ( ! function_exists( 'get_stylesheet_directory' ) ) {
		return null;
	}
	$path = rtrim( (string) get_stylesheet_directory(), '/' ) . '/theme.json';
	if ( ! is_readable( $path ) ) {
		return null;
	}
	$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
	return is_array( $decoded ) ? $decoded : null;
}

/**
 * The served theme-origin slugs per family; null when the settings are not
 * origin-keyed (a flat list cannot separate theme from default).
 *
 * @return array<string,array<int,string>>|null family => slugs
 */
function sn_health_theme_presets_served() {
	if ( ! function_exists( 'wp_get_global_settings' ) ) {
		return null;
	}
	$out = array();
	foreach ( sn_health_theme_preset_families() as $family => $paths ) {
		$v = wp_get_global_settings( $paths[0] );
		if ( ! is_array( $v ) || array() === $v ) {
			$out[ $family ] = array();
			continue;
		}
		$keyed = isset( $v['theme'] ) || isset( $v['default'] ) || isset( $v['custom'] );
		if ( ! $keyed ) {
			return null;
		}
		$out[ $family ] = sn_health_theme_preset_slugs( $v['theme'] ?? array() );
	}
	return $out;
}

/**
 * @param array|null $declared theme.json as an array; production reads it.
 * @param array|null $served   family => served theme-origin slugs; production reads it.
 * @return array sn_health_pack_check() envelope.
 */
function sn_health_check_theme_presets( $declared = null, $served = null ) {
	$label    = 'Theme presets served';
	$fix_hint = 'Set the family\'s default* flag to false in theme.json (e.g. spacing.defaultSpacingSizes, typography.defaultFontSizes), or rename the colliding slugs. Since WordPress 6.6 core drops a theme preset whose slug collides with a core default unless that flag is false, and the site silently serves core\'s value instead.';

	if ( null === $declared ) {
		$declared = sn_health_theme_json_declared();
	}
	if ( ! is_array( $declared ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'The active theme\'s theme.json could not be read, so declared presets could not be compared with served ones.' );
	}
	if ( null === $served ) {
		$served = sn_health_theme_presets_served();
	}
	if ( ! is_array( $served ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'Global settings did not come back keyed by origin, so theme presets cannot be told from core defaults on this WordPress.' );
	}

	$findings = array();
	foreach ( sn_health_theme_preset_families() as $family => $paths ) {
		$mine = sn_health_theme_preset_slugs( sn_health_theme_preset_get( $declared, array_merge( array( 'settings' ), $paths[0] ), array() ) );
		if ( array() === $mine ) {
			continue;
		}
		$have    = (array) ( $served[ $family ] ?? array() );
		$missing = array_values( array_diff( $mine, $have ) );
		if ( array() === $missing ) {
			continue;
		}
		$flag      = sn_health_theme_preset_get( $declared, array_merge( array( 'settings' ), $paths[1] ), 'absent' );
		$flag_name = implode( '.', $paths[1] );
		$findings[] = array(
			'subject_type'  => 'theme_json',
			'subject_id'    => 0,
			'subject_label' => $family,
			'subject_url'   => '',
			'edit_url'      => '',
			'note'          => sprintf(
				'theme.json declares %1$s slug%2$s %3$s, but the site serves none of them under the theme origin. %4$s is %5$s.',
				$family,
				1 === count( $missing ) ? '' : 's',
				implode( ', ', $missing ),
				$flag_name,
				'absent' === $flag ? 'not set' : ( false === $flag ? 'false (so this is not the core collision; check the slugs)' : 'true' )
			),
			'missing_slugs' => $missing,
			'flag'          => $flag_name,
		);
	}

	return sn_health_pack_check( $label, $findings, $fix_hint, null );
}
