<?php
/**
 * Signal & Noise — Links admin section (Tools tab → Links sub-tab).
 *
 * Renders the external-shortcuts grid (source repos, releases, infrastructure).
 * Extracted verbatim from inc/admin-page.php in v4.5.4.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Links section body. Used as the sn_admin_render_section()
 * callback for the 'links' sub-tab.
 */
function sn_admin_render_links_section() {
	$link_groups = array(
		array(
			'label' => 'Source code',
			'links' => array(
				array( 'title' => 'Theme repo',  'href' => 'https://github.com/juanlentino/signal-and-noise' ),
				array( 'title' => 'Plugin repo', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools' ),
			),
		),
		array(
			'label' => 'Releases',
			'links' => array(
				array( 'title' => 'Theme releases',  'href' => 'https://github.com/juanlentino/signal-and-noise/releases' ),
				array( 'title' => 'Plugin releases', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools/releases' ),
			),
		),
		array(
			'label' => 'Infrastructure',
			'links' => array(
				array( 'title' => 'Cloudflare dashboard', 'href' => 'https://dash.cloudflare.com' ),
				array( 'title' => 'Cloudways platform',   'href' => 'https://platform.cloudways.com' ),
			),
		),
	);
	echo '<div class="sn-link-grid">';
	foreach ( $link_groups as $group ) {
		foreach ( $group['links'] as $link ) {
			$host = (string) wp_parse_url( $link['href'], PHP_URL_HOST );
			echo '<div class="sn-link-card">';
			echo '<span class="sn-link-card__label">' . esc_html( $group['label'] ) . '</span>';
			echo '<span class="sn-link-card__title">' . esc_html( $link['title'] ) . '</span>';
			echo '<span class="sn-link-card__host">' . esc_html( $host ) . ' &#x2197;</span>';
			echo '<a class="sn-link-card__link" href="' . esc_url( $link['href'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link['title'] ) . '</a>';
			echo '</div>';
		}
	}
	echo '</div>';
}
