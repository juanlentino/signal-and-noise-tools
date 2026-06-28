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

	// Phase 4b: a single-toggle form earns full width only by pairing the
	// control with a second column — so the toggle (primary action) lives in the
	// shell's MAIN column and a status/reference readout in the narrower rail.
	sn_admin_shell_open();

	echo '<div class="sn-fieldset">';
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="perf_save">';

	echo '<h2 class="sn-fieldset-h">Speculative loading</h2>';
	echo '<p class="sn-fieldset-intro">WordPress 7.0 ships native <a href="https://developer.chrome.com/docs/web-platform/prerender-pages" target="_blank" rel="noopener noreferrer">Speculation Rules</a> (default: <code>auto</code>/<code>auto</code>). Enabling this opts the site into a more aggressive profile — links the visitor is likely to click are rendered in the background, so navigation feels instant. The profile and exclusions are summarized alongside.</p>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Status</label>';
	echo '<label><input type="checkbox" name="speculative_loading" value="1"' . checked( $spec_enabled, true, false ) . '> Enabled — prerender the pages a visitor is likely to open next</label>';
	echo '<p class="sn-field-helper">Turning this off disables speculative loading entirely (core emits no speculation rules).</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" class="button button-primary">Save</button>';
	echo '</div>';

	echo '</form>';
	echo '</div>'; // .sn-fieldset

	// ── Rail: the active profile + exclusions + browser-support reference. ──
	// Echo static literals only (no composed-variable echo) so WPCS EscapeOutput
	// stays clean — same discipline as the shell helpers.
	sn_admin_shell_rail( 'Speculative loading status' );

	if ( $spec_enabled ) {
		echo '<div class="sn-status-box">';
		echo '<div><p class="sn-status-box-title">Speculative loading</p><p class="sn-status-box-body">Enabled</p></div>';
		echo '<span class="sn-pill sn-pill--ok">On</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div><p class="sn-status-box-title">Speculative loading</p><p class="sn-status-box-body">Disabled</p></div>';
		echo '<span class="sn-pill">Off</span>';
		echo '</div>';
	}

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Profile</h2>';
	echo '<p class="sn-field-helper">Mode <code>prerender</code>, eagerness <code>moderate</code> — more aggressive than core\'s <code>auto</code>/<code>auto</code>.</p>';
	echo '<p class="sn-field-helper"><strong>Excluded automatically:</strong> the custom login URL and <code>/contact/*</code>.</p>';
	echo '<p class="sn-field-helper"><strong>Support:</strong> only modern Chromium browsers act on speculation rules; others safely ignore them.</p>';
	echo '</div>';

	sn_admin_shell_close();
}
