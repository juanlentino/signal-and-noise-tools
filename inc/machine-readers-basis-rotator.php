<?php
/**
 * Signal & Noise Tools: rotator networks, and the credit context a fold
 * threads through every attribution call.
 *
 * A rotator network is one that, within the rows being folded, presents at
 * least SN_MR_ROTATOR_MIN_AGENTS distinct vendor agents (the taxonomy names a
 * vendor; first-party rows, control tokens and the vendorless dev and generic
 * agents do not count), each of them unverified from that network: no read of
 * that agent from that network was verified, by Cloudflare or by the vendor's
 * own IP ranges. Unverifiable rows (before the since date) and rows with no
 * network neither count toward it nor are caught by it.
 *
 * It is window-dependent, so it is computed once per fold from the fold's own
 * rows and passed explicitly (snt_mr_credit_context) to every per-row call;
 * there is no global. Loaded by inc/machine-readers-basis.php. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Five. One rotating operator was seen (2026-10-18) presenting about twenty
 * vendors' agents from "Google LLC" with none verified, while the busiest
 * genuine cloud networks present at most two or three unverified vendor
 * agents (Amazon: PerplexityBot and CCBot; Charter: Claude-User and GPTBot).
 * A genuine crawler fleet runs one vendor's agents, so five distinct vendor
 * agents, none verified, is a pattern a vendor does not make; below five, a
 * shared cloud network hosting a few real crawlers stays credited.
 */
const SN_MR_ROTATOR_MIN_AGENTS = 5;

/**
 * The credit context for one fold: the rotator networks of its rows.
 *
 * @param array $rows The fold's aggregate rows (the whole window, unfiltered).
 * @return array{rotators:array<string,true>}
 */
function snt_mr_credit_context( array $rows ) {
	$seen = array(); // network => agent => [verified hits, unverified hits]
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || ! empty( $r['first_party'] ) || '' === (string) ( $r['vendor'] ?? '' ) || snt_mr_is_impersonation( $r ) ) {
			continue;
		}
		$net   = (string) ( $r['network'] ?? '' );
		$class = snt_mr_row_verification( $r );
		if ( '' === $net || 'unverifiable' === $class ) {
			continue;
		}
		$agent                    = (string) ( $r['agent'] ?? '' );
		$tally                    = $seen[ $net ][ $agent ] ?? array( 0, 0 );
		$tally[ 'verified' === $class ? 0 : 1 ] += max( 1, (int) ( $r['hits'] ?? 0 ) );
		$seen[ $net ][ $agent ]   = $tally;
	}
	$rotators = array();
	foreach ( $seen as $net => $agents ) {
		$unverified = array_filter( $agents, static fn( $t ) => 0 === $t[0] && $t[1] > 0 );
		if ( count( $unverified ) >= SN_MR_ROTATOR_MIN_AGENTS ) {
			$rotators[ (string) $net ] = true;
		}
	}
	return array( 'rotators' => $rotators );
}

/**
 * Whether a row came from one of the fold's rotator networks.
 *
 * @param array $row Aggregate row.
 * @param array $ctx snt_mr_credit_context().
 * @return bool
 */
function snt_mr_on_rotator( $row, array $ctx ) {
	$net = (string) ( $row['network'] ?? '' );
	return '' !== $net && isset( $ctx['rotators'][ $net ] );
}
