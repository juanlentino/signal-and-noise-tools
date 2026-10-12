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
				array( 'title' => 'Theme releases',  'href' => 'https://github.com/juanlentino/signal-and-noise/tags' ),
				array( 'title' => 'Plugin releases', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools/tags' ),
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
	// The S&N Home twin's heading (2026-10-12): one box, all six cards.
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'External shortcuts', 'signal-and-noise-tools' ) . '</h2>';
	echo '<div class="sn-link-grid">';
	foreach ( $link_groups as $group ) {
		foreach ( $group['links'] as $link ) {
			$host = (string) wp_parse_url( $link['href'], PHP_URL_HOST );
			echo '<div class="sn-link-card">';
			// Each said once (2026-10-12): the title was a span and then the same
			// words again as the link. The title IS the link now, as on S&N Home.
			echo '<span class="sn-link-card__label">' . esc_html( $group['label'] ) . '</span>';
			echo '<a class="sn-link-card__title" href="' . esc_url( $link['href'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link['title'] ) . '</a>';
			echo '<span class="sn-link-card__host">' . esc_html( $host ) . ' <span aria-hidden="true">&#x2197;</span></span>';
			echo '</div>';
		}
	}
	echo '</div>';
}
