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

/**
 * REST handler for the pin toggle.
 *
 * The two failure modes are kept APART on purpose. `sn_dash_set_pin()` returns
 * false for an unknown zone id (the caller's fault, 400) and for a failed write
 * (ours, 500). Collapsing both into "unknown zone" would send someone hunting a
 * typo in a zone id that was spelled correctly all along.
 *
 * Note it can no longer return false merely because nothing changed — see the
 * unchanged-write short-circuit in sn_dash_set_pin(). Re-pinning a pinned zone
 * is a 200, not a 400.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sn_dash_pin_route_handler( $request ) {
	$zone   = (string) $request->get_param( 'zone' );
	$pinned = (bool) $request->get_param( 'pinned' );

	if ( ! in_array( $zone, SN_DASH_ZONE_IDS, true ) ) {
		return new WP_REST_Response( array( 'error' => 'unknown zone' ), 400 );
	}

	$user_id = get_current_user_id();
	if ( ! sn_dash_set_pin( $user_id, $zone, $pinned ) ) {
		return new WP_REST_Response( array( 'error' => 'could not save the preference' ), 500 );
	}

	return new WP_REST_Response( array( 'pins' => sn_dash_pins( $user_id ) ), 200 );
}

/**
 * Register the route.
 *
 * Gated on `manage_options` — VERIFIED to be the same capability that gates the
 * admin page itself (inc/admin-menu.php). Uses the house permission helper
 * rather than an inline closure so the gate is named, greppable, and shared.
 *
 * @return void
 */
function sn_dash_pin_register_route() {
	register_rest_route(
		'signal-noise/v1',
		'/dash-pin',
		array(
			'methods'             => 'POST',
			'callback'            => 'sn_dash_pin_route_handler',
			'permission_callback' => 'snt_ability_perm_manage_options',
			'args'                => array(
				'zone'   => array( 'required' => true ),
				'pinned' => array( 'required' => true ),
			),
		)
	);
}

if ( ! defined( 'SN_DASH_PINS_TEST' ) || ! SN_DASH_PINS_TEST ) {
	add_action( 'rest_api_init', 'sn_dash_pin_register_route' );
}
