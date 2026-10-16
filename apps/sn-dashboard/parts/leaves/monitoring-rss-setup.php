<?php
/**
 * S&N Dashboard, Measurement > RSS: the status line and the Setup card.
 *
 * 2026-10-16 leaf anatomy (docs/kit-leaf-anatomy.md): the leaf answers "is
 * this fine?" in one status line (tracking on or off, how long the log is
 * kept), and the settings and the purge, changed once in a while, are folds
 * of one Setup card (snt_kit_setup_card(), inc/openstation-kit-anatomy.php)
 * whose closed lines state the setting. Same three forms, same field names,
 * same `sn_rss_action` values and nonce (rss_form()) as the boxes they replace.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Days the log is kept, as stored.
 *
 * @param array $settings sn_rss_tracker_settings() result.
 * @return string "45 days".
 */
function rss_retention_text( array $settings ) {
	$days = (int) ( $settings['log_retention_days'] ?? 90 );
	/* translators: %s: number of days */
	return sprintf( _n( '%s day', '%s days', $days, 'signal-and-noise-tools' ), number_format_i18n( $days ) );
}

/**
 * The status line: tracking on (ok) or off (a warning: nothing is logged or
 * sent), and how long rows are kept.
 *
 * @param array $settings sn_rss_tracker_settings() result.
 * @return string
 */
function rss_status_html( array $settings ) {
	$on = ! empty( $settings['enabled'] );
	return \snt_kit_status_line(
		array(
			array(
				'tone'  => $on ? 'ok' : 'warn',
				'label' => $on ? __( 'Tracking on', 'signal-and-noise-tools' ) : __( 'Tracking off', 'signal-and-noise-tools' ),
				'title' => $on ? '' : __( 'Feed requests are neither logged nor sent to the collector.', 'signal-and-noise-tools' ),
			),
			/* translators: %s: "45 days" */
			array( 'label' => sprintf( __( 'Keeps %s', 'signal-and-noise-tools' ), rss_retention_text( $settings ) ) ),
		)
	);
}

/**
 * The Setup card: Settings (the switch, event name and retention, then
 * Reset to Defaults) and Maintenance (the purge). Settings opens itself
 * while its required event name is empty.
 *
 * @param array  $settings sn_rss_tracker_settings() result.
 * @param string $tab      The painting tab, for the go-target.
 * @return string
 */
function rss_setup_html( array $settings, $tab ) {
	$event = trim( (string) ( $settings['event_name'] ?? '' ) );
	$parts = array(
		! empty( $settings['enabled'] ) ? __( 'On', 'signal-and-noise-tools' ) : __( 'Off', 'signal-and-noise-tools' ),
		'' !== $event ? $event : __( 'no event name', 'signal-and-noise-tools' ),
		rss_retention_text( $settings ),
	);
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array(
			array(
				'title'   => __( 'Settings', 'signal-and-noise-tools' ),
				'summary' => implode( ' · ', $parts ),
				'body'    => rss_settings_form_html( $settings, $tab ) . rss_reset_form_html(),
				'open'    => '' === $event,
			),
			array(
				'title'   => __( 'Maintenance', 'signal-and-noise-tools' ),
				/* translators: %s: "45 days" */
				'summary' => sprintf( __( 'A daily prune keeps %s; purge by hand here', 'signal-and-noise-tools' ), rss_retention_text( $settings ) ),
				'body'    => rss_maintenance_html( $settings ),
			),
		),
		__( 'Each line is the setting as it stands; open one to change it.', 'signal-and-noise-tools' )
	);
}
