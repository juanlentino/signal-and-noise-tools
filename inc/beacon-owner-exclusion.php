<?php
/**
 * Signal & Noise Tools — owner/role analytics exclusion (v6.23.0).
 *
 * Mirrors Plausible's "exclude user roles" setting. When a logged-in user holds
 * a role listed in sn_settings['analytics']['exclude_roles'], the companion
 * theme's front-end beacon is suppressed for that request via the theme's
 * `sn_beacon_enabled` filter — no pixel is printed, so nothing reaches the edge
 * collector. Excluded users also get a first-party sn_owner=1 cookie (see the
 * owner-device section below) so logged-out cached views stay excluded. Forward-only (visits already recorded are unaffected).
 *
 * CACHED PAGES: this server-side filter only fires on requests WordPress
 * renders. A page served from the full-page or CDN cache never reaches it; the
 * browser-side gate covers that case: sn-beacon.js sends nothing when the device
 * carries the sn_owner=1 cookie (see the owner-device section below), so an
 * excluded user's cached views stay excluded without any cache-bypass rule.
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
 * Cache hits never reach this filter; the browser-side sn_owner cookie gate
 * in sn-beacon.js covers cached pages.
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

/*
 * Owner-device cookie. Logged-out visits are served from the edge cache, so the
 * server cannot suppress the beacon for them. Instead an excluded user's browser
 * carries sn_owner=1 (set at login, NOT cleared at logout) and the theme's
 * sn-beacon.js sends nothing when it sees it. sn_owner=0 means "count this
 * device again": it is persistent so the init backfill below does not re-mark it.
 */
const SN_OWNER_COOKIE     = 'sn_owner';
const SN_OWNER_COOKIE_TTL = 34560000; // 400 days, the browser cap on cookie lifetime.

/**
 * Whether a given user is excluded by the configured role rule.
 *
 * @param WP_User|object|null $user User.
 * @return bool
 */
function sn_beacon_owner_user_excluded( $user ) {
	$roles = ( $user && isset( $user->roles ) ) ? (array) $user->roles : array();
	return sn_beacon_owner_excluded( $roles, (array) sn_setting( 'analytics.exclude_roles', array() ) );
}

/**
 * setcookie() options for the owner-device flag. Readable by JS on purpose.
 *
 * @param int $expires Unix expiry.
 * @return array
 */
function sn_owner_cookie_options( $expires ) {
	return array(
		'expires'  => (int) $expires,
		'path'     => '/',
		'secure'   => true,
		'httponly' => false,
		'samesite' => 'Lax',
	);
}

/**
 * Write the flag ('1' excluded, '0' counted again). Returns the options it
 * used, or null when headers are already out.
 *
 * @param string $value '1' or '0'.
 * @return array|null
 */
function sn_owner_cookie_write( $value ) {
	if ( headers_sent() ) {
		return null;
	}
	$opts = sn_owner_cookie_options( time() + SN_OWNER_COOKIE_TTL );
	setcookie( SN_OWNER_COOKIE, $value, $opts );
	$_COOKIE[ SN_OWNER_COOKIE ] = $value;
	return $opts;
}

/**
 * wp_login: mark this browser when the user is excluded.
 *
 * @param string  $login Username (unused).
 * @param WP_User $user  User.
 * @return array|null Options written, or null.
 */
function sn_owner_cookie_on_login( $login, $user ) {
	return sn_beacon_owner_user_excluded( $user ) ? sn_owner_cookie_write( '1' ) : null;
}
add_action( 'wp_login', 'sn_owner_cookie_on_login', 10, 2 );

/**
 * init: backfill an existing excluded session that has no flag yet, and handle
 * the nonce'd "?sn_owner_device=forget|mark" link from the settings card.
 *
 * @return string|null What was done: 'backfill', 'forget', 'mark', or null.
 */
function sn_owner_cookie_on_init() {
	if ( ! sn_beacon_owner_current_user_excluded() ) {
		return null;
	}
	$req = isset( $_GET['sn_owner_device'] ) ? sanitize_key( wp_unslash( $_GET['sn_owner_device'] ) ) : '';
	if ( in_array( $req, array( 'forget', 'mark' ), true ) ) {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sn_owner_device' ) ) {
			return null;
		}
		sn_owner_cookie_write( 'forget' === $req ? '0' : '1' );
		return $req;
	}
	if ( ! isset( $_COOKIE[ SN_OWNER_COOKIE ] ) ) {
		sn_owner_cookie_write( '1' );
		return 'backfill';
	}
	return null;
}

/** Hook wrapper: after a link action, drop the query args. */
function sn_owner_cookie_init_hook() {
	$done = sn_owner_cookie_on_init();
	if ( in_array( $done, array( 'forget', 'mark' ), true ) ) {
		wp_safe_redirect( remove_query_arg( array( 'sn_owner_device', '_wpnonce' ) ) );
		exit;
	}
}
add_action( 'init', 'sn_owner_cookie_init_hook' );

/**
 * Whether THIS browser carries the owner-device flag.
 *
 * @return bool
 */
function sn_owner_device_flagged() {
	return isset( $_COOKIE[ SN_OWNER_COOKIE ] ) && '1' === $_COOKIE[ SN_OWNER_COOKIE ];
}
