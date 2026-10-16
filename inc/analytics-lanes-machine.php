<?php
/**
 * SN Traffic Lanes: the machine sensor (the rights-signals aggregate view).
 *
 * Precedence per row, first match wins:
 *   owner_identity 2      owner_agent / owner_token (its row also carries
 *                         first_party, so this goes first)
 *   first_party           own_infra / first_party
 *   control token         claimed_bot / impersonation (never the vendor's
 *                         crawler, so it outranks every proof; no family or
 *                         purpose is kept, as snt_mr_impersonations credits none)
 *   signature valid       verified_bot / web_bot_auth (purpose kept)
 *   verified_matched      verified_bot / cf_verified (purpose kept)
 *   verified, no match    claimed_bot / category_mismatch (family, purpose)
 *   a taxonomy claim      claimed_bot / ua_claim (family, purpose)
 *   unclassified-machine  unknown_machine / no_claim
 * A code-3 read (invalid token) keeps its lane; its reason becomes
 * owner_token_invalid. Verification and impersonation are the plugin's own
 * helpers (snt_mr_row_verified_matched, snt_mr_is_impersonation), never a
 * copy. WebMCP tool calls are not reads: snt_mr_fetch() splits them off.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One normalized aggregate row's lane key. PURE.
 *
 * @param array $row snt_mr_normalize_rows() row.
 * @return array{lane:string,reason:string,agent:string,family:string,purpose:string}
 */
function sn_lanes_machine_key( array $row ) {
	$id      = $row['owner_identity'] ?? null;
	$family  = (string) ( $row['family'] ?? '' );
	$purpose = (string) ( $row['purpose'] ?? '' );
	$agent   = in_array( $id, array( 2, 3 ), true ) ? sn_lanes_agent_label( $row['owner_agent'] ?? 0 ) : '';
	$k       = array( 'lane' => '', 'reason' => '', 'agent' => $agent, 'family' => '', 'purpose' => '' );
	if ( 2 === $id ) {
		return array( 'lane' => 'owner_agent', 'reason' => 'owner_token' ) + $k;
	}
	if ( ! empty( $row['first_party'] ) ) {
		$k = array( 'lane' => 'own_infra', 'reason' => 'first_party' ) + $k;
	} elseif ( snt_mr_is_impersonation( $row ) ) {
		// Credited to no family and no purpose, as every house figure does
		// (snt_mr_impersonations): no vendor crawls under a control token.
		$k = array( 'lane' => 'claimed_bot', 'reason' => 'impersonation' ) + $k;
	} elseif ( 'valid' === (string) ( $row['signed_agent'] ?? '' ) ) {
		$k = array( 'lane' => 'verified_bot', 'reason' => 'web_bot_auth', 'purpose' => $purpose ) + $k;
	} elseif ( snt_mr_row_verified_matched( $row ) ) {
		$k = array( 'lane' => 'verified_bot', 'reason' => 'cf_verified', 'purpose' => $purpose ) + $k;
	} elseif ( 'verified' === snt_mr_row_verification( $row ) ) {
		$k = array( 'lane' => 'claimed_bot', 'reason' => 'category_mismatch', 'family' => $family, 'purpose' => $purpose ) + $k;
	} elseif ( 'unclassified-machine' !== $family ) {
		$k = array( 'lane' => 'claimed_bot', 'reason' => 'ua_claim', 'family' => $family, 'purpose' => $purpose ) + $k;
	} else {
		$k = array( 'lane' => 'unknown_machine', 'reason' => 'no_claim' ) + $k;
	}
	if ( 3 === $id ) {
		$k['reason'] = 'owner_token_invalid';
	}
	return $k;
}

/**
 * The machine lanes per sensor day: reads summed per lane key. PURE.
 *
 * @param array    $rows Normalized aggregate rows.
 * @param string[] $days Days to keep.
 * @return array<string,array<int,array>> Day => lane rows.
 */
function sn_lanes_machine_derive( array $rows, array $days ) {
	$keep = array_flip( $days );
	$out  = array();
	foreach ( $rows as $row ) {
		$day = (string) ( $row['day'] ?? '' );
		if ( ! isset( $keep[ $day ] ) ) {
			continue;
		}
		$k = sn_lanes_machine_key( (array) $row );
		$i = implode( '|', $k );
		$c = $out[ $day ][ $i ] ?? $k + array( 'hits' => 0, 'visits' => null, 'visitor_days' => null );
		$c['hits']        += max( 0, (int) ( $row['hits'] ?? 0 ) );
		$out[ $day ][ $i ] = $c;
	}
	return array_map( 'array_values', $out );
}

/**
 * The first day a row carries the owner identity column: machine lanes_since. PURE.
 *
 * @param array $rows Normalized aggregate rows.
 * @return string '' when none does.
 */
function sn_lanes_machine_since( array $rows ) {
	$days = array();
	foreach ( $rows as $row ) {
		if ( null !== ( $row['owner_identity'] ?? null ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $row['day'] ?? '' ) ) ) {
			$days[] = (string) $row['day'];
		}
	}
	return array() === $days ? '' : min( $days );
}

/**
 * The machine half of the nightly pass. The view's window is rolling (now
 * minus N days), so its oldest date and today are partial: nine days are read
 * and the seven complete UTC days before today are written.
 *
 * @return bool Whether days were written.
 */
function sn_lanes_machine_run() {
	if ( ! function_exists( 'snt_mr_fetch' ) ) {
		return false;
	}
	$read = snt_mr_fetch( SN_LANES_WINDOW_DAYS + 2, 'aggregate' );
	if ( empty( $read['ok'] ) || ! empty( $read['truncated'] ) || empty( $read['rows'] ) ) {
		error_log( '[sn-lanes] machine: the sensor read failed, was truncated or was empty; the stored days are kept' );
		return sn_lanes_last_run( 'machine', empty( $read['ok'] ) ? 'read_failed' : ( ! empty( $read['truncated'] ) ? 'truncated' : 'empty' ) );
	}
	$since = sn_lanes_machine_since( $read['rows'] );
	if ( '' !== $since ) {
		sn_lanes_since( 'machine', $since );
	}
	$days = sn_lanes_complete_days( '' );
	$ok = sn_lanes_store_days( 'machine', $days, sn_lanes_machine_derive( $read['rows'], $days ) );
	return sn_lanes_last_run( 'machine', $ok ? 'wrote' : 'not_written' );
}
