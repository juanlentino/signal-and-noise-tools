<?php
/**
 * S&N Dashboard — Monitoring → Analytics, painted from the kit.
 *
 * The classic leaf (`snt_analytics_render_settings_section()`,
 * inc/analytics-admin.php:584, assembled from the small renderers in
 * inc/analytics-render-settings.php) is a settings hub: a five-pill pipeline
 * status strip, five writable settings (Credentials, Collector endpoint,
 * Exclude my own visits, Engine tuning, Session funnels: each a native
 * `<details>` fold on the classic page) and a read-only reference column
 * (edge-Worker version, identity-salt window, "Configured elsewhere" mirrors,
 * a developer filter-reference link, and the Cloudflare Worker setup steps
 * while the pipeline is incomplete). Same six sn_action values, same field
 * names, same readers; the kit's parts instead of wp-admin's two-column
 * `.sn-2up` layout. Helpers live in the monitoring-analytics-*.php siblings
 * to keep this file to the assembly.
 *
 * 2026-10-11 (owner rule: no collapsible sections): every setting is an open
 * section whose description is its one-line current state. Open forms made
 * the left column three times the right one, so the two definitions (North
 * star, Session funnels) sit in the right column under the edge readings.
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-analytics-parts.php';
require_once __DIR__ . '/monitoring-analytics-settings.php'; // credentials, collector, exclusion (2026-10-11: open, no folds)
require_once __DIR__ . '/monitoring-analytics-tuning.php'; // tuning, funnels, the Worker setup steps

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
	$tab = (string) ( $ctx['tab'] ?? 'monitoring' );

	$dashboard_url = function_exists( '\snt_analytics_page_url' ) ? \snt_analytics_page_url() : '';
	$intro = '<p class="snt-prose">' . \snt_kit_esc( __( 'First-party analytics credentials. The comprehensive read-only dashboard has its own menu, S&N Analytics.', 'signal-and-noise-tools' ) ) . ' '
		. ( '' !== $dashboard_url ? \snt_kit_door( __( 'View dashboard →', 'signal-and-noise-tools' ), $dashboard_url ) : '' )
		. '</p>';

	// 15.3.0: the edge's seven days, painted by the Connections leaf's part from the same stored record.
	// Painted only once the monitor has a configured record, as the classic card is;
	// a site without Cloudflare keeps the hub as it was.
	$edge   = '';
	$record = function_exists( __NAMESPACE__ . '\\cloudflare_monitor_record' ) ? cloudflare_monitor_record() : null;
	if ( is_array( $record ) && ! empty( $record['configured'] ) && function_exists( __NAMESPACE__ . '\\cloudflare_edge_html' ) && function_exists( __NAMESPACE__ . '\\cloudflare_data' ) ) {
		$edge = cloudflare_edge_html( cloudflare_data() );
	}
	// 15.3.1: the Edge, 7 days section heads the LEFT column, above the settings;
	// as a full-width band it left five short folds over an empty half.
	$left  = $intro . $edge;
	$left .= analytics_credentials_html();
	$left .= analytics_collector_html();
	$left .= analytics_exclusion_html();
	$left .= analytics_tuning_html();

	$right  = analytics_worker_html();
	$right .= analytics_salt_html();
	$right .= function_exists( '\\snt_nsm_settings_html' ) ? \snt_nsm_settings_html() : '';
	$right .= analytics_funnels_html();
	$right .= analytics_mirrors_html( $tab );
	$right .= analytics_filter_reference_html();
	$right .= analytics_worker_setup_html();

	return analytics_pipeline_html()
		. \snt_kit_grid( array( \snt_kit_stack( $left ), \snt_kit_stack( $right ) ), 290, 24 );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/analytics'] = __NAMESPACE__ . '\\paint_monitoring_analytics';
		return $painters;
	}
);
