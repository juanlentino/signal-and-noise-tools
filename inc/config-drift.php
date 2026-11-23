<?php
/**
 * Signal & Noise Tools — effective settings snapshot + configuration drift.
 *
 * The acknowledged baseline is durable option state, not a transient. It only
 * moves when the owner acknowledges it or the plugin version changes; ordinary
 * settings saves therefore remain visible between sessions.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SNT_CONFIG_DRIFT_SNAPSHOT_OPTION', 'snt_config_drift_snapshot' );

/** Flatten a settings tree to stable dot paths. Empty arrays remain values. */
function snt_config_drift_flatten( $value, $prefix = '' ) {
	if ( ! is_array( $value ) || empty( $value ) ) {
		return '' === $prefix ? array() : array( $prefix => $value );
	}
	$flat = array();
	foreach ( $value as $key => $child ) {
		$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
		$flat = array_merge( $flat, snt_config_drift_flatten( $child, $path ) );
	}
	ksort( $flat, SORT_STRING );
	return $flat;
}

/**
 * Current effective settings surface: schema defaults plus sparse stored state.
 * Secret-like leaves are hashed so a changed credential is detected without
 * copying the credential into a second option.
 */
function snt_config_drift_current_values() {
	$defaults = function_exists( 'sn_settings_defaults' ) ? sn_settings_defaults() : array();
	$stored   = get_option( defined( 'SN_SETTINGS_OPTION' ) ? SN_SETTINGS_OPTION : 'sn_settings', array() );
	$merged   = array_replace_recursive( $defaults, is_array( $stored ) ? $stored : array() );
	$flat     = snt_config_drift_flatten( $merged );
	foreach ( $flat as $path => $value ) {
		// Suffix match, not exact-leaf match: credential-like leaves are named
		// with prefixes (read_token, uptime_kuma_push_url), so requiring the
		// whole leaf name would store some secrets in plaintext. A false
		// positive only hashes a non-secret; a false negative copies a secret
		// into a second option. R6b added `credential` for the GSC
		// service-account key — a PEM private key is the worst possible
		// thing for this snapshot to copy in plaintext.
		if ( preg_match( '/(?:token|secret|password|api_key|private_key|push_url|credential)$/i', $path ) ) {
			$flat[ $path ] = 'sha256:' . hash( 'sha256', serialize( $value ) );
		}
	}
	return $flat;
}

/** Pure comparison returning the added, removed, and changed dot-path keys. */
function snt_config_drift_diff( $before, $after ) {
	$before = snt_config_drift_flatten( is_array( $before ) ? $before : array() );
	$after  = snt_config_drift_flatten( is_array( $after ) ? $after : array() );
	$added   = array_keys( array_diff_key( $after, $before ) );
	$removed = array_keys( array_diff_key( $before, $after ) );
	$changed = array();
	foreach ( array_intersect_key( $after, $before ) as $key => $value ) {
		if ( $before[ $key ] !== $value ) {
			$changed[] = $key;
		}
	}
	sort( $added, SORT_STRING );
	sort( $removed, SORT_STRING );
	sort( $changed, SORT_STRING );
	return array( 'added' => $added, 'removed' => $removed, 'changed' => $changed );
}

/** Replace the baseline after an explicit owner acknowledgement. */
function snt_config_drift_acknowledge() {
	$snapshot = array(
		'version'     => defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '',
		'captured_at' => time(),
		'values'      => snt_config_drift_current_values(),
	);
	update_option( SNT_CONFIG_DRIFT_SNAPSHOT_OPTION, $snapshot, false );
	return $snapshot;
}

/** Seed once, and reset on a real plugin-version transition. */
function snt_config_drift_snapshot_lifecycle() {
	$snapshot = get_option( SNT_CONFIG_DRIFT_SNAPSHOT_OPTION, null );
	$version  = defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '';
	if ( ! is_array( $snapshot ) || ! isset( $snapshot['values'] ) || (string) ( $snapshot['version'] ?? '' ) !== $version ) {
		snt_config_drift_acknowledge();
	}
}
add_action( 'init', 'snt_config_drift_snapshot_lifecycle', 5 );

/** Owner-safe drift fact: keys and counts, never stored values. */
function snt_config_drift_status() {
	snt_config_drift_snapshot_lifecycle();
	$snapshot = get_option( SNT_CONFIG_DRIFT_SNAPSHOT_OPTION, array() );
	$diff     = snt_config_drift_diff( $snapshot['values'] ?? array(), snt_config_drift_current_values() );
	$count    = count( $diff['added'] ) + count( $diff['removed'] ) + count( $diff['changed'] );
	return array(
		'has_drift'       => $count > 0,
		'count'           => $count,
		'added'           => $diff['added'],
		'removed'         => $diff['removed'],
		'changed'         => $diff['changed'],
		'snapshot_version' => (string) ( $snapshot['version'] ?? '' ),
		'captured_at'     => (int) ( $snapshot['captured_at'] ?? 0 ),
	);
}

/**
 * The box Connections > Cron keeps. Drift is a warning about operations, not a
 * notification setting, so it stayed here when the brief's email switches
 * moved to Settings > Notifications and reports. Its form posts
 * morning_brief_save carrying snt_config_drift_acknowledge on every submit,
 * and the handler returns on that field before it writes a switch.
 */
function snt_config_drift_render_cron_box() {
	$drift = snt_config_drift_status();
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Configuration drift', 'signal-and-noise-tools' ) . '</h2>';
	if ( empty( $drift['has_drift'] ) ) {
		echo '<p class="sn-field-helper">' . esc_html__( 'No settings differ from the acknowledged snapshot.', 'signal-and-noise-tools' ) . '</p>';
	} else {
		echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Configuration drift: %d settings differ from the acknowledged snapshot.', (int) $drift['count'] ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '"><input type="hidden" name="action" value="sn_morning_brief_save" /><input type="hidden" name="snt_config_drift_acknowledge" value="1" />';
		wp_nonce_field( 'sn_morning_brief_save' );
		echo '<div class="sn-fieldset-actions"><button type="submit" class="button">' . esc_html__( 'Acknowledge current settings', 'signal-and-noise-tools' ) . '</button></div></form>';
	}
	if ( function_exists( 'snt_notifications_moved_line' ) ) {
		snt_notifications_moved_line( __( 'The morning brief and alert email switches moved to', 'signal-and-noise-tools' ) );
	}
	echo '</div>';
}
add_action( 'sn_admin_cron_tab', 'snt_config_drift_render_cron_box', 20 );
