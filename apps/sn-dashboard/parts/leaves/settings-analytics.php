<?php
/**
 * S&N Dashboard: Settings > Analytics, painted from the kit. The sixth pane
 * of the Settings tab, the native twin of inc/admin-forms/settings-analytics.php:
 * the settings of four Measurement leaves that keep their readings, tables
 * and actions and one line each saying where the settings went. Nine boxes,
 * headed from the pane's one list of titles (snt_settings_analytics_titles()).
 *
 * Every body is the function the old leaf called, so every field, name,
 * bound and word is the one it was: the collector, the roles kept out (one
 * select per role, generated from sn_beacon_excludable_roles(), ONE form)
 * with This device after it, where the classic box has it (its own form, for
 * a user whose role is excluded; it was on the Your devices card until 2026-11),
 * engine tuning, session funnels, publish windows and North star from
 * Measurement > Analytics; feed tracking from Measurement > RSS (ONE form of
 * three fields; its notice is the tracker's own `sn_rss_ok`); the Worker URL
 * from Machine Readers, without the hidden tab and sub that named the leaf;
 * the weekly scan switch from Insights. They were closed folds there; a
 * setting on a settings pane is open.
 *
 * A save is answered on the tab and leaf it was posted from
 * (sn-dashboard.os.php, the `post` action), and no form here carries a hidden
 * tab or sub, so it lands back on this pane.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/settings-analytics-parts.php'; // the line the leaves keep.
require_once __DIR__ . '/monitoring-analytics-settings.php'; // the collector and the roles kept out.
require_once __DIR__ . '/monitoring-analytics-tuning.php'; // engine tuning and session funnels.
require_once __DIR__ . '/monitoring-rss.php'; // rss_form(), the feed tracking form and the tracker's notice.
require_once __DIR__ . '/monitoring-machine-readers-parts.php'; // machine_readers_url_form_html().
require_once __DIR__ . '/monitoring-insights-setup.php'; // insights_weekly_form_html().

/**
 * The Machine readers box's body: the Worker URL and Save. Reads the three
 * values the field shows, as the classic twin does; the sensor is not asked.
 *
 * @return string
 */
function settings_analytics_machine_readers_html() {
	return machine_readers_url_form_html(
		defined( 'SN_MR_WORKER_URL' ) && '' !== (string) constant( 'SN_MR_WORKER_URL' ),
		(string) \sn_setting( 'machine_readers.worker_url', '' ),
		defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? (string) constant( 'SN_MR_DEFAULT_ENDPOINT' ) : ''
	);
}

/**
 * Each box's body, keyed as the titles are, for the modules that are loaded
 * (the same gates as the classic twin, so both paint the same boxes).
 *
 * @return array<string,string>
 */
function settings_analytics_bodies() {
	$bodies = array();
	if ( function_exists( '\snt_an_tuning_presets' ) && function_exists( '\snt_an_funnels_copy' ) ) {
		$bodies['collector'] = analytics_collector_html();
		$bodies['roles']     = analytics_exclusion_html() . analytics_device_html();
		$bodies['tuning']    = analytics_tuning_html();
		$bodies['funnels']   = analytics_funnels_html();
	}
	if ( function_exists( '\sn_publish_window_form_html' ) ) {
		$bodies['publish'] = \sn_publish_window_form_html();
	}
	if ( function_exists( '\sn_rss_tracker_render_settings_form' ) ) {
		$bodies['feed'] = rss_settings_form_html( (array) \sn_rss_tracker_settings() );
	}
	if ( function_exists( '\snt_mr_render_url_form' ) ) {
		$bodies['machine_readers'] = settings_analytics_machine_readers_html();
	}
	if ( function_exists( '\snt_insights_render_settings_section' ) ) {
		$bodies['insights'] = insights_weekly_form_html();
	}
	return $bodies;
}

/**
 * The pane: the tracker's notice when a feed tracking save was just
 * answered, then one headed box per subject in the titles' order. North star
 * paints its own box (snt_nsm_settings_html(), unchanged). Every leaf these
 * came from sits behind manage_options; so does the pane.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_settings_analytics( array $ctx ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$state  = $ctx['state'] ?? null;
	$params = is_object( $state ) ? (array) $state->get( 'params' ) : array();
	$bodies = settings_analytics_bodies();
	$titles = function_exists( 'snt_settings_analytics_titles' ) ? \snt_settings_analytics_titles() : array();
	$rows   = '';
	// Headed rows of three by subject (snt_settings_analytics_groups()).
	foreach ( function_exists( 'snt_settings_analytics_groups' ) ? \snt_settings_analytics_groups() : array() as $heading => $keys ) {
		$boxes = array();
		foreach ( $keys as $key ) {
			if ( 'north_star' === $key && function_exists( '\snt_nsm_settings_html' ) ) {
				$boxes[] = \snt_nsm_settings_html();
			} elseif ( isset( $bodies[ $key ], $titles[ $key ] ) ) {
				$boxes[] = \snt_kit_section( $titles[ $key ], $bodies[ $key ] );
			}
		}
		$rows .= array() === $boxes ? '' : '<h3 class="snt-h">' . \snt_kit_esc( $heading ) . '</h3>' . \snt_kit_grid( $boxes, 290 );
	}
	return rss_flash_html( isset( $params['sn_rss_ok'] ) && is_scalar( $params['sn_rss_ok'] ) ? (string) $params['sn_rss_ok'] : '' ) . $rows;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['settings/analytics'] = __NAMESPACE__ . '\\paint_settings_analytics';
		return $painters;
	}
);
