<?php
/**
 * S&N Dashboard, Monitoring > Analytics: engine tuning, session funnels and
 * the Worker setup steps, open.
 *
 * Moved out of monitoring-analytics-parts.php when the folds came out
 * (2026-10-11, owner rule: no collapsible sections). The two settings are
 * open sections whose description is the classic fold's one-line snapshot;
 * the setup steps are an open `<os-steps>` under a short heading, painted
 * only while the pipeline is incomplete, as before.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Engine tuning: baseline window (number) and anomaly sensitivity. The
 * classic radios (a `<fieldset>` of three presets) are a kit select: same
 * field name, same three values.
 *
 * @return string
 */
function analytics_tuning_html() {
	$baseline = (int) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.signal_baseline_days', 30 ) : 30 );
	$preset   = (string) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.anomaly_sensitivity', 'standard' ) : 'standard' );
	$presets  = \snt_an_tuning_presets();
	$preset   = isset( $presets[ $preset ] ) ? $preset : 'standard';

	$fields  = \snt_kit_field( 'number', 'sn_signal_baseline_days', __( 'Baseline window', 'signal-and-noise-tools' ), $baseline, array( 'min' => 14, 'max' => 90, 'step' => 1, 'hint' => __( 'Days of history behind the anomaly baseline. 14–90; shorter reacts faster, longer is steadier.', 'signal-and-noise-tools' ) ) );
	$fields .= \snt_kit_field( 'select', 'sn_anomaly_sensitivity', __( 'Anomaly sensitivity', 'signal-and-noise-tools' ), $preset, array( 'options' => $presets, 'hint' => __( 'Governs both anomaly families: the predictive signals engine and the per-page skim/dwell detector.', 'signal-and-noise-tools' ) ) );
	$fields .= '<p class="snt-hint" full-width>' . \snt_kit_esc( __( 'How unusual a day must be before it’s flagged as an anomaly.', 'signal-and-noise-tools' ) ) . '</p>';

	$body  = '<p class="snt-prose">' . \snt_kit_esc( __( 'How the anomaly detectors read your history. Trend and forecast signals aren’t tunable here. Developers can override more via the sn_analytics_signal_config filter: see the reference in the right column.', 'signal-and-noise-tools' ) ) . '</p>';
	$body .= \snt_kit_form( 'analytics_tuning_save', $fields, array( 'submit' => __( 'Save tuning', 'signal-and-noise-tools' ), 'columns' => 'auto' ) );

	return \snt_kit_section( __( 'Engine tuning', 'signal-and-noise-tools' ), $body, \snt_an_tuning_snapshot() );
}

/**
 * Session funnels: one named funnel per line.
 *
 * @return string
 */
function analytics_funnels_html() {
	$funnels   = (array) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.funnels', array() ) : array() );
	$text      = function_exists( '\sn_analytics_funnels_to_text' ) ? \sn_analytics_funnels_to_text( $funnels ) : '';
	$max_steps = defined( 'SN_ANALYTICS_FUNNELS_MAX_STEPS' ) ? constant( 'SN_ANALYTICS_FUNNELS_MAX_STEPS' ) : 8;
	$max_count = defined( 'SN_ANALYTICS_FUNNELS_MAX' ) ? constant( 'SN_ANALYTICS_FUNNELS_MAX' ) : 10;

	$fields = \snt_kit_field(
		'textarea',
		'sn_funnels',
		__( 'Session funnels, one per line', 'signal-and-noise-tools' ),
		$text,
		array(
			'rows'        => 6,
			'placeholder' => __( 'Home flow: /entry > /step > /goal', 'signal-and-noise-tools' ),
			/* translators: 1: max steps per funnel; 2: max funnel count. */
			'hint'        => sprintf( __( 'Named conversion paths for the Sessions view: one per line: "Name: /entry > /step > /goal" (2–%1$d steps, up to %2$d funnels). A bare path gets a leading slash added automatically.', 'signal-and-noise-tools' ), $max_steps, $max_count ),
		)
	);

	$body  = '<p class="snt-prose">' . \snt_kit_esc( __( 'Saving any funnel here replaces the built-in defaults for the Sessions view: including their custom-event goals. Those defaults remain available via the sn_analytics_session_funnels filter, which always runs last and wins over whatever is saved here.', 'signal-and-noise-tools' ) ) . '</p>';
	$body .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Only exact-match path steps can be expressed here. Funnels this box can’t express (prefix matching, custom-event goals) are not shown above and are managed in code via the filter, which always wins last.', 'signal-and-noise-tools' ) ) . '</p>';
	$body .= \snt_kit_form( 'analytics_funnels_save', $fields, array( 'submit' => __( 'Save funnels', 'signal-and-noise-tools' ) ) );

	return \snt_kit_section( __( 'Session funnels', 'signal-and-noise-tools' ), $body, \snt_an_funnels_snapshot() );
}

/**
 * The Cloudflare Worker setup steps, read-only. Painted only while the
 * pipeline is incomplete, the seam the classic reference reads too.
 *
 * @return string
 */
function analytics_worker_setup_html() {
	if ( function_exists( '\sn_analytics_pipeline_complete' ) && \sn_analytics_pipeline_complete() ) {
		return '';
	}
	$steps = \snt_kit_steps(
		array(
			sprintf( \snt_kit_esc( __( 'Read token (for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with %1$s. The Account ID is in the dashboard URL: %2$s.', 'signal-and-noise-tools' ) ), '<os-code>Account · Analytics · Read</os-code>', '<os-code>dash.cloudflare.com/&lt;account_id&gt;</os-code>' ),
			\snt_kit_esc( __( 'Deploy the edge Worker + its secrets (from the analytics-worker repo: this can’t be done from WordPress):', 'signal-and-noise-tools' ) ) . \snt_kit_code( "wrangler secret put SN_PX_TOKEN\nwrangler secret put SN_PX_SALT_SEED\nwrangler deploy" ),
			\snt_kit_esc( __( 'Theme beacon: set SN_BEACON_TOKEN in wp-config.php to the SAME value as the Worker’s SN_PX_TOKEN so the front-end beacon is accepted.', 'signal-and-noise-tools' ) ),
			\snt_kit_esc( __( 'Hit Test connection above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.', 'signal-and-noise-tools' ) ),
		)
	);
	return \snt_kit_section( __( 'Cloudflare Worker setup', 'signal-and-noise-tools' ), $steps, __( 'Manual and one-time: shown until every pipeline stage reads ok.', 'signal-and-noise-tools' ) );
}
