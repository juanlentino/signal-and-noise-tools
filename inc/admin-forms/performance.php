<?php
/**
 * Signal & Noise — Performance admin section (Tools tab → Performance sub-tab).
 *
 * Renders the Speculation Rules toggle (sn_action=perf_save). When on, the site
 * opts into WP 7.0's native prerender/moderate speculative loading via the
 * filters in inc/speculation-rules.php; when off, that module returns null and
 * core emits no speculation rules.
 *
 * Added in v4.10.0 (T6).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Performance section body. Used as the sn_admin_render_section()
 * callback for the 'performance' sub-tab.
 */
function sn_admin_render_performance_section() {
	$spec_enabled = (bool) sn_setting( 'perf.speculative_loading', true );

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="perf_save">';

	echo '<h2 class="sn-fieldset-h">Speculative loading</h2>';
	echo '<p class="sn-fieldset-intro">WordPress 7.0 ships native <a href="https://developer.chrome.com/docs/web-platform/prerender-pages" target="_blank" rel="noopener noreferrer">Speculation Rules</a> (default: <code>auto</code>/<code>auto</code>). This opts the site into a more aggressive <code>prerender</code>/<code>moderate</code> profile — links the visitor is likely to click are rendered in the background, so navigation feels instant. The custom login URL and <code>/contact/*</code> are excluded automatically.</p>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Status</label>';
	echo '<label><input type="checkbox" name="speculative_loading" value="1"' . checked( $spec_enabled, true, false ) . '> Enabled — prerender likely next pages (mode <code>prerender</code>, eagerness <code>moderate</code>)</label>';
	echo '<p class="sn-field-helper">Turning this off disables speculative loading entirely (core emits no speculation rules). Only modern Chromium browsers act on these rules; others ignore them.</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" class="button button-primary">Save</button>';
	echo '</div>';

	echo '</form>';
}
