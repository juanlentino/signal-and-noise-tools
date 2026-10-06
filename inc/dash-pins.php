<?php
/**
 * Signal & Noise — Dashboard zone pins.
 *
 * A pin is a PERSONAL view preference, so it lives in user meta rather than a
 * site option. It could force a zone open, never closed (that check,
 * sn_dash_zone_is_open(), was removed with the unused zone renderer).
 *
 * v11.29.0 — READER ONLY. The setter and its REST route were removed: nothing in
 * the admin ever called them, so no pin could be set, so this always returned an
 * empty array and the whole feature had no effect while a live endpoint sat on
 * the REST surface. Its one caller (inc/admin-tab-dashboard.php) now assigns a
 * value nothing reads, since the zone renderer was removed.
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
