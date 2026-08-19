<?php
/**
 * Signal & Noise — Dashboard zones: contract, state, renderer.
 *
 * A zone is a group of glance cards that answers one question. Its STATE decides
 * whether it takes space: `ok` collapses to a line, `attention` expands and leads,
 * `unknown` collapses but says it was never measured.
 *
 * `unknown` is derived FIRST on purpose. A zone holding an unmeasured probe and a
 * real warning reports unknown, because you cannot triage what you did not measure.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The three zone states, most-urgent last-resort first. */
const SN_DASH_STATES = array( 'unknown', 'attention', 'ok' );

/**
 * Derive a zone's state from its cards. Pure.
 *
 * A card is UNMEASURED when it carries `'measured' => false`. That is the only
 * unknown signal — a card with value '0' and no `measured` key ran and returned
 * zero, which is measured. array_key_exists, never a falsy check.
 *
 * @param array<int,array<string,mixed>> $cards
 * @return string One of SN_DASH_STATES.
 */
function sn_dash_zone_state( array $cards ) {
	$unknown   = false;
	$attention = false;
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		if ( array_key_exists( 'measured', $card ) && false === $card['measured'] ) {
			$unknown = true;
			continue;
		}
		$kind = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		// Same opt-out the existing attention sort honours: a card may look amber
		// without asking to be promoted (a cold probe is unknown, not broken).
		$wants = ! array_key_exists( 'attention', $card ) || false !== $card['attention'];
		if ( $wants && ( 'err' === $kind || 'warn' === $kind ) ) {
			$attention = true;
		}
	}
	if ( $unknown ) {
		return 'unknown';
	}
	return $attention ? 'attention' : 'ok';
}

/**
 * Should this zone render expanded?
 *
 * An `attention` zone is ALWAYS open — a pin can force a zone open, never closed.
 * Pinning is a personal view preference and must not be able to hide a problem.
 * Pure.
 *
 * @param array<string,mixed> $zone
 * @param string[]            $pins Zone ids the current user has pinned open.
 * @return bool
 */
function sn_dash_zone_is_open( array $zone, array $pins ) {
	$state = isset( $zone['state'] ) ? (string) $zone['state'] : '';
	if ( 'attention' === $state ) {
		return true;
	}
	$id = isset( $zone['id'] ) ? (string) $zone['id'] : '';
	return '' !== $id && in_array( $id, $pins, true );
}
