<?php
/**
 * Signal & Noise Tools — owner/role analytics exclusion (v6.23.0).
 *
 * Mirrors Plausible's "exclude user roles" setting. When a logged-in user holds
 * a role listed in sn_settings['analytics']['exclude_roles'], the companion
 * theme's front-end beacon is suppressed for that request via the theme's
 * `sn_beacon_enabled` filter — no pixel is printed, so nothing reaches the edge
 * collector. Cookieless (reads WP's existing auth session; sets nothing new) and
 * forward-only (visits already recorded are unaffected).
 *
 * CACHING CAVEAT — this only fires on requests WordPress renders per-user. On a
 * full-page / CDN-cached site the gate is bypassed on cache hits, so the owner's
 * pageviews still leak in UNLESS logged-in requests bypass the edge cache (e.g. a
 * Cloudflare "Bypass cache when the request carries a wordpress_logged_in_
 * cookie" rule). The settings card states this requirement; see the research
 * handoff for the live cache-header evidence on juanlentino.com.
 *
 * @package SignalNoiseTools
 * @since 6.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure predicate: does any of the user's roles fall in the exclusion set?
 *
 * @param string[] $user_roles    Roles held by the current user.
 * @param string[] $exclude_roles Role slugs configured for exclusion.
 * @return bool
 */
function sn_beacon_owner_excluded( $user_roles, $exclude_roles ) {
	$exclude_roles = (array) $exclude_roles;
	if ( empty( $exclude_roles ) ) {
		return false;
	}
	return (bool) array_intersect( (array) $user_roles, $exclude_roles );
}

/**
 * `sn_beacon_enabled` filter: suppress the front-end beacon for excluded roles.
 *
 * Runs at wp_enqueue_scripts time (the theme calls it inside sn_beacon_enqueue,
 * priority 30) when the current user is resolved. Leaves an already-disabled
 * beacon disabled — it only ever suppresses, never re-enables.
 *
 * @param bool $enabled Whether the beacon is currently enabled.
 * @return bool
 */
function sn_beacon_owner_exclusion_filter( $enabled ) {
	if ( ! $enabled || ! is_user_logged_in() ) {
		return $enabled;
	}
	$exclude = (array) sn_setting( 'analytics.exclude_roles', array() );
	if ( empty( $exclude ) ) {
		return $enabled;
	}
	$user  = wp_get_current_user();
	$roles = ( $user && isset( $user->roles ) ) ? (array) $user->roles : array();
	return sn_beacon_owner_excluded( $roles, $exclude ) ? false : $enabled;
}
add_filter( 'sn_beacon_enabled', 'sn_beacon_owner_exclusion_filter' );

/**
 * Whether the CURRENT viewer would be excluded — drives the settings-card status.
 *
 * @return bool
 */
function sn_beacon_owner_current_user_excluded() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$exclude = (array) sn_setting( 'analytics.exclude_roles', array() );
	if ( empty( $exclude ) ) {
		return false;
	}
	$user  = wp_get_current_user();
	$roles = ( $user && isset( $user->roles ) ) ? (array) $user->roles : array();
	return sn_beacon_owner_excluded( $roles, $exclude );
}

/**
 * Editable roles for the checkbox UI: slug => display name.
 *
 * @return array<string,string>
 */
function sn_beacon_excludable_roles() {
	if ( ! function_exists( 'wp_roles' ) ) {
		return array();
	}
	$roles = wp_roles()->roles;
	if ( ! is_array( $roles ) ) {
		return array();
	}
	$out = array();
	foreach ( $roles as $slug => $data ) {
		$out[ (string) $slug ] = isset( $data['name'] ) ? (string) $data['name'] : (string) $slug;
	}
	return $out;
}

/**
 * Sanitize a submitted set of role slugs against the real role list.
 *
 * Unknown slugs are dropped (allowlist), duplicates collapsed, order preserved.
 *
 * @param mixed $submitted Raw $_POST role slugs (array|other).
 * @return string[] Valid, de-duplicated role slugs.
 */
function sn_beacon_sanitize_exclude_roles( $submitted ) {
	$submitted = is_array( $submitted ) ? $submitted : array();
	$valid     = array_keys( sn_beacon_excludable_roles() );
	$clean     = array();
	foreach ( $submitted as $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( in_array( $slug, $valid, true ) && ! in_array( $slug, $clean, true ) ) {
			$clean[] = $slug;
		}
	}
	return $clean;
}
