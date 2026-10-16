<?php
/**
 * Signal & Noise Tools: rolling live snapshots.
 *
 * The live panel answers for the last 5 minutes and is gone a refresh
 * later. This keeps each COMPLETED 5-minute slot of the last 24 hours, in a
 * non-autoloaded option, so the owner can look back: readers by class, the
 * top five pages and the top five sources, as the panel would have shown
 * them. The realtime refresh (inc/analytics-realtime.php) calls the step
 * after it reads the hour; the step reads Analytics Engine only when the
 * hour holds a completed slot the store does not have yet, so a refresh
 * inside the same 5 minutes costs nothing.
 *
 * What a slot never contains: a visitor id, an IP, a user agent, a cookie or
 * any request text. No withholding floor, as live has none (owner,
 * 2026-10-16). Read by `signal-noise/live-now` with `hours_back`, and by the
 * past-slot picker under the admin Right now block (classic and native).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-live-slots-parts.php';

/**
 * The refresh's step: store the hour's completed slots when one is new.
 * Any failed read stores nothing; the next refresh tries again.
 *
 * The current slot is the hour's own last slot, never the clock read now: a
 * 5-minute boundary passing between the two reads must not store the slot
 * that was still partial when the hour was read.
 *
 * @param array|null $hour Twelve slots, or null when the hour was not read.
 * @return bool Whether the store was written.
 */
function sn_analytics_live_slots_step( $hour ) {
	if ( ! is_array( $hour ) || array() === $hour || ! function_exists( 'sn_analytics_query' ) ) {
		return false;
	}
	$last  = end( $hour );
	$cur   = is_array( $last ) && isset( $last['t'] ) ? (int) $last['t'] : 0;
	$now   = $cur;
	$store = get_option( SN_ANALYTICS_LIVE_SLOTS_OPT, array() );
	$store = is_array( $store ) ? $store : array();
	$new   = false;
	foreach ( $hour as $s ) {
		if ( is_array( $s ) && isset( $s['t'] ) && (int) $s['t'] < $cur && ! isset( $store[ (int) $s['t'] ] ) ) {
			$new = true;
			break;
		}
	}
	if ( ! $new ) {
		return false;
	}
	$classes = sn_analytics_query( sn_analytics_live_slot_classes_sql() );
	$pages   = is_array( $classes ) ? sn_analytics_query( sn_analytics_live_slot_pages_sql() ) : null;
	$sources = is_array( $pages ) ? sn_analytics_query( sn_analytics_live_slot_sources_sql() ) : null;
	if ( ! is_array( $sources ) ) {
		error_log( '[sn-live-slots] a per-slot read failed; nothing stored, the next refresh tries again' );
		return false;
	}
	$fresh = sn_analytics_live_slots_from_rows( $hour, $classes, $pages, $sources, $now, 'sn_analytics_live_pages_resolve' );
	return update_option( SN_ANALYTICS_LIVE_SLOTS_OPT, sn_analytics_live_slots_merge( $store, $fresh, $now ), false );
}

/**
 * The stored slots of the last `$hours`, oldest first.
 *
 * @param int $hours 1 to 24.
 * @return array<int,array<string,mixed>>
 */
function sn_analytics_live_slots( $hours ) {
	$store = get_option( SN_ANALYTICS_LIVE_SLOTS_OPT, array() );
	return sn_analytics_live_slots_window( is_array( $store ) ? $store : array(), $hours, time() );
}
