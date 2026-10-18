<?php
/**
 * Signal & Noise Tools: impostor reads, counted but not credited.
 *
 * An impostor is an unverified hit, on a day the sensor recorded
 * verification, that a rule shows is not the agent it claims, for one reason
 * (inc/machine-readers-basis-ladder.php decides which):
 *   cf_directory      Cloudflare's directory lists the agent (cf_verified,
 *                     taxonomy 1.5.0) and Cloudflare did not verify it;
 *   vendor_ranges     the IP is outside the vendor's published ranges AND
 *                     the network is not one of the vendor's (taxonomy 1.6.0);
 *   off_home_network  the network is not one of the vendor's;
 *   rotator_network   the network presents many vendors' agents, none verified.
 * Like a control token it stays in `total` and is credited to no vendor,
 * family or purpose. Unlike one it is a rule's call, so its basis is `inferred`.
 *
 * Never an impostor: a verified hit, by Cloudflare or the vendor's ranges
 * (whatever its network: Claude-User from Google Cloud is real), an
 * unverifiable one, and this site's own tools. Fields a sensor does not send
 * read as not measured. Loaded by inc/machine-readers-basis.php. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a row is an impostor read, by any reason (snt_mr_credit_verdict).
 *
 * @param array $row Aggregate row.
 * @param array $ctx snt_mr_credit_context() of the fold. Required: a call site
 *                   that forgets it fails loudly rather than under-counting.
 * @return bool
 */
function snt_mr_is_impostor( $row, array $ctx ) {
	return is_array( $row ) && 'impostor' === snt_mr_credit_verdict( $row, $ctx )['class'];
}

/**
 * How a row is credited: `credited` to its vendor, family and purpose, or
 * counted only, as a `control_token` or an `impostor`.
 *
 * @param array $row Aggregate row.
 * @param array $ctx snt_mr_credit_context() of the fold.
 * @return string credited|control_token|impostor
 */
function snt_mr_credit_class( $row, array $ctx ) {
	return snt_mr_credit_verdict( $row, $ctx )['class'];
}

/**
 * Whether a row is credited to its vendor, family and purpose.
 *
 * @param array $row Aggregate row.
 * @param array $ctx snt_mr_credit_context() of the fold.
 * @return bool
 */
function snt_mr_is_credited( $row, array $ctx ) {
	return is_array( $row ) && 'credited' === snt_mr_credit_class( $row, $ctx );
}

/**
 * The credited rows of a fold. The context is built from these same rows
 * unless the caller passes the one it already holds for the whole window.
 *
 * @param array      $rows Aggregate rows.
 * @param array|null $ctx  snt_mr_credit_context(), or null to build it from $rows.
 * @return array
 */
function snt_mr_credited_rows( array $rows, $ctx = null ) {
	$ctx = is_array( $ctx ) ? $ctx : snt_mr_credit_context( $rows );
	return array_values( array_filter( $rows, static fn( $r ) => snt_mr_is_credited( $r, $ctx ) ) );
}

/**
 * Impostor hits, per claimed agent, with the networks they came from and a
 * count per reason (cf_directory, vendor_ranges, off_home_network,
 * rotator_network). The context is the fold's own.
 *
 * @param array $rows Aggregate rows.
 * @return array<int,array{agent:string,hits:int,reasons:array<string,int>,networks:array,basis:string}> Hits descending.
 */
function snt_mr_impostors( array $rows ) {
	$ctx = snt_mr_credit_context( $rows );
	$by  = array();
	foreach ( $rows as $row ) {
		$v = snt_mr_credit_verdict( $row, $ctx );
		if ( 'impostor' !== $v['class'] ) {
			continue;
		}
		$agent = (string) $row['agent'];
		$hits  = max( 0, (int) ( $row['hits'] ?? 0 ) );
		$net   = (string) ( $row['network'] ?? '' );
		$by[ $agent ]['hits']                     = ( $by[ $agent ]['hits'] ?? 0 ) + $hits;
		$by[ $agent ]['reasons'][ $v['reason'] ]  = ( $by[ $agent ]['reasons'][ $v['reason'] ] ?? 0 ) + $hits;
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
		$reasons = array_intersect_key( array_fill_keys( SN_MR_IMPOSTOR_REASONS, 0 ), $a['reasons'] );
		foreach ( $a['reasons'] as $r => $h ) {
			$reasons[ $r ] = (int) $h;
		}
		$out[] = array( 'agent' => (string) $agent, 'hits' => (int) $a['hits'], 'reasons' => $reasons, 'networks' => $list, 'basis' => 'inferred' );
	}
	usort( $out, static fn( $x, $y ) => $y['hits'] <=> $x['hits'] ?: strcmp( $x['agent'], $y['agent'] ) );
	return $out;
}

/**
 * The impostor hits per reason, summed across agents, in ladder order.
 *
 * @param array|null $impostors snt_mr_impostors(), or null when not measured.
 * @return array<string,int>|null Only reasons with hits.
 */
function snt_mr_impostor_reasons( $impostors ) {
	if ( null === $impostors ) {
		return null;
	}
	$sum = array_fill_keys( SN_MR_IMPOSTOR_REASONS, 0 );
	foreach ( (array) $impostors as $i ) {
		foreach ( (array) ( $i['reasons'] ?? array() ) as $r => $h ) {
			$sum[ (string) $r ] = ( $sum[ (string) $r ] ?? 0 ) + (int) $h;
		}
	}
	return array_filter( $sum );
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
