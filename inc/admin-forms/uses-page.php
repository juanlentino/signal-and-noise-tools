<?php
/**
 * Signal & Noise — Uses Page admin section (Content tab → Uses Page sub-tab).
 *
 * The editor for the /about/uses gear list. One textarea in the
 * `## Label` / `- name | note` format; sn_action=uses_save stores it via
 * sn_uses_page_save() (inc/uses-page.php). Since v9.20.0 /about/uses is a real
 * CMS child Page, and saving here regenerates that Page's content: the box
 * stays the editor, the Page is the rendered artifact + Excerpt/SEO surface.
 * An empty box leaves the last published page unchanged.
 *
 * @package SignalNoiseTools
 * @since 7.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Uses Page section body. Used as the sn_admin_render_section()
 * callback for the Content tab's 'uses' sub-tab.
 *
 * @since 7.6.0
 */
function sn_admin_render_uses_section() {
	$page = function_exists( 'sn_uses_page_get' ) ? sn_uses_page_get() : null;
	$live = $page && function_exists( 'sn_uses_parse_groups' ) && ! empty( sn_uses_parse_groups( $page['raw'] ) );
	$raw  = $page ? (string) $page['raw'] : '';

	// First open: prefill from the theme's live file groups so the owner edits
	// the current list, not a blank box. sn_uses_groups() runs the filter chain;
	// with no option saved our filter passes the theme file content through.
	if ( '' === $raw && function_exists( 'sn_uses_groups' ) && function_exists( 'sn_uses_serialize_groups' ) ) {
		$raw = sn_uses_serialize_groups( sn_uses_groups() );
	}

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Uses page</h2>';

	if ( $live ) {
		echo '<p class="sn-fieldset-intro">This box is the editor for the live <a href="' . esc_url( home_url( '/about/uses' ) ) . '" target="_blank" rel="noopener">/about/uses</a> page. Saving here regenerates it. Last saved: <code>' . esc_html( (string) $page['updated'] ) . '</code>.</p>';
	} else {
		echo '<p class="sn-fieldset-intro">This box is the editor for the <a href="' . esc_url( home_url( '/about/uses' ) ) . '" target="_blank" rel="noopener">/about/uses</a> page. Add your gear list below and save to publish it.</p>';
	}

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn-uses-content">Gear list</label>';
	echo '<textarea id="sn-uses-content" name="uses_content" rows="16" class="large-text code">' . esc_textarea( $raw ) . '</textarea>';
	echo '<p class="sn-field-helper"><code>## Label</code> starts a group; each line is one item, with an optional <code>|</code> separating the name from a short note (e.g. <code>- SSL UF8 | Advanced DAW controller</code>). Saving regenerates the /about/uses page from this list. An empty box, or content with zero groups, leaves the last published page unchanged (it never blanks the page).</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="uses_save" class="button button-primary">Save uses page</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
