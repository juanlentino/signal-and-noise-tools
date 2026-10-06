<?php
/**
 * Signal & Noise Tools — the maturity pages' shared layout sheet
 * (assets/maturity-layout-front.css): the page track and the spec-sheet bands
 * for the hub and the seven system pages (2026-10-06).
 *
 * Loaded in the HEAD on a page whose content carries a maturity shortcode,
 * like the stats page's sheet: each family's own sheet enqueues at render
 * time and lands in the footer, so a layout carried there would paint narrow
 * first and jump. The roadmap keeps its own wide layout and is not listed.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shortcodes whose pages take the layout.
 *
 * @return string[]
 */
function sn_maturity_layout_shortcodes() {
	return array( 'sn_maturity_index', 'sn_analytics_maturity', 'sn_a11y_maturity', 'sn_ai_maturity', 'sn_ops_maturity', 'sn_ml_maturity', 'sn_machine_maturity', 'sn_provenance_maturity' );
}

/**
 * Does this content carry one of them? PURE apart from has_shortcode().
 *
 * @param string $content Post content.
 * @return bool
 */
function sn_maturity_layout_wanted( $content ) {
	foreach ( sn_maturity_layout_shortcodes() as $tag ) {
		if ( has_shortcode( (string) $content, $tag ) ) {
			return true;
		}
	}
	return false;
}

/** Enqueue the sheet in the head on a page that wants it. */
function sn_maturity_layout_enqueue() {
	$post = get_post();
	if ( is_singular() && $post && sn_maturity_layout_wanted( (string) $post->post_content ) ) {
		wp_enqueue_style( 'sn-maturity-layout-front', plugins_url( 'assets/maturity-layout-front.css', SNT_PATH . 'signal-and-noise-tools.php' ), array(), SNT_VERSION );
	}
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_enqueue_scripts', 'sn_maturity_layout_enqueue' );
}
