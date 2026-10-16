<?php
/**
 * Signal & Noise Tools: the "Owner devices" card under Measurement ›
 * Analytics, beside "Exclude my own visits" (SN Traffic Lanes, Phase 1).
 *
 * One view model, two painters: the classic card below and the native twin
 * (apps/sn-dashboard/parts/leaves/monitoring-analytics-owner.php). Both show
 * label, enrolled, last seen and whether THIS browser is enrolled; neither
 * ever shows a device id, a marker or a key. A row is revoked by its index
 * and a nonce bound to the id stored there (sn_owner_row_nonce_action()).
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The words both surfaces say.
 *
 * @return array<string,string>
 */
function sn_owner_devices_copy() {
	return array(
		'heading'   => __( 'Owner devices', 'signal-and-noise-tools' ),
		'intro'     => __( 'Enroll each browser you read the site with. It carries a signed owner marker, so its visits are stored as yours and never counted as readers, even on pages served from cache. Readers get no cookie.', 'signal-and-noise-tools' ),
		'no_key'    => __( 'Generate the owner device key in Connections › Credentials first, and set it on the analytics Worker.', 'signal-and-noise-tools' ),
		'last_seen' => __( 'Last seen is the last wp-admin visit that renewed the marker, at most once a day.', 'signal-and-noise-tools' ),
		'none'      => __( 'No devices enrolled.', 'signal-and-noise-tools' ),
		'label'     => __( 'Label for this browser', 'signal-and-noise-tools' ),
		'enroll'    => __( 'Enroll this browser', 'signal-and-noise-tools' ),
		'revoke'    => __( 'Revoke', 'signal-and-noise-tools' ),
		'all'       => __( 'Revoke all devices', 'signal-and-noise-tools' ),
		'all_hint'  => __( 'Every enrolled device stops counting as yours within one Worker refresh. To also retire the key, Generate a new one in Connections › Credentials.', 'signal-and-noise-tools' ),
	);
}

/**
 * The view model. Never carries an id, a marker or a key.
 *
 * @param int $now Unix time.
 * @return array{key_ready:bool,this:string,rows:array<int,array<string,mixed>>}
 */
function sn_owner_devices_view( $now ) {
	$mine   = sn_owner_this_device( $now );
	$format = (string) get_option( 'date_format', 'F j, Y' );
	$rows   = array();
	foreach ( sn_owner_devices() as $i => $d ) {
		$rows[] = array(
			'index'     => $i,
			'nonce'     => wp_create_nonce( sn_owner_row_nonce_action( $i, (string) ( $d['id'] ?? '' ) ) ),
			'label'     => (string) ( $d['label'] ?? '' ),
			'enrolled'  => wp_date( $format, (int) ( $d['enrolled'] ?? 0 ) ),
			'last_seen' => wp_date( $format, (int) ( $d['last_seen'] ?? 0 ) ),
			'this'      => null !== $mine && $mine['index'] === $i,
		);
	}
	return array(
		'key_ready' => sn_owner_key_ok( sn_owner_device_keys()[0] ),
		/* translators: %s: device label. */
		'this'      => null !== $mine ? sprintf( __( 'This browser is enrolled as "%s".', 'signal-and-noise-tools' ), (string) $mine['device']['label'] ) : __( 'This browser is not enrolled.', 'signal-and-noise-tools' ),
		'rows'      => $rows,
	);
}

/** The fold's one-line snapshot. */
function sn_owner_devices_snapshot() {
	$n = count( sn_owner_devices() );
	/* translators: %d: number of enrolled devices. */
	return 0 === $n ? __( 'None enrolled', 'signal-and-noise-tools' ) : sprintf( _n( '%d device enrolled', '%d devices enrolled', $n, 'signal-and-noise-tools' ), $n );
}

/** One classic admin-post form around $inner, for $action. */
function sn_owner_devices_classic_form( $action, $inner, $button, $confirm = '' ) {
	$onsubmit = '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '';
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '"' . $onsubmit . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $onsubmit is built from esc_attr() above.
	wp_nonce_field( 'sn_' . $action );
	echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass markup built from esc_* calls.
	echo '<button type="submit" name="action" value="sn_' . esc_attr( $action ) . '" class="button">' . esc_html( $button ) . '</button></form>';
}

/** The classic card. */
function snt_analytics_render_owner_devices() {
	$copy = sn_owner_devices_copy();
	$view = sn_owner_devices_view( time() );
	echo '<div class="sn-an-settings"><h3 class="sn-fieldset-h">' . esc_html( $copy['heading'] ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html( $copy['intro'] ) . '</p>';
	echo '<p class="sn-an-status"><strong>' . esc_html( $view['this'] ) . '</strong></p>';
	if ( array() === $view['rows'] ) {
		echo '<p class="sn-an-empty">' . esc_html( $copy['none'] ) . '</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Device</th><th>Enrolled</th><th>Last seen</th><th><span class="screen-reader-text">Action</span></th></tr></thead><tbody>';
		foreach ( $view['rows'] as $r ) {
			echo '<tr><td>' . esc_html( $r['label'] ) . ( $r['this'] ? ' ' . esc_html__( '(this browser)', 'signal-and-noise-tools' ) : '' ) . '</td><td>' . esc_html( $r['enrolled'] ) . '</td><td>' . esc_html( $r['last_seen'] ) . '</td><td>';
			/* translators: %s: device label. */
			sn_owner_devices_classic_form( 'owner_device_revoke', '<input type="hidden" name="sn_owner_row" value="' . esc_attr( (string) $r['index'] ) . '"><input type="hidden" name="sn_owner_row_nonce" value="' . esc_attr( $r['nonce'] ) . '">', $copy['revoke'], sprintf( __( 'Revoke "%s"? Its visits will no longer be marked as yours.', 'signal-and-noise-tools' ), $r['label'] ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '<p class="sn-an-settings-help">' . esc_html( $copy['last_seen'] ) . '</p>';
	if ( ! $view['key_ready'] ) {
		echo '<p class="sn-an-status">' . esc_html( $copy['no_key'] ) . '</p>';
	} else {
		sn_owner_devices_classic_form( 'owner_device_enroll', '<p><label for="sn_owner_device_label">' . esc_html( $copy['label'] ) . '</label><br><input type="text" id="sn_owner_device_label" name="sn_owner_device_label" maxlength="' . esc_attr( (string) SN_OWNER_LABEL_MAX ) . '" required></p>', $copy['enroll'] );
	}
	if ( array() !== $view['rows'] ) {
		echo '<p class="sn-an-settings-help">' . esc_html( $copy['all_hint'] ) . '</p>';
		sn_owner_devices_classic_form( 'owner_device_revoke_all', '', $copy['all'], $copy['all_hint'] );
	}
	echo '</div>';
}
