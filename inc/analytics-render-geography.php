<?php
/**
 * Signal & Noise — Analytics geography panel: the world-map choropleth, the pure
 * SVG-recolor transform behind it, and the static-cached vendored-SVG loader.
 * Native wp-admin markup via the panel primitive; the recolored SVG is
 * pre-escaped by the transform. Extracted from analytics-admin-render.php
 * (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // panel chrome + empty-fold collector (snt_an_note_empty)

/**
 * Recolor a vendored world-map SVG: rewrite each country <path>'s inline fill to a
 * quantile-tier WP-blue alpha (neutral for zero/absent) and inject a per-country
 * <title>. Pure string transform — no IO. Country paths are keyed id="XX" (uppercase
 * ISO-3166-1 alpha-2); structural ids (svg2/robinson/defs4) are left untouched.
 *
 * @param string $svg   Raw SVG markup (211 self-closing <path id="XX" … fill:#hex />).
 * @param array  $views ISO alpha-2 => view count (case-insensitive; <=0 ignored).
 * @param array  $names ISO alpha-2 => display name (uppercase keys); falls back to the code.
 * @param int    $tiers Number of shading tiers (default 5).
 * @return string Recolored SVG with <title>s; returns '' if $svg is empty.
 */
function snt_analytics_recolor_world_svg( $svg, $views, $names = array(), $tiers = 5 ) {
	$svg = (string) $svg;
	if ( '' === $svg ) {
		return '';
	}
	$tiers = max( 1, (int) $tiers );

	// Uppercase-normalize + drop non-positive.
	$norm = array();
	foreach ( (array) $views as $iso => $v ) {
		$v = (int) $v;
		if ( $v > 0 ) {
			$norm[ strtoupper( (string) $iso ) ] = $v;
		}
	}
	$sorted = array_values( $norm );
	sort( $sorted );
	$count = count( $sorted );

	$upper_names = array();
	foreach ( (array) $names as $iso => $name ) {
		$upper_names[ strtoupper( (string) $iso ) ] = (string) $name;
	}

	// The attribute half goes through core's HTML parser: it finds each <path>,
	// reads id and data-name, and rewrites the style in place. It cannot add a
	// child element, so each country path is left a numbered marker attribute
	// and one pass below turns marker plus "/>" into "><title>...</title></path>".
	$tags   = new WP_HTML_Tag_Processor( $svg );
	$titles = array();
	while ( $tags->next_tag( 'PATH' ) ) {
		$iso = $tags->get_attribute( 'id' );
		if ( ! is_string( $iso ) || 1 !== preg_match( '/^[A-Z]{2}$/', $iso ) || ! $tags->has_self_closing_flag() ) {
			continue; // structural / non-country path: leave as-is.
		}
		$v = isset( $norm[ $iso ] ) ? $norm[ $iso ] : 0;

		if ( $v > 0 && $count > 0 ) {
			$le = 0;
			foreach ( $sorted as $s ) {
				if ( $s <= $v ) {
					++$le;
				}
			}
			$tier  = max( 1, min( $tiers, (int) ceil( ( $le / $count ) * $tiers ) ) );
			$alpha = round( 0.15 + ( $tier - 1 ) / max( 1, $tiers - 1 ) * 0.75, 2 );
			$fill  = 'rgba(34,113,177,' . $alpha . ')';
		} else {
			$fill = '#f0f0f1';
		}

		// Rewrite the inline fill:#hex (default #f2f2f2) to the computed fill.
		$style = $tags->get_attribute( 'style' );
		if ( is_string( $style ) ) {
			$tags->set_attribute( 'style', (string) preg_replace( '/fill:\s*#[0-9a-fA-F]{3,6}/', 'fill:' . $fill, $style, 1 ) );
		}

		// Label precedence: caller-supplied name → the SVG path's own data-name
		// (SimpleMaps ships data-name="United States" etc.) → the bare ISO code.
		$label = isset( $upper_names[ $iso ] ) ? $upper_names[ $iso ] : $iso;
		$name  = $tags->get_attribute( 'data-name' );
		if ( ! isset( $upper_names[ $iso ] ) && is_string( $name ) && '' !== $name ) {
			$label = $name;
		}
		$tags->set_attribute( 'data-sn-title', (string) count( $titles ) );
		$titles[] = $v > 0 ? ( $label . ' — ' . number_format_i18n( $v ) . ' views' ) : $label;
	}
	$recolored = $tags->get_updated_html();

	// Inject each <title> (esc'd) and convert the self-closing /> to <path>…</path>.
	$titled = preg_replace_callback( '/<(path) data-sn-title="(\d+)"([^>]*?)\s*\/>/i', static function ( $m ) use ( $titles ) {
		return '<' . $m[1] . $m[3] . '><title>' . esc_html( $titles[ (int) $m[2] ] ) . '</title></' . $m[1] . '>';
	}, $recolored );

	// preg_replace_callback answers null on a PCRE failure: the map then ships
	// recolored and unlabeled (markers dropped) instead of not at all.
	return is_string( $titled ) ? $titled : str_replace( array_map( static function ( $i ) {
		return ' data-sn-title="' . $i . '"';
	}, array_keys( $titles ) ), '', $recolored );
}

