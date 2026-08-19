<?php
/**
 * Signal & Noise — Dashboard attention zone.
 *
 * Health, cron, caches, provenance and login guard collapse into one question:
 * is anything wrong? Takes already-fetched glance cards so the builder stays pure.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array<int,array<string,mixed>> $cards
 * @return array<string,mixed>
 */
function sn_dash_zone_attention( array $cards ) {
	$state = sn_dash_zone_state( $cards );
	$needy = 0;
	foreach ( $cards as $c ) {
		$kind  = isset( $c['pill']['kind'] ) ? (string) $c['pill']['kind'] : '';
		// Same predicate sn_dash_zone_state() uses, so the COUNT and the STATE can
		// never disagree about what counts.
		$wants = sn_admin_card_wants_attention( $c );
		if ( $wants && ( 'err' === $kind || 'warn' === $kind ) ) {
			$needy++;
		}
	}
	if ( 'attention' === $state ) {
		$summary = sprintf(
			/* translators: %d count of checks needing attention */
			_n( '%d needs attention', '%d need attention', $needy, 'signal-and-noise-tools' ),
			$needy
		);
	} elseif ( 'unknown' === $state ) {
		$summary = __( 'Not measured', 'signal-and-noise-tools' );
	} else {
		$summary = __( 'Nothing needs attention', 'signal-and-noise-tools' );
	}
	return array(
		'id'      => 'attention',
		'state'   => $state,
		'summary' => $summary,
		'detail'  => __( 'health, cron, caches, provenance, login guard', 'signal-and-noise-tools' ),
		'cards'   => $cards,
	);
}
