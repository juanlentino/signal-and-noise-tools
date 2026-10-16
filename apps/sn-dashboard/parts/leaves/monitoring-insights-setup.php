<?php
/**
 * S&N Dashboard, Measurement > Insights: the Setup card.
 *
 * 2026-10-16 leaf anatomy (docs/kit-leaf-anatomy.md): the weekly scan is a
 * setting changed once in a while, so it is one earned fold in a Setup card
 * (snt_kit_setup_card(), inc/openstation-kit-anatomy.php) whose closed line
 * is its value, On or Off. Same one checkbox, same action, same field name
 * as the Settings card it replaces.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Setup card: the weekly-cron form in its fold.
 *
 * @return string
 */
function insights_settings_html() {
	$enabled = function_exists( 'snt_insights_weekly_cron_enabled' ) && snt_insights_weekly_cron_enabled();
	$field   = \snt_kit_field( 'checkbox', 'insights_weekly_cron', __( 'Run a weekly scan automatically', 'signal-and-noise-tools' ), $enabled );
	$form    = \snt_kit_form( 'save_insights_settings', $field, array( 'submit' => __( 'Save settings', 'signal-and-noise-tools' ) ) );
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array(
			array(
				'title'   => __( 'Weekly scan', 'signal-and-noise-tools' ),
				'summary' => $enabled ? __( 'On', 'signal-and-noise-tools' ) : __( 'Off', 'signal-and-noise-tools' ),
				'body'    => '<p class="snt-prose">' . \snt_kit_esc( __( 'A weekly automated scan can be enabled here. Defaults off. When enabled, fires weekly. You can still click Run Analysis any time.', 'signal-and-noise-tools' ) ) . '</p>' . $form,
			),
		),
		__( 'Each line is the setting as it stands; open one to change it.', 'signal-and-noise-tools' )
	);
}
