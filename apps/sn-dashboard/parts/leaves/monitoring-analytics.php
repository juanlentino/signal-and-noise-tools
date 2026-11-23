<?php
/**
 * S&N Dashboard — Monitoring → Analytics, painted from the kit.
 *
 * The twin of the classic leaf (`snt_analytics_render_settings_section()`,
 * inc/analytics-admin-settings-parts.php): readings, status and actions. A
 * five-check Pipeline card, the Edge card, Your devices (a managed list), a
 * Setup card (Credentials with Test connection, Publish windows, the edge
 * Worker and salt, "Configured elsewhere") and North star; the Cloudflare
 * Worker setup steps close the leaf while the pipeline is incomplete.
 *
 * 2026-11: the collector endpoint, the roles kept out, engine tuning and
 * session funnels are settings and moved to Settings > Analytics
 * (settings-analytics.php, which calls the same four functions from
 * monitoring-analytics-settings.php and monitoring-analytics-tuning.php).
 * One line under the cards says so.
 *
 * 2026-10-16 (owner, desktop PWA: "a mess. Design, design, design."): one
 * Pipeline status card, the Edge card, then three cards across: Your devices
 * (open), Setup (each setting an earned fold whose summary answers) and North
 * star; the Worker setup steps close the leaf while the pipeline is incomplete.
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-analytics-parts.php';
require_once __DIR__ . '/settings-analytics-parts.php'; // settings_analytics_moved_html(): the line saying where this leaf's settings went.
require_once __DIR__ . '/monitoring-analytics-status.php'; // the Pipeline card
require_once __DIR__ . '/monitoring-analytics-setup.php'; // the Setup card, analytics_fold()
require_once __DIR__ . '/monitoring-analytics-settings.php'; // this device (and the collector and roles forms the Settings pane paints)
require_once __DIR__ . '/monitoring-analytics-owner.php'; // Your devices (SN Traffic Lanes)
require_once __DIR__ . '/monitoring-analytics-tuning.php'; // the Worker setup steps (and the tuning and funnels forms the Settings pane paints)

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_monitoring_analytics( array $ctx ) {
	if ( ! function_exists( '\snt_analytics_render_settings_section' ) ) {
		return \snt_kit_empty( __( 'Analytics settings are not available.', 'signal-and-noise-tools' ) );
	}
	// One probe per paint: Re-check now reads the Worker and the salt live
	// once, and the Pipeline chips and the Setup fold share that reading.
	$recheck = analytics_recheck_requested( $ctx );
	$worker  = analytics_worker_rows( $recheck );
	$salt    = function_exists( '\\sn_salt_window_get' ) ? (array) \sn_salt_window_get( $recheck ) : null;

	// 15.3.0: the edge's seven days, painted by the Connections leaf's part from
	// the same stored record, only once the monitor has a configured record (a
	// site without Cloudflare keeps the hub as it was). 2026-10-12: a full row
	// of its own, so its five figures share one row and its lists are capped.
	$edge   = '';
	$record = function_exists( __NAMESPACE__ . '\\cloudflare_monitor_record' ) ? cloudflare_monitor_record() : null;
	if ( is_array( $record ) && ! empty( $record['configured'] ) && function_exists( __NAMESPACE__ . '\\cloudflare_edge_html' ) && function_exists( __NAMESPACE__ . '\\cloudflare_data' ) ) {
		$edge = cloudflare_edge_html( cloudflare_data() );
	}

	return analytics_status_html( $worker, $salt )
		. $edge
		. \snt_kit_grid( array( analytics_owner_devices_html(), analytics_setup_html( $ctx, $worker, $salt ), function_exists( '\\snt_nsm_settings_html' ) ? \snt_nsm_settings_html() : '' ), 290, 24 )
		. settings_analytics_moved_html( 'analytics', (string) ( $ctx['tab'] ?? 'monitoring' ) ) // 2026-11: four settings left for Settings > Analytics.
		. analytics_worker_setup_html();
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/analytics'] = __NAMESPACE__ . '\\paint_monitoring_analytics';
		return $painters;
	}
);
