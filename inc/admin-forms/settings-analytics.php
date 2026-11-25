<?php
/**
 * Signal & Noise: Settings > Analytics, the classic section.
 *
 * The sixth pane of the Settings tab: eleven settings, gathered from four
 * Measurement leaves, and the north star's three. All four kept their readings, tables and actions and
 * one line saying where their settings went, so sn_admin_subtab_moves() has
 * no line. Nine headed boxes, each heading from the one list of titles
 * (snt_settings_analytics_titles(), settings-analytics-pointers.php):
 *
 * 1. Collector endpoint, from Measurement > Analytics: the Endpoint URL.
 * 2. Roles kept out, same leaf: one checkbox per role, generated from
 *    sn_beacon_excludable_roles() by the renderer the leaf called. ONE form:
 *    sn_handle_analytics_exclude_save() writes the whole list on every save.
 * 3. Engine tuning, same leaf: the baseline window and anomaly sensitivity.
 *    ONE form: the save writes both, each from its default when missing.
 * 4. Session funnels, same leaf: the one textarea.
 * 5. Publish windows, same leaf: the window length.
 * 6. North star: which page groups count, the scroll depth and the time on
 *    page (snt_nsm_render_settings(), inc/north-star-settings.php). The
 *    window had it first; this twin came in 2026-11. ONE form: the save
 *    rewrites all three.
 * 7. Feed tracking, from Measurement > RSS: the switch, the event name and
 *    the log retention. ONE form: the save rebuilds the tracker's option.
 *    Its notice is the tracker's own (?sn_rss_ok), painted on this pane.
 * 8. Machine readers, from Measurement > Machine Readers: the Worker URL.
 * 9. Insights, from Measurement > Insights: the weekly scan switch.
 *
 * Every renderer is CALLED as it is, not retyped, so each form keeps its
 * nonce, names, ids, bounds and words. Owner devices (a managed list),
 * Credentials with Test connection, the Edge report, the Worker reference,
 * Reset to Defaults and Purge, Run Analysis and the sensor readout stayed on
 * Measurement.
 *
 * Nothing about what a save writes changed. A form posts back to the address
 * it was painted on (sn_admin_post_url()) and carries no hidden tab or sub,
 * so a save from this pane returns to it; the RSS save follows the posting
 * address since 2026-11 (sn_rss_tracker_landing()).
 *
 * The native twin is apps/sn-dashboard/parts/leaves/settings-analytics.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/settings-analytics-pointers.php';

/**
 * The Feed tracking box's body: the tracker's own settings form.
 *
 * @return void
 */
function snt_settings_analytics_render_feed() {
	sn_rss_tracker_render_settings_form( sn_rss_tracker_settings() );
}

/**
 * The Machine readers box's body: the Worker URL form, with the three values
 * the field shows. The sensor is not asked.
 *
 * @return void
 */
function snt_settings_analytics_render_machine_readers() {
	snt_mr_render_url_form(
		defined( 'SN_MR_WORKER_URL' ) && '' !== (string) SN_MR_WORKER_URL,
		(string) sn_setting( 'machine_readers.worker_url', '' ),
		defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? SN_MR_DEFAULT_ENDPOINT : ''
	);
}

/**
 * The section: one headed box per subject, in the titles' order. A box paints
 * only when its module is loaded (the second column names the function that
 * proves it). Every leaf these came from sits on a manage_options page; so
 * does the pane. The wrapper class is the one analytics-admin.css styles
 * these forms under.
 *
 * @return void
 */
function sn_admin_render_settings_analytics_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flash code the tracker's own renderer matches against fixed values.
	if ( function_exists( 'sn_rss_tracker_render_flash' ) && isset( $_GET['sn_rss_ok'] ) ) {
		sn_rss_tracker_render_flash( sanitize_text_field( wp_unslash( $_GET['sn_rss_ok'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same read.
	}
	$boxes = array(
		'collector'       => array( 'snt_analytics_render_collector', 'snt_analytics_render_collector' ),
		'roles'           => array( 'snt_analytics_render_exclusion', 'snt_analytics_render_exclusion' ),
		'tuning'          => array( 'snt_analytics_render_engine_tuning', 'snt_analytics_render_engine_tuning' ),
		'funnels'         => array( 'snt_analytics_render_funnels', 'snt_analytics_render_funnels' ),
		'publish'         => array( 'snt_analytics_render_publish_window', 'snt_analytics_render_publish_window' ),
		'north_star'      => array( 'snt_nsm_render_settings', 'snt_nsm_render_settings' ),
		'feed'            => array( 'snt_settings_analytics_render_feed', 'sn_rss_tracker_render_settings_form' ),
		'machine_readers' => array( 'snt_settings_analytics_render_machine_readers', 'snt_mr_render_url_form' ),
		'insights'        => array( 'snt_insights_render_settings_section', 'snt_insights_render_settings_section' ),
	);
	$titles = snt_settings_analytics_titles();
	echo '<div class="sn-an-settings-leaf">';
	foreach ( snt_settings_analytics_groups() as $heading => $keys ) {
		$keys = array_values( array_filter( $keys, static fn( $k ) => function_exists( $boxes[ $k ][1] ) ) );
		if ( array() === $keys ) {
			continue;
		}
		echo '<h2 class="sn-section-h">' . esc_html( $heading ) . '</h2><div class="sn-2up">';
		foreach ( $keys as $key ) {
			echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html( $titles[ $key ] ) . '</h2>';
			$boxes[ $key ][0]();
			// Engine tuning's intro points at this link, which sat in the leaf's reference column.
			if ( 'tuning' === $key && function_exists( 'snt_analytics_render_filter_reference' ) ) {
				snt_analytics_render_filter_reference();
			}
			echo '</div>';
		}
		echo '</div>';
	}
	echo '</div>';
}
