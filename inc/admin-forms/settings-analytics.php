<?php
/**
 * Signal & Noise: Settings > Analytics, the classic section.
 *
 * The sixth pane of the Settings tab: seven settings, gathered from three
 * Measurement leaves. All three kept their readings, tables and actions and
 * one line saying where their settings went, so sn_admin_subtab_moves() has
 * no line.
 *
 * 1. Collector endpoint, from Measurement > Analytics: the Endpoint URL.
 * 2. Exclude my own visits, same leaf: one checkbox per role, generated from
 *    sn_beacon_excludable_roles() by the renderer the leaf called. ONE form:
 *    sn_handle_analytics_exclude_save() writes the whole list on every save.
 * 3. Engine tuning, same leaf: the baseline window and anomaly sensitivity.
 *    ONE form: the save writes both, each from its default when missing.
 * 4. Session funnels, same leaf: the one textarea.
 * 5. Machine readers, from Measurement > Machine Readers: the Worker URL.
 * 6. Insights, from Measurement > Insights: the weekly scan switch.
 *
 * The four analytics renderers and the Insights one are CALLED as they are,
 * not retyped, so each form keeps its nonce, names, ids, bounds and words.
 * Owner devices (a managed list), Publish windows, Credentials with Test
 * connection, the Edge report, the Worker reference, Run Analysis and the
 * sensor readout stayed on Measurement.
 *
 * Nothing about saving changed: analytics_collector_save,
 * analytics_exclude_save, analytics_tuning_save, analytics_funnels_save,
 * machine_readers_save and save_insights_settings, the same fields, the same
 * handlers. A form posts back to the address it was painted on
 * (sn_admin_post_url()) and carries no hidden tab or sub, so a save from this
 * pane returns to it.
 *
 * NOT here: the three RSS feed-tracking settings. sn_rss_tracker_handle_form()
 * redirects to Measurement > RSS whatever address posted it
 * (inc/rss-feed-tracker-form-parts.php), so on this pane a save would leave
 * the pane; they stay on their leaf until that handler may change.
 *
 * The native twin is apps/sn-dashboard/parts/leaves/settings-analytics.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pane's address, for the lines the old screens keep.
 *
 * @return string
 */
function snt_settings_analytics_url() {
	return function_exists( 'snt_desktop_admin_url' )
		? snt_desktop_admin_url( 'sn-settings', 'analytics' )
		: admin_url( 'admin.php?page=sn-theme-options&tab=settings&sub=analytics' );
}

/**
 * The pane's name as every pointer says it. Shared by both twins.
 *
 * @return string
 */
function snt_settings_analytics_label() {
	return __( 'Settings › Analytics', 'signal-and-noise-tools' );
}

/**
 * The sentence an old screen keeps, up to the link. Shared by both twins. It
 * names settings and never a value.
 *
 * @param string $from `analytics`, `machine-readers` or `insights`, the Measurement leaf.
 * @return string
 */
function snt_settings_analytics_moved_text( $from ) {
	$texts = array(
		'analytics'       => __( 'The collector endpoint, the roles kept out, engine tuning and session funnels moved to', 'signal-and-noise-tools' ),
		'machine-readers' => __( 'The Worker URL moved to', 'signal-and-noise-tools' ),
		'insights'        => __( 'The weekly scan switch moved to', 'signal-and-noise-tools' ),
	);
	return (string) ( $texts[ $from ] ?? '' );
}

/**
 * That line on a classic screen, with its link to the pane.
 *
 * @param string $from See snt_settings_analytics_moved_text().
 * @return void
 */
function snt_settings_analytics_moved_line( $from ) {
	echo '<p class="sn-field-helper">' . esc_html( snt_settings_analytics_moved_text( $from ) ) . ' <a href="' . esc_url( snt_settings_analytics_url() ) . '">'
		. esc_html( snt_settings_analytics_label() ) . '</a>.</p>';
}

/**
 * The section: six headed boxes by subject. A box paints only when its module
 * is loaded. Every leaf these came from sits on a manage_options page; so does
 * the pane. The wrapper class is the one analytics-admin.css styles these
 * forms under.
 *
 * @return void
 */
function sn_admin_render_settings_analytics_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="sn-an-settings-leaf"><div class="sn-2up">';
	foreach ( array( 'snt_analytics_render_collector', 'snt_analytics_render_exclusion', 'snt_analytics_render_engine_tuning', 'snt_analytics_render_funnels' ) as $render ) {
		if ( ! function_exists( $render ) ) {
			continue;
		}
		echo '<div class="sn-fieldset">';
		$render(); // prints its own heading and form.
		// Engine tuning's intro points at this link, which sat in the leaf's reference column.
		if ( 'snt_analytics_render_engine_tuning' === $render && function_exists( 'snt_analytics_render_filter_reference' ) ) {
			snt_analytics_render_filter_reference();
		}
		echo '</div>';
	}
	if ( function_exists( 'snt_mr_render_url_form' ) ) {
		echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Machine readers', 'signal-and-noise-tools' ) . '</h2>';
		snt_mr_render_url_form(
			defined( 'SN_MR_WORKER_URL' ) && '' !== (string) SN_MR_WORKER_URL,
			(string) sn_setting( 'machine_readers.worker_url', '' ),
			defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? SN_MR_DEFAULT_ENDPOINT : ''
		);
		echo '</div>';
	}
	if ( function_exists( 'snt_insights_render_settings_section' ) ) {
		snt_insights_render_settings_section(); // prints its own box, headed "Insights".
	}
	echo '</div></div>';
}
