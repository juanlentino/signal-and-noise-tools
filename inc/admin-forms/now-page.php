<?php
/**
 * Signal & Noise — Now Page admin section (Content tab → Now Page sub-tab).
 *
 * The editor for the theme's /now page content (owner direction 2026-07-01:
 * content lives in the plugin, not a hardcoded theme file). One textarea in
 * the simple `## Label` / `- item` format; sn_action=now_save stores it via
 * sn_now_page_save() (inc/now-page.php), which stamps the updated date the
 * /now page renders. Saving an empty box clears the override — the page
 * reverts to the theme's built-in file content.
 *
 * @package SignalNoiseTools
 * @since 7.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Now Page section body. Used as the sn_admin_render_section()
 * callback for the Content tab's 'now' sub-tab.
 *
 * @since 7.5.0
 */
function sn_admin_render_now_section() {
	$page   = function_exists( 'sn_now_page_get' ) ? sn_now_page_get() : null;
	$live   = $page && function_exists( 'sn_now_page_sections' ) && ! empty( sn_now_page_sections() );
	$raw    = $page ? (string) $page['raw'] : '';
	$sample = "## Building\n- What you're working on right now.\n\n## Listening\n- Current rotation.\n\n## Reading\n- Current book or essay.";

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Now page</h2>';

	if ( $live ) {
		echo '<p class="sn-fieldset-intro">This content feeds the live <a href="' . esc_url( home_url( '/now' ) ) . '" target="_blank" rel="noopener">/now</a> page. Updated stamp: <code>' . esc_html( (string) $page['updated'] ) . '</code> (set automatically on save).</p>';
	} else {
		echo '<p class="sn-fieldset-intro">Nothing saved here yet — the <a href="' . esc_url( home_url( '/now' ) ) . '" target="_blank" rel="noopener">/now</a> page is rendering the theme\'s built-in file content. Save content below to take over without a theme release.</p>';
	}

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn-now-content">Sections</label>';
	echo '<textarea id="sn-now-content" name="now_content" rows="14" class="large-text code" placeholder="' . esc_attr( $sample ) . '">' . esc_textarea( $raw ) . '</textarea>';
	echo '<p class="sn-field-helper"><code>## Label</code> starts a section; every other line is an item (a leading <code>-</code> is fine). Sections without items are skipped. Saving an empty box reverts /now to the theme\'s built-in content. Content that parses to zero sections never replaces the live page.</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="now_save" class="button button-primary">Save now page</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
