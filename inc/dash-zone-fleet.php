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
