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
 * The composition (#1573): boxes on rows of comparable height, Scan status
 * and the recommendation cards at full width between the rows.
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
 * Two boxes on one row, the tags_pair() shape (content-tags-parts.php): a
 * side that painted nothing leaves the other alone at full width rather than
 * beside a hole.
 *
 * @param string $left  Painted section HTML, or ''.
 * @param string $right Painted section HTML, or ''.
 * @return string
 */
function insights_pair( $left, $right ) {
	return \snt_kit_grid( array( $left, $right ) );
}

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
	// #1573: rows of comparable height, measured live at 1581px. Two columns
	// stacked the three readouts to 1170 against 393 on the left. Now Run
	// Analysis (223) sits beside Settings (222), the weekly scan read against
	// the manual one; Scan status (129, 15.3.1) stands under the button that
	// makes it at full width, not beside a hole; the recommendation cards take
	// the full width; AI usage & spend (494) sits beside the Prompt-cache probe
	// (371), the spend beside what caching would do to it.
	return $intro
		. insights_pair( insights_run_form_html( $last, $ai_ready ), insights_settings_html() )
		. \snt_kit_section( __( 'Scan status', 'signal-and-noise-tools' ), insights_status_html( $last ) )
		. insights_recommendations_html( $last )
		. insights_pair( insights_usage_html(), insights_cache_probe_html() );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/insights'] = __NAMESPACE__ . '\\paint_monitoring_insights';
		return $painters;
	}
);
