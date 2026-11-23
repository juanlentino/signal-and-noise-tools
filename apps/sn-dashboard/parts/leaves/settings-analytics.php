<?php
/**
 * S&N Dashboard: Settings > Analytics, painted from the kit. The sixth pane
 * of the Settings tab, the native twin of inc/admin-forms/settings-analytics.php:
 * seven settings, gathered from three Measurement leaves that keep their
 * readings, tables and actions and one line each saying where the settings
 * went.
 *
 * 1. Collector endpoint, Roles kept out, Engine tuning and Session funnels,
 *    from Measurement > Analytics: the four functions that leaf's Setup card
 *    called (monitoring-analytics-settings.php, monitoring-analytics-tuning.php),
 *    so every field, name, bound and word is the one it was. They were closed
 *    folds there; a setting on a settings pane is open, so the fold's body is
 *    the box and its title the heading. The roles are one select per role,
 *    generated from sn_beacon_excludable_roles() by that same function, in
 *    ONE form: analytics_exclude_save writes the whole list on every save.
 * 2. Machine readers, from Measurement > Machine Readers: the Worker URL
 *    (machine_readers_url_form_html()), without the hidden tab and sub that
 *    named the leaf.
 * 3. Insights, from Measurement > Insights: the weekly scan switch
 *    (insights_weekly_box_html()).
 *
 * A save is answered on the tab and leaf it was posted from
 * (sn-dashboard.os.php, the `post` action), and no form here carries a hidden
 * tab or sub, so it lands back on this pane.
 *
 * NOT here: the three RSS feed-tracking settings (see the classic twin).
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
require_once __DIR__ . '/monitoring-machine-readers-parts.php'; // machine_readers_url_form_html().
require_once __DIR__ . '/monitoring-insights-setup.php'; // insights_weekly_box_html().

/**
 * The four boxes from Measurement > Analytics: each Setup fold's title and
 * body, open.
 *
 * @return string[] Painted boxes; none before the classic renderers are loaded.
 */
function settings_analytics_boxes() {
	if ( ! function_exists( '\snt_an_tuning_presets' ) || ! function_exists( '\snt_an_funnels_copy' ) ) {
		return array();
	}
	$boxes = array();
	foreach ( array( analytics_collector_html(), analytics_exclusion_html(), analytics_tuning_html(), analytics_funnels_html() ) as $fold ) {
		$boxes[] = \snt_kit_section( (string) $fold['title'], (string) $fold['body'] );
	}
	return $boxes;
}

/**
 * The Machine readers box: the Worker URL and Save. Reads the three values
 * the field shows, as the classic twin does; the sensor is not asked.
 *
 * @return string
 */
function settings_analytics_machine_readers_html() {
	return \snt_kit_section(
		__( 'Machine readers', 'signal-and-noise-tools' ),
		machine_readers_url_form_html(
			defined( 'SN_MR_WORKER_URL' ) && '' !== (string) constant( 'SN_MR_WORKER_URL' ),
			(string) \sn_setting( 'machine_readers.worker_url', '' ),
			defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? (string) constant( 'SN_MR_DEFAULT_ENDPOINT' ) : ''
		)
	);
}

/**
 * The pane: six headed boxes by subject. A box paints only when its module is
 * loaded. Every leaf these came from sits behind manage_options; so does the
 * pane.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_settings_analytics( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$boxes = settings_analytics_boxes();
	// The same two gates as the classic twin, so both paint the same boxes.
	if ( function_exists( '\snt_mr_render_url_form' ) ) {
		$boxes[] = settings_analytics_machine_readers_html();
	}
	if ( function_exists( '\snt_insights_render_settings_section' ) ) {
		$boxes[] = insights_weekly_box_html();
	}
	return \snt_kit_grid( $boxes, 290 );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['settings/analytics'] = __NAMESPACE__ . '\\paint_settings_analytics';
		return $painters;
	}
);
