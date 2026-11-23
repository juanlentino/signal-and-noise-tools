<?php
/**
 * S&N Dashboard: the weekly insights scan switch, a box on Settings >
 * Analytics since 2026-11 (settings-analytics.php). It was a Setup fold on
 * Measurement > Insights; a setting on a settings pane is open. Same one
 * checkbox, same action, same field name.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Insights box's body: the weekly scan switch, its line and Save. ONE
 * field: save_insights_settings writes the switch from `! empty()` on every save.
 *
 * @return string
 */
function insights_weekly_form_html() {
	$enabled = function_exists( 'snt_insights_weekly_cron_enabled' ) && snt_insights_weekly_cron_enabled();
	$field   = \snt_kit_field( 'checkbox', 'insights_weekly_cron', __( 'Run a weekly scan automatically', 'signal-and-noise-tools' ), $enabled );
	return '<p class="snt-prose">' . \snt_kit_esc( __( 'A weekly automated scan can be enabled here. Defaults off. When enabled, fires weekly. You can still click Run Analysis any time.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_form( 'save_insights_settings', $field, array( 'submit' => __( 'Save settings', 'signal-and-noise-tools' ) ) );
}
