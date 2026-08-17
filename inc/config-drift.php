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
		if ( preg_match( '/(?:^|\.)(?:token|secret|password|api_key|private_key|read_token)$/i', $path ) ) {
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
