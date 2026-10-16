<?php
/**
 * S&N Dashboard, Monitoring > Analytics: Owner devices, the native twin of
 * the classic card (inc/owner-devices-render.php). Same view model, same
 * actions and field names; never an id, a marker or a key.
 *
 * Enroll from a window runs as the window's authenticated POST dispatch; the
 * marker's Set-Cookie rides that response (sn_owner_dev_cookie_allowed()).
 * Renewal runs only on wp-admin page loads (`admin_init`).
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @return string
 */
function analytics_owner_devices_html() {
	if ( ! function_exists( '\sn_owner_devices_view' ) ) {
		return '';
	}
	$copy = \sn_owner_devices_copy();
	$view = \sn_owner_devices_view( time() );
	$body = '<p class="snt-prose">' . \snt_kit_esc( $copy['intro'] ) . '</p>'
		. '<p class="snt-hint"><b>' . \snt_kit_esc( $view['this'] ) . '</b></p>';

	if ( array() === $view['rows'] ) {
		$body .= '<p class="snt-hint">' . \snt_kit_esc( $copy['none'] ) . '</p>';
	} else {
		$rows = array();
		foreach ( $view['rows'] as $r ) {
			$revoke = \snt_kit_form(
				'owner_device_revoke',
				\snt_kit_field( 'hidden', 'sn_owner_row', '', (string) $r['index'] ) . \snt_kit_field( 'hidden', 'sn_owner_row_nonce', '', $r['nonce'] ),
				/* translators: %s: device label. */
				array( 'submit' => $copy['revoke'], 'confirm' => sprintf( __( 'Revoke "%s"? Its visits will no longer be marked as yours.', 'signal-and-noise-tools' ), $r['label'] ), 'danger' => true )
			);
			$rows[] = array(
				'device'    => $r['label'] . ( $r['this'] ? ' ' . __( '(this browser)', 'signal-and-noise-tools' ) : '' ),
				'enrolled'  => $r['enrolled'],
				'last_seen' => $r['last_seen'],
				'action'    => array( 'html' => $revoke, 'text' => $copy['revoke'] ),
			);
		}
		$body .= \snt_kit_table(
			array(
				array( 'key' => 'device', 'label' => __( 'Device', 'signal-and-noise-tools' ) ),
				array( 'key' => 'enrolled', 'label' => __( 'Enrolled', 'signal-and-noise-tools' ) ),
				array( 'key' => 'last_seen', 'label' => __( 'Last seen', 'signal-and-noise-tools' ) ),
				array( 'key' => 'action', 'label' => __( 'Action', 'signal-and-noise-tools' ) ),
			),
			$rows,
			array( 'compact' => true )
		);
	}
	$body .= '<p class="snt-hint">' . \snt_kit_esc( $copy['last_seen'] ) . '</p>';

	$body .= $view['key_ready']
		? \snt_kit_form( 'owner_device_enroll', \snt_kit_field( 'text', 'sn_owner_device_label', $copy['label'], '', array( 'required' => true, 'maxlength' => SN_OWNER_LABEL_MAX ) ), array( 'submit' => $copy['enroll'] ) )
		: '<p class="snt-hint">' . \snt_kit_esc( $copy['no_key'] ) . '</p>';

	if ( array() !== $view['rows'] ) {
		$body .= \snt_kit_form( 'owner_device_revoke_all', '<p class="snt-hint">' . \snt_kit_esc( $copy['all_hint'] ) . '</p>', array( 'submit' => $copy['all'], 'confirm' => $copy['all_hint'], 'danger' => true ) );
	}
	return \snt_kit_section( $copy['heading'], $body, \sn_owner_devices_snapshot() );
}
