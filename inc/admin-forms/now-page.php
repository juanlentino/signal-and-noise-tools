<?php
/**
 * Signal & Noise — Now Page admin section (Content tab → Now Page sub-tab).
 *
 * The editor for the /now page. One textarea in the simple `## Label` /
 * `- item` format; sn_action=now_save stores it via sn_now_page_save()
 * (inc/now-page.php). Since v9.19.0 /now is a real CMS Page, and saving here
 * regenerates that Page's content (the hero plus these sections as blocks):
 * the box stays the editor, the Page is the rendered artifact + Excerpt/SEO
 * surface. An empty box leaves the last published page unchanged.
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
		echo '<p class="sn-fieldset-intro">This box is the editor for the live <a href="' . esc_url( home_url( '/now' ) ) . '" target="_blank" rel="noopener">/now</a> page. Saving here regenerates it. Last saved: <code>' . esc_html( (string) $page['updated'] ) . '</code>.</p>';
	} else {
		echo '<p class="sn-fieldset-intro">This box is the editor for the <a href="' . esc_url( home_url( '/now' ) ) . '" target="_blank" rel="noopener">/now</a> page. Add content below and save to publish it.</p>';
	}

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn-now-content">Sections</label>';
	echo '<textarea id="sn-now-content" name="now_content" rows="14" class="large-text code" placeholder="' . esc_attr( $sample ) . '">' . esc_textarea( $raw ) . '</textarea>';
	echo '<p class="sn-field-helper"><code>## Label</code> starts a section; every other line is an item (a leading <code>-</code> is fine). Sections without items are skipped. Saving regenerates the /now page from this content. An empty box, or content with zero sections, leaves the last published page unchanged (it never blanks the page).</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="now_save" class="button button-primary">Save now page</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
