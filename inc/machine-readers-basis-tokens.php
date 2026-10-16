<?php
/**
 * Signal & Noise Tools: control tokens in the readings (2026.10.1). The
 * reads that presented a robots.txt control token, listed apart; the purpose
 * label (unknown reads as unmapped); and the strip's figures with the tokens
 * and this site's own tools taken out. Uses the constants in
 * inc/machine-readers-basis.php, which loads this file. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hits presenting a control token, per token: never the vendor's crawler.
 *
 * @param array $rows Aggregate rows.
 * @return array<int,array{agent:string,token:string,hits:int,networks:array,basis:string}> Hits descending.
 */
function snt_mr_impersonations( array $rows ) {
	$by = array();
	foreach ( $rows as $row ) {
		if ( ! snt_mr_is_impersonation( $row ) ) {
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
		$out[] = array( 'agent' => (string) $agent, 'token' => SN_MR_CONTROL_TOKENS[ $agent ], 'hits' => (int) $a['hits'], 'networks' => $list, 'basis' => 'claimed' );
	}
	usort( $out, static fn( $x, $y ) => $y['hits'] <=> $x['hits'] );
	return $out;
}

/**
 * How a purpose reads on screen. The stored value `unknown` means the taxonomy
 * has no entry for the reader, so it displays as "unmapped"; the key is
 * unchanged everywhere it is stored or returned.
 *
 * @param string $purpose Stored purpose.
 * @return string
 */
function snt_mr_purpose_label( $purpose ) {
	$purpose = (string) $purpose;
	return ( '' === $purpose || 'unknown' === $purpose ) ? 'unmapped' : $purpose;
}