/**
 * Country choropleth panel: shades the world map by view intensity from the durable
 * `country` dimension rows. Empty-state when no country has views; otherwise loads the
 * vendored SVG (static-cached) and echoes the recolored, titled map in an accessible
 * panel. Mirrors snt_analytics_render_heatmap()'s panel + a11y shape. No JS, no AE.
 *
 * @param string      $title Panel heading.
 * @param array       $rows  [{value: ISO-2, views, visits}] from sn_analytics_top_dimension('country', …).
 * @param string      $empty Empty-state copy.
 * @param string|null $svg   Map SVG override (tests inject '' to exercise the
 *                           missing-asset fold; the loader fn is unguarded so it
 *                           cannot be stubbed). Null loads the vendored asset.
 */
function snt_analytics_render_choropleth( $title, $rows, $empty, $svg = null ) {
	if ( null === $rows ) {
		// v9.68.1: null = the accessor's failed-read verdict — the read-failure
		// fold, never the empty-window copy.
		snt_an_note_empty( $title, snt_an_read_failed_copy( $title ) );
		return;
	}
	$views = array();
	$names = array();
	foreach ( (array) $rows as $r ) {
		$iso = strtoupper( (string) ( $r['value'] ?? '' ) );
		if ( '' === $iso ) {
			continue;
		}
		$views[ $iso ] = (int) ( $r['views'] ?? 0 );
	}

	$has_data = false;
	foreach ( $views as $v ) {
		if ( $v > 0 ) {
			$has_data = true;
			break;
		}
	}

	$svg = ( null === $svg ) ? snt_analytics_choropleth_svg() : (string) $svg;
	if ( ! $has_data || '' === $svg ) {
		// Two distinct fold causes share this branch. No country has views: the
		// common data-gap case — plain $empty covers it (even if the asset is
		// ALSO missing, the data gap is the message that matters). Data exists
		// but the vendored SVG failed to load: an operational fault, not a data
		// gap — say ONLY that, never the false "no data" copy.
		$why = ( $has_data && '' === $svg ) ? __( 'World map asset missing.', 'signal-and-noise-tools' ) : $empty;
		snt_an_note_empty( $title, $why );
		return;
	}

	snt_an_panel_open( $title, array( 'panel_class' => 'sn-an-choropleth', 'inside_class' => 'inside inside-flush sn-map-inside' ) );
	echo '<figure class="sn-map-figure">';
	echo '<div role="img" aria-label="' . esc_attr( __( 'World map shaded by views per country', 'signal-and-noise-tools' ) ) . '">';
	echo snt_analytics_recolor_world_svg( $svg, $views, $names ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped markup: vendored static SVG + numeric fills + esc_html'd <title>s.
	echo '</div></figure>';
	echo '<div class="sn-map-legend" aria-hidden="true">';
	echo '<span class="sn-legend-item"><span class="sn-legend-swatch sn-legend-swatch--low"></span> ' . esc_html__( 'Low', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-legend-item"><span class="sn-legend-swatch sn-legend-swatch--medium"></span> ' . esc_html__( 'Medium', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-legend-item"><span class="sn-legend-swatch sn-legend-swatch--high"></span> ' . esc_html__( 'High', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-legend-item sn-legend-item--meta">' . esc_html__( 'Views by country', 'signal-and-noise-tools' ) . '</span>';
	echo '</div>';
	snt_an_panel_close();
}

/**
 * Load + statically cache the vendored world-map SVG. Returns '' if the asset is
 * missing (the choropleth then degrades to its empty-state).
 *
 * @return string
 */
function snt_analytics_choropleth_svg() {
	static $svg = null;
	if ( null === $svg ) {
		$path = dirname( __DIR__ ) . '/assets/analytics/world-map.svg';
		$svg  = is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}
	return $svg;
}
