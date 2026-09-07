<?php
/**
 * S&N Dashboard — Monitoring → Insights, painted from the kit.
 *
 * The classic leaf (`sn_admin_render_insights_section()` →
 * `snt_insights_render_admin_tab()`, inc/insights-admin.php) is a cross-system
 * synthesis tab: a Run-Analysis form, zero-to-many open-question
 * recommendation cards (with mark-done/snooze/dismiss actions), an AI usage
 * & spend readout, a prompt-cache probe verdict, a compact scan-status box,
 * and a weekly-cron settings form. Same five sn_action values, same fields,
 * same handlers — the kit's parts instead of the classic .sn-fieldset shell.
 * Section builders live in monitoring-insights-parts.php to keep this file under ~200 lines.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-insights-parts.php';

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_monitoring_insights( array $ctx ) {
	unset( $ctx );
	if ( ! function_exists( 'snt_insights_last_scan' ) ) {
		return \snt_kit_empty( __( 'Insights is not available.', 'signal-and-noise-tools' ) );
	}
	$last     = snt_insights_last_scan();
	$ai_ready = function_exists( 'snt_ai_is_available' ) && snt_ai_is_available();

	$intro = '<p class="snt-prose">' . \snt_kit_esc( __( 'Cross-system synthesis: reads your Plausible analytics, publish history, webhook delivery patterns, and cron freshness, then surfaces unexplored open questions worth developing for your Notes (or nothing, when none clears the bar). One AI call per scan; results cached 7 days.', 'signal-and-noise-tools' ) ) . '</p>';
	$left  = insights_run_form_html( $last, $ai_ready );
	$left .= insights_recommendations_html( $last );

	$right  = \snt_kit_section( __( 'Scan status', 'signal-and-noise-tools' ), insights_status_html( $last ) );
	$right .= insights_usage_html();
	$right .= insights_cache_probe_html();
	$right .= insights_settings_html();

	return $intro
		. '<div class="snt-2up">'
		. '<div class="snt-2up-col">' . $left . '</div>'
		. '<div class="snt-2up-col">' . $right . '</div>'
		. '</div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/insights'] = __NAMESPACE__ . '\\paint_monitoring_insights';
		return $painters;
	}
);
