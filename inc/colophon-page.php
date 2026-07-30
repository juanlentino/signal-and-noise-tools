<?php
/**
 * Signal & Noise — the colophon shortcode ([sn_colophon]).
 *
 * Moves the colophon's content out of the theme template and into the CMS
 * (owner decision 2026-07-30): the page body carries [sn_colophon], the
 * theme stays frozen, and future edits are plugin releases or page edits.
 * Content mirrors the previously published colophon verbatim — including
 * the hosting and AI-assistance lines, which are ALREADY the owner's public
 * copy (they are deliberate here; the maturity-family leak contract does
 * not apply to the colophon's own published facts) — plus one new line
 * closing the loop to /maturity/ (resolved from the page per the
 * never-hardcode-paths rule).
 *
 * The version footer reads live values (wp_get_theme + SNT_VERSION), both
 * already public on the old colophon. Returns, never echoes; everything
 * escaped at build; no stylesheet — semantic markup inherits the theme's
 * typography exactly as the template-rendered version did.
 *
 * @package SignalNoiseTools @since 10.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The colophon items: slug → [label, text]. Filterable so a future line is
 * a one-liner (`sn_colophon_items`), mirroring the family's seam idiom.
 *
 * @return array<string,array{0:string,1:string}>
 */
function sn_colophon_items() {
	$items = array(
		'platform' => array( __( 'Platform', 'signal-and-noise-tools' ), __( 'WordPress Full Site Editing (block theme)', 'signal-and-noise-tools' ) ),
		'type'     => array( __( 'Type', 'signal-and-noise-tools' ), __( 'Bebas Neue (display), DM Mono (body & UI)', 'signal-and-noise-tools' ) ),
		'build'    => array( __( 'Build', 'signal-and-noise-tools' ), __( 'buildless: hand-written PHP, theme.json, vanilla ES5. No bundler.', 'signal-and-noise-tools' ) ),
		'hosting'  => array( __( 'Hosting', 'signal-and-noise-tools' ), __( 'Cloudways, Cloudflare CDN & DNS', 'signal-and-noise-tools' ) ),
		'tooling'  => array( __( 'Tooling', 'signal-and-noise-tools' ), __( 'companion plugin Signal & Noise Tools for SEO, search & ops', 'signal-and-noise-tools' ) ),
		'ai'       => array( __( 'AI assistance', 'signal-and-noise-tools' ), __( 'engineered with Claude (Anthropic) as a pair-programmer', 'signal-and-noise-tools' ) ),
		'trust'    => array( __( 'Trust', 'signal-and-noise-tools' ), __( 'every system documented at the maturity index', 'signal-and-noise-tools' ) ),
	);
	return apply_filters( 'sn_colophon_items', $items );
}

/**
 * Resolve the maturity index URL from the page itself ('' when absent).
 *
 * @return string
 */
function sn_colophon_maturity_url() {
	if ( function_exists( 'sn_maturity_index_resolve_url' ) ) {
		return sn_maturity_index_resolve_url( 'maturity' );
	}
	return '';
}

/**
 * [sn_colophon] — the how-this-is-built page body. Returns, never echoes.
 *
 * @param array|string $atts Unused; present for the shortcode signature.
 * @return string
 */
function sn_colophon_shortcode( $atts = array() ) {
	$out = '<div class="sn-colophon">'
		. '<p>' . esc_html__( 'Signal & Noise is a custom WordPress block theme - Full Site Editing, no page builder, no framework. Type is set in Bebas Neue and DM Mono. Built and maintained in the open, one author, no team.', 'signal-and-noise-tools' ) . '</p>'
		. '<ul class="sn-colophon-items">';

	$maturity_url = sn_colophon_maturity_url();
	foreach ( sn_colophon_items() as $slug => $item ) {
		$label = esc_html( isset( $item[0] ) ? $item[0] : $slug );
		$text  = esc_html( isset( $item[1] ) ? $item[1] : '' );
		if ( 'trust' === $slug && '' !== $maturity_url ) {
			$text = esc_html__( 'every system documented at the', 'signal-and-noise-tools' )
				. ' <a href="' . esc_url( $maturity_url ) . '">' . esc_html__( 'maturity index', 'signal-and-noise-tools' ) . '</a>';
		} elseif ( 'trust' === $slug ) {
			// Index page absent: keep the plain-text line rather than a dead link.
			$text = esc_html( $item[1] );
		}
		$out .= '<li class="sn-colophon-item--' . esc_attr( $slug ) . '"><strong>' . $label . '</strong> - ' . $text . '</li>';
	}
	$out .= '</ul>';

	// Live versions — both were already public on the template-rendered
	// colophon; parity kept on purpose.
	$theme_version  = function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Version' ) : '';
	$plugin_version = defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '';
	if ( '' !== $theme_version || '' !== $plugin_version ) {
		$out .= '<p class="sn-colophon-versions">'
			. esc_html( trim( ( '' !== $theme_version ? 'Theme v' . $theme_version : '' )
				. ( '' !== $theme_version && '' !== $plugin_version ? ' · ' : '' )
				. ( '' !== $plugin_version ? 'plugin v' . $plugin_version : '' ) ) )
			. '</p>';
	}

	return $out . '</div>';
}
add_shortcode( 'sn_colophon', 'sn_colophon_shortcode' );
