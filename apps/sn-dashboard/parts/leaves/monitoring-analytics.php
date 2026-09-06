<?php
/**
 * S&N Dashboard — Monitoring → Analytics, painted from the kit.
 *
 * The classic leaf (`snt_analytics_render_settings_section()`,
 * inc/analytics-admin.php:584, assembled from the small renderers in
 * inc/analytics-render-settings.php) is a settings hub: a five-pill pipeline
 * status strip, five writable folds (Credentials, Collector endpoint, Exclude
 * my own visits, Engine tuning, Session funnels — each a native `<details>`
 * fold on the classic page, an `<os-disclosure>` here) and a read-only
 * reference column (edge-Worker version, identity-salt window, "Configured
 * elsewhere" mirrors, a developer filter-reference link, and the Cloudflare
 * Worker setup steps while the pipeline is incomplete). Same six sn_action
 * values, same field names, same readers; the kit's parts instead of
 * wp-admin's two-column `.sn-2up` layout. Helpers live in
 * monitoring-analytics-parts.php to keep this file to the assembly.
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-analytics-parts.php';

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

	$out  = analytics_pipeline_html();
	$out .= $intro;
	$out .= analytics_credentials_html();
	$out .= analytics_collector_html();
	$out .= analytics_exclusion_html();
	$out .= analytics_tuning_html();
	$out .= analytics_funnels_html();
	$out .= analytics_worker_html();
	$out .= analytics_salt_html();
	$out .= analytics_mirrors_html( $tab );
	$out .= analytics_filter_reference_html();
	$out .= analytics_worker_setup_html();
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/analytics'] = __NAMESPACE__ . '\\paint_monitoring_analytics';
		return $painters;
	}
);
