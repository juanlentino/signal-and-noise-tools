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
 * 2026-10-16: the Your devices card, open (a control in regular use, never a
 * fold): this browser, who is kept out while logged in, the devices with a
 * Revoke each, enroll, This device and Revoke all; the long explanation sits
 * under a small How this works fold (snt_kit_how_fold()).
 *
 * Live check (owner, desktop PWA, 2026-10-16): each Revoke sat alone on a
 * full-width row with its form's footer rule, so the card stood twice its
 * neighbors' height. Every action here is a compact one-button form
 * (snt_kit_form( ..., array( 'inline' => true ) )): the revokes and Revoke
 * all in one wrapping row under the table, danger outlines, and This device
 * beside its own line. Revoke all's long hint moved into How this works; its
 * confirm still states it before anything is revoked.
 *
 * @package SignalNoiseTools
 * @since 2026.10.18
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-analytics-settings.php'; // analytics_device_html()

/**
 * The Your devices card.
 *
 * @return string
 */
function analytics_owner_devices_html() {
	$device = analytics_device_html();
	if ( ! function_exists( '\sn_owner_devices_view' ) ) {
		$who = analytics_kept_out_html();
		return '' === $device . $who ? '' : \snt_kit_section( __( 'Your devices', 'signal-and-noise-tools' ), $who . $device );
	}
	$copy = \sn_owner_devices_copy();
	$view = \sn_owner_devices_view( time() );
	$body = '<p class="snt-hint"><b>' . \snt_kit_esc( $view['this'] ) . '</b></p>' . analytics_kept_out_html();

	if ( array() === $view['rows'] ) {
		$body .= '<p class="snt-hint">' . \snt_kit_esc( $copy['none'] ) . '</p>';
	} else {
		$rows    = array();
		$revokes = '';
		foreach ( $view['rows'] as $r ) {
			$rows[] = array(
				'device'    => $r['label'] . ( $r['this'] ? ' ' . __( '(this browser)', 'signal-and-noise-tools' ) : '' ) . ( $r['confirmed'] ? '' : ' ' . $copy['unconfirmed'] ),
				'enrolled'  => $r['enrolled'],
				'last_seen' => $r['last_seen'],
			);
			// One form per device, in a row under the table rather than in a
			// table cell: a whole <os-form> is not a slot-cell control, and the
			// live check (2026-10-15) painted that cell as "[object Object]".
			$revokes .= \snt_kit_form(
				'owner_device_revoke',
				\snt_kit_field( 'hidden', 'sn_owner_row', '', (string) $r['index'] ) . \snt_kit_field( 'hidden', 'sn_owner_row_nonce', '', $r['nonce'] ),
				/* translators: %s: device label. */
				array( 'submit' => sprintf( __( 'Revoke "%s"', 'signal-and-noise-tools' ), $r['label'] ), 'confirm' => sprintf( __( 'Revoke "%s"? Its visits will no longer be marked as yours.', 'signal-and-noise-tools' ), $r['label'] ), 'danger' => true, 'inline' => true )
			);
		}
		$revokes .= \snt_kit_form( 'owner_device_revoke_all', '', array( 'submit' => $copy['all'], 'confirm' => $copy['all_hint'], 'danger' => true, 'inline' => true ) );
		$body .= \snt_kit_table(
			array(
				array( 'key' => 'device', 'label' => __( 'Device', 'signal-and-noise-tools' ) ),
				array( 'key' => 'enrolled', 'label' => __( 'Enrolled', 'signal-and-noise-tools' ) ),
				array( 'key' => 'last_seen', 'label' => __( 'Last seen', 'signal-and-noise-tools' ) ),
			),
			$rows,
			array( 'compact' => true )
		) . '<os-cluster gap="8" class="snt-actions">' . $revokes . '</os-cluster>';
	}

	$body .= $view['mine'] ? '' : ( $view['key_ready']
		? \snt_kit_form( 'owner_device_enroll', \snt_kit_field( 'text', 'sn_owner_device_label', $copy['label'], '', array( 'required' => true, 'maxlength' => SN_OWNER_LABEL_MAX ) ), array( 'submit' => $copy['enroll'] ) )
		: '<p class="snt-hint">' . \snt_kit_esc( $copy['no_key'] ) . '</p>' );
	$body .= $device;
	$how   = '<p class="snt-prose">' . \snt_kit_esc( $copy['intro'] ) . '</p><p class="snt-hint">' . \snt_kit_esc( $copy['last_seen'] ) . '</p>'
		. ( array() !== $view['rows'] ? '<p class="snt-hint">' . \snt_kit_esc( $copy['all_hint'] ) . '</p>' : '' );
	$body .= \snt_kit_how_fold( __( 'A signed owner marker on each enrolled browser; readers get no cookie.', 'signal-and-noise-tools' ), $how );
	return \snt_kit_section( __( 'Your devices', 'signal-and-noise-tools' ), $body, \sn_owner_devices_snapshot() );
}

/**
 * "Logged in as <role>: kept out": logged-in visits never count as readers,
 * whatever the role (the collector reads the login cookie at the edge).
 *
 * @return string
 */
function analytics_kept_out_html() {
	if ( ! function_exists( '\wp_get_current_user' ) ) {
		return '';
	}
	$user  = \wp_get_current_user();
	$roles = is_object( $user ) && isset( $user->roles ) ? array_values( (array) $user->roles ) : array();
	if ( array() === $roles ) {
		return '';
	}
	/* translators: %s: the current user's role(s). */
	return '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Logged in as %s: kept out.', 'signal-and-noise-tools' ), implode( ', ', array_map( 'strval', $roles ) ) ) ) . '</p>';
}
