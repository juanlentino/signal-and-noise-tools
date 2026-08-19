<?php
/**
 * Signal & Noise — Dashboard zone pins.
 *
 * A pin is a PERSONAL view preference, so it lives in user meta rather than a
 * site option. It can force a zone open; sn_dash_zone_is_open() guarantees it can
 * never force one closed.
 *
 * Zone ids are validated against an allowlist because the id is echoed into a
 * data attribute and used as a storage key.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_DASH_PIN_META  = 'sn_dash_pins';
const SN_DASH_ZONE_IDS  = array( 'attention', 'fleet', 'measurement' );

/**
 * The zone ids this user has pinned open.
 *
 * @param int $user_id
 * @return string[]
 */
function sn_dash_pins( $user_id ) {
	$raw = get_user_meta( (int) $user_id, SN_DASH_PIN_META, true );
	if ( ! is_array( $raw ) ) {
		return array();
	}
	return array_values( array_intersect( SN_DASH_ZONE_IDS, $raw ) );
}

/**
 * Pin or unpin one zone for one user.
 *
 * @param int    $user_id
 * @param string $zone_id
 * @param bool   $pinned
 * @return bool True when the preference was written.
 */
function sn_dash_set_pin( $user_id, $zone_id, $pinned ) {
	$zone_id = (string) $zone_id;
	if ( ! in_array( $zone_id, SN_DASH_ZONE_IDS, true ) ) {
		return false;
	}
	$pins = sn_dash_pins( $user_id );
	$next = $pins;
	if ( $pinned ) {
		if ( ! in_array( $zone_id, $next, true ) ) {
			$next[] = $zone_id;
		}
	} else {
		$next = array_values( array_diff( $next, array( $zone_id ) ) );
	}

	// WordPress reports an UNCHANGED write as false — update_metadata() returns
	// false when the stored value already equals the new one. That is not a
	// failure: the caller asked for a state, and the state holds. Returning the
	// raw result would make the REST route report a failed pin for a zone that
	// is pinned, so short-circuit before writing.
	if ( $next === $pins ) {
		return true;
	}

	return (bool) update_user_meta( (int) $user_id, SN_DASH_PIN_META, $next );
}
