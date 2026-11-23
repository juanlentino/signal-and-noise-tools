<?php
/**
 * Signal & Noise: Site > Performance, the classic section.
 *
 * The readings about speculative loading: which profile the site serves
 * (this plugin's prerender/moderate, or WordPress's own default) and the
 * Profile reference. The switch that chooses between them left for Settings >
 * Front end in 2026-11 (inc/admin-forms/settings-front-end.php); this leaf
 * keeps one line saying so. The filters are inc/speculation-rules.php: the
 * switch never turns core's speculative loading off, so neither state here
 * reads as "off".
 *
 * Added in v4.10.0 (T6).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The reading's words, shared by both twins: the state sentence, the pill
 * and the profile in effect.
 *
 * @param bool $enabled The `perf.speculative_loading` setting.
 * @return array{state:string,pill:string,profile:string}
 */
function snt_speculative_loading_copy( $enabled ) {
	return $enabled
		? array(
			'state'   => __( 'Prerender is on', 'signal-and-noise-tools' ),
			'pill'    => __( 'On', 'signal-and-noise-tools' ),
			'profile' => __( 'prerender, moderate eagerness', 'signal-and-noise-tools' ),
		)
		: array(
			'state'   => __( 'Prerender is off; WordPress’s own default applies', 'signal-and-noise-tools' ),
			'pill'    => __( 'Off', 'signal-and-noise-tools' ),
			'profile' => __( 'WordPress’s default', 'signal-and-noise-tools' ),
		);
}

/**
 * Render the Performance section body. Used as the sn_admin_render_section()
 * callback for the 'performance' sub-tab.
 */
function sn_admin_render_performance_section() {
	$spec_enabled = (bool) sn_setting( 'perf.speculative_loading', true );
	$copy         = snt_speculative_loading_copy( $spec_enabled );

	// Phase 4b: the leaf earns full width by pairing two columns: the state
	// and what it means in the shell's MAIN column, the Profile reference in
	// the narrower rail.
	sn_admin_shell_open();

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Speculative loading</h2>';
	echo '<p class="sn-fieldset-intro">Since 6.8, WordPress ships native <a href="https://developer.chrome.com/docs/web-platform/prerender-pages" target="_blank" rel="noopener noreferrer">Speculation Rules</a> (default: <code>auto</code>/<code>auto</code>, which WordPress resolves to prefetch). With its switch on, this plugin asks for a more aggressive profile: links the visitor is likely to click are rendered in the background, so navigation feels instant. With it off, WordPress&rsquo;s default stays. The Profile box lists what is in effect and what it leaves out.</p>';
	// Neither state is a warning: off is WordPress's own default, not nothing.
	echo '<div class="sn-status-box">';
	echo '<div><p class="sn-status-box-title">' . esc_html( $copy['state'] ) . '.</p></div>';
	echo '<span class="sn-pill' . ( $spec_enabled ? ' sn-pill--ok' : '' ) . '">' . esc_html( $copy['pill'] ) . '</span>';
	echo '</div>';
	if ( function_exists( 'snt_settings_front_end_moved_line' ) ) {
		snt_settings_front_end_moved_line();
	}
	echo '</div>'; // .sn-fieldset

	// ── Rail: the profile in effect + exclusions + browser-support reference. ──
	sn_admin_shell_rail( 'Speculative loading profile' );

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Profile</h2>';
	if ( $spec_enabled ) {
		echo '<p class="sn-field-helper">Mode <code>prerender</code>, eagerness <code>moderate</code>: more aggressive than core\'s <code>auto</code>/<code>auto</code>.</p>';
	} else {
		echo '<p class="sn-field-helper">WordPress&rsquo;s default (<code>auto</code>/<code>auto</code>): mode <code>prefetch</code>, eagerness <code>conservative</code>, unless the host set another.</p>';
	}
	echo '<p class="sn-field-helper"><strong>Excluded automatically:</strong> <code>/contact/*</code>.</p>';
	echo '<p class="sn-field-helper"><strong>Browser support:</strong> only modern Chromium browsers act on speculation rules; others safely ignore them.</p>';
	echo '</div>';

	sn_admin_shell_close();
}
