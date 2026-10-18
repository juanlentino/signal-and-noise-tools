<?php
/**
 * Signal & Noise Tools: impostor reads, counted but not credited.
 *
 * An impostor is a hit that claims an agent Cloudflare verifies when genuine
 * (the taxonomy's cf_verified, which the sensor stamps on each aggregate row,
 * rights-signals taxonomy 1.5.0) and that Cloudflare did NOT verify, on a day
 * the sensor recorded verification. Like a control token it stays in `total`
 * and is credited to no vendor, family or purpose. Unlike one it is a rule's
 * call, not the client's word, so its basis is `inferred`.
 *
 * Never an impostor: a verified hit (whatever its network: Claude-User from
 * Google Cloud is real), an unverifiable one (before the since date), this
 * site's own tools, and any agent the taxonomy does not mark. A sensor that
 * sends no cf_verified reads false, so nothing is reclassified until it does.
 * Loaded by inc/machine-readers-basis.php. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a row is an impostor read.
 *
 * @param array $row Aggregate row (agent, cf_verified, day, verified_bot, first_party).
 * @return bool
 */
function snt_mr_is_impostor( $row ) {
	return is_array( $row )
		&& ! empty( $row['cf_verified'] )
		&& empty( $row['first_party'] )
		&& ! snt_mr_is_impersonation( $row )
		&& 'unverified' === snt_mr_row_verification( $row );
}

/**
 * How a row is credited: `credited` to its vendor, family and purpose, or
 * counted only, as a `control_token` or an `impostor`.
 *
 * @param array $row Aggregate row.
 * @return string credited|control_token|impostor
 */
function snt_mr_credit_class( $row ) {
	if ( snt_mr_is_impersonation( $row ) ) {
		return 'control_token';
	}
	return snt_mr_is_impostor( $row ) ? 'impostor' : 'credited';
}

/**
 * Whether a row is credited to its vendor, family and purpose.
 *
 * @param array $row Aggregate row.
 * @return bool
 */
function snt_mr_is_credited( $row ) {
	return is_array( $row ) && 'credited' === snt_mr_credit_class( $row );
}

/**
 * Impostor hits, per claimed agent, with the networks they came from.
 *
 * @param array $rows Aggregate rows.
 * @return array<int,array{agent:string,hits:int,networks:array,basis:string}> Hits descending.
 */
function snt_mr_impostors( array $rows ) {
	$by = array();
	foreach ( $rows as $row ) {
		if ( ! snt_mr_is_impostor( $row ) ) {
			continue;
		}
		$agent = (string) $row['agent'];
		$hits  = max( 0, (int) ( $row['hits'] ?? 0 ) );
		$net   = (string) ( $row['network'] ?? '' );
		$by[ $agent ]['hits'] = ( $by[ $agent ]['hits'] ?? 0 ) + $hits;
		if ( '' !== $net ) {
			$by[ $agent ]['networks'][ $net ] = ( $by[ $agent ]['networks'][ $net ] ?? 0 ) + $hits;
		}
	}
	$out = array();
	foreach ( $by as $agent => $a ) {
		$nets = (array) ( $a['networks'] ?? array() );
		arsort( $nets );
		$list = array();
		foreach ( array_slice( $nets, 0, 5, true ) as $net => $h ) {
			$list[] = array( 'network' => (string) $net, 'hits' => (int) $h );
		}
		$out[] = array( 'agent' => (string) $agent, 'hits' => (int) $a['hits'], 'networks' => $list, 'basis' => 'inferred' );
	}
	usort( $out, static fn( $x, $y ) => $y['hits'] <=> $x['hits'] ?: strcmp( $x['agent'], $y['agent'] ) );
	return $out;
}

/**
 * The impostor hits in a window, as one number.
 *
 * @param array|null $impostors snt_mr_impostors(), or null when not measured.
 * @return int|null
 */
function snt_mr_impostor_total( $impostors ) {
	return null === $impostors ? null : array_sum( array_map( static fn( $i ) => (int) ( $i['hits'] ?? 0 ), (array) $impostors ) );
}
