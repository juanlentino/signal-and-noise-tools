<?php
/**
 * Signal & Noise: Settings > Front end, the classic section.
 *
 * The fourth pane of the Settings tab, nine settings:
 *
 * 1. The eight front-end settings (related notes, the "Updated" badge,
 *    reading speed, notes per page, JSON feed items, the command palette and
 *    its recent notes, where a note reply goes). They came WHOLE from Site >
 *    Front-End: that leaf was nothing but this one form, so it moved rather
 *    than leave a leaf holding only a pointer. sn_admin_render_front_end_form()
 *    (inc/admin-forms/front-end.php) is the form; sn_admin_subtab_moves()
 *    sends the old address here.
 * 2. The speculative loading switch, from Site > Performance. That leaf keeps
 *    its readings (which profile is in effect, the Profile reference) and one
 *    line saying where the switch went.
 *
 * Nothing about saving changed: save_theme and perf_save, the same fields,
 * the same handlers. A form posts back to the address it was painted on
 * (sn_admin_post_url()), so a save from this pane returns to it.
 *
 * The native twin is apps/sn-dashboard/parts/leaves/settings-front-end.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pane's address, for the line Site > Performance keeps.
 *
 * @return string
 */
function snt_settings_front_end_url() {
	return function_exists( 'snt_desktop_admin_url' )
		? snt_desktop_admin_url( 'sn-settings', 'front-end' )
		: admin_url( 'admin.php?page=sn-theme-options&tab=settings&sub=front-end' );
}

/**
 * The sentence Site > Performance keeps, up to the link. Shared by both
 * twins.
 *
 * @return string
 */
function snt_settings_front_end_moved_text() {
	return __( 'The prerender switch moved to', 'signal-and-noise-tools' );
}

/**
 * That line on the classic Performance leaf, with its link to the pane.
 *
 * @return void
 */
function snt_settings_front_end_moved_line() {
	echo '<p class="sn-field-helper">' . esc_html( snt_settings_front_end_moved_text() ) . ' <a href="' . esc_url( snt_settings_front_end_url() ) . '">'
		. esc_html__( 'Settings › Front end', 'signal-and-noise-tools' ) . '</a>.</p>';
}

/**
 * What each state of the switch does, in one line. Shared by both twins.
 * "prefetch" is what core's 'auto' resolves to (wp-includes/speculative-loading.php,
 * 6.8 to 7.1); since 7.1 a host can set another default with a constant,
 * which the line allows for.
 *
 * @return string
 */
function snt_speculative_loading_hint() {
	return __( 'On: pages a visitor is likely to open next are prerendered. Off: WordPress’s own default applies (prefetch, unless the host set another).', 'signal-and-noise-tools' );
}

/**
 * The switch, a box headed "Performance": the checkbox Site > Performance
 * had, under the label it had, and its own Save. sn_handle_perf_save()
 * (inc/admin-post-actions/monitoring.php) writes the switch from `! empty()`
 * on every save; it is the handler's one field and this is its one form.
 *
 * @return void
 */
function snt_speculative_loading_render_form() {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-fieldset">';
	wp_nonce_field( 'sn_perf_save' );
	echo '<input type="hidden" name="action" value="sn_perf_save">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Performance', 'signal-and-noise-tools' ) . '</h2>';
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">' . esc_html__( 'Speculative loading', 'signal-and-noise-tools' ) . '</label>';
	echo '<label><input type="checkbox" name="speculative_loading" value="1"' . checked( (bool) sn_setting( 'perf.speculative_loading', true ), true, false ) . '> ' . esc_html__( 'Enabled: prerender the pages a visitor is likely to open next', 'signal-and-noise-tools' ) . '</label>';
	echo '<p class="sn-field-helper">' . esc_html( snt_speculative_loading_hint() ) . '</p>';
	echo '</div>';
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button>';
	echo '</div>';
	echo '</form>';
}

/**
 * The section: the front-end form (three boxes, one Save), then the
 * Performance box, a sibling form and never inside the first.
 *
 * @return void
 */
function sn_admin_render_settings_front_end_section() {
	if ( function_exists( 'sn_admin_render_front_end_form' ) ) {
		sn_admin_render_front_end_form();
	}
	snt_speculative_loading_render_form();
}
