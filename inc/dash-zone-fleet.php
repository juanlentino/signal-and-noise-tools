<?php
/**
 * Signal & Noise — Dashboard fleet zone.
 *
 * Seven component versions and the last deploy, collapsed into one line. A
 * component whose version is null was never probed, which makes the whole zone
 * unknown — "current" is a claim, and an unprobed component cannot support it.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array<string,string|null> $components name => version, null = never probed.
 * @param string                    $last_deploy_ago Human string, may be ''.
 * @return array<string,mixed>
 */
function sn_dash_zone_fleet( array $components, $last_deploy_ago = '' ) {
	$cards   = array();
	$unknown = 0;
	foreach ( $components as $name => $version ) {
		$measured = null !== $version && '' !== $version;
		if ( ! $measured ) {
			$unknown++;
		}
		$cards[] = array(
			'label'     => (string) $name,
			'value'     => $measured ? (string) $version : '—',
			'measured'  => $measured,
			'attention' => false, // a version is never an alarm; drift is reported elsewhere.
		);
	}
	$state = sn_dash_zone_state( $cards );
	$total = count( $components );
	if ( 'unknown' === $state ) {
		$summary = sprintf(
			/* translators: 1: unprobed count, 2: total components */
			__( 'Fleet not measured — %1$d of %2$d never probed', 'signal-and-noise-tools' ),
			$unknown,
			$total
		);
	} else {
		$summary = sprintf(
			/* translators: %d component count */
			__( 'Fleet current — %d components', 'signal-and-noise-tools' ),
			$total
		);
	}
	$detail = '' !== $last_deploy_ago
		? sprintf( /* translators: %s human time */ __( 'deploy %s', 'signal-and-noise-tools' ), $last_deploy_ago )
		: '';
	return array(
		'id'      => 'fleet',
		'state'   => $state,
		'summary' => $summary,
		'detail'  => $detail,
		'cards'   => $cards,
	);
}

/**
 * Component name => version for the fleet zone.
 *
 * A component the probe has never seen returns null, which makes the zone
 * unknown rather than letting an unprobed worker read as current.
 *
 * NOT snt_deploy_status_for(): that takes 'theme'|'plugin' only and returns a
 * STRUCT, so passing it a worker key returns plugin data and the card renders
 * "Array". Worker versions come from snt_deploy_workers_status(), the same
 * source the glance cards use — `live` is the version actually answering.
 *
 * Theme and plugin are NOT in the worker registry; they arrive as the structs
 * already in scope in snt_dashboard_tab_render().
 *
 * @since 11.28.0
 * @param array<string,mixed> $theme   snt_deploy_status_for( 'theme' ) struct.
 * @param array<string,mixed> $plugin  snt_deploy_status_for( 'plugin' ) struct.
 * @param array<int,array>    $workers snt_deploy_workers_status() rows.
 * @return array<string,string|null>
 */
function snt_dashboard_fleet_components( $theme, $plugin, $workers = array() ) {
	$theme_v  = is_array( $theme ) ? (string) ( $theme['current'] ?? '' ) : '';
	$plugin_v = is_array( $plugin ) ? (string) ( $plugin['current'] ?? '' ) : '';

	$out = array(
		'Theme'  => '' !== $theme_v ? $theme_v : null,
		'Plugin' => '' !== $plugin_v ? $plugin_v : null,
	);

	foreach ( $workers as $worker ) {
		if ( ! is_array( $worker ) ) {
			continue;
		}
		$label = (string) ( $worker['label'] ?? '' );
		if ( '' === $label ) {
			continue;
		}
		// `live` is the version the worker actually answered with. Empty means
		// never probed (cold, budget-skipped) — null, not a version.
		$live          = (string) ( $worker['live'] ?? '' );
		$out[ $label ] = '' !== $live ? $live : null;
	}

	return $out;
}
