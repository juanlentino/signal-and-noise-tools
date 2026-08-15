<?php
/**
 * Signal & Noise Tools — weekly digest preferences.
 *
 * A small Tools-tab section: whether the weekly digest is sent at all, and the
 * day it goes out. Stored under the `digest` subtree of SN_SETTINGS_OPTION and
 * written through sn_setting_update(), the same way the monitoring and perf
 * sections persist their own knobs.
 *
 * @package SignalNoiseTools
 * @since 11.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the weekly digest enabled?
 *
 * @return bool
 */
function snt_digest_enabled() {
	return (bool) sn_setting( 'digest.enabled', false );
}

/**
 * Day of week the digest is sent, 1 (Monday) to 7 (Sunday).
 *
 * @return int
 */
function snt_digest_send_day() {
	$day = (int) sn_setting( 'digest.send_day', 1 );
	return ( $day >= 1 && $day <= 7 ) ? $day : 1;
}

/**
 * Persist the digest preferences posted from the Tools tab.
 *
 * @param array $raw Unslashed form payload.
 * @return void
 */
function snt_digest_save( array $raw ) {
	sn_setting_update( 'digest.enabled', ! empty( $raw['digest_enabled'] ) );

	$day = isset( $raw['digest_send_day'] ) ? (int) $raw['digest_send_day'] : 1;
	if ( $day < 1 || $day > 7 ) {
		$day = 1;
	}
	sn_setting_update( 'digest.send_day', $day );
}

/**
 * Render the Tools-tab section.
 *
 * @return void
 */
function snt_digest_render_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$enabled = snt_digest_enabled();
	$day     = snt_digest_send_day();
	echo '<h3>' . esc_html__( 'Weekly digest', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p><label><input type="checkbox" name="digest_enabled" value="1" ' . checked( $enabled, true, false ) . '> ';
	echo esc_html__( 'Send a weekly digest', 'signal-and-noise-tools' ) . '</label></p>';
	echo '<p><label>' . esc_html__( 'Send on day', 'signal-and-noise-tools' ) . ' ';
	echo '<input type="number" name="digest_send_day" min="1" max="7" value="' . esc_attr( (string) $day ) . '"></label></p>';
}
add_action( 'sn_admin_tools_tab', 'snt_digest_render_section' );
