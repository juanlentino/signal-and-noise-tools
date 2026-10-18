<?php
/**
 * Signal & Noise Tools: three security watches (2026-10-18). They observe and
 * never enforce; enforcement belongs to the login guard, Two Factor and Cloudflare.
 *
 * - app_passwords: core's application passwords are the one standing
 *   credential that skips the masked login door, Two Factor and passkeys.
 *   Ripe on one created this week, or one unused for 90 days.
 * - admin_2fa: reads Two Factor (passkeys reach it through its WebAuthn
 *   provider plugin). Ripe when Two Factor is inactive, or an administrator has
 *   no method stronger than email or backup codes.
 * - guard_routes: once a day, reads the Cloudflare routes. Ripe when the door's
 *   routes no longer point at the login guard, when a retired door's route is
 *   left behind, when Cloudflare cannot be read, or when the check goes quiet.
 *   Stores counts only, never route patterns.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SEC_GUARD_ROUTES_HOOK = 'sn_security_guard_routes_check';
const SN_SEC_GUARD_ROUTES_OPT  = 'sn_security_guard_routes_last';
const SN_SEC_STALE_DAYS        = 90;

/**
 * Verdict over application passwords.
 *
 * @param array $rows Each: user, name, created, last_used (null if never).
 * @param int   $now  Unix time.
 * @return array{ripe:bool,note:string}
 */
function snt_sec_app_passwords_verdict( array $rows, $now ) {
	$notes = array();
	foreach ( $rows as $r ) {
		$label = $r['user'] . ' / ' . $r['name'];
		$seen  = null === $r['last_used'] ? (int) $r['created'] : (int) $r['last_used'];
		if ( (int) $now - (int) $r['created'] < 7 * DAY_IN_SECONDS ) {
			$notes[] = $label . ' created ' . gmdate( 'Y-m-d', (int) $r['created'] );
		} elseif ( (int) $now - $seen > SN_SEC_STALE_DAYS * DAY_IN_SECONDS ) {
			$notes[] = $label . ( null === $r['last_used'] ? ' never used' : ' unused since ' . gmdate( 'Y-m-d', $seen ) ) . ': revoke it if nothing needs it';
		}
	}
	return array( 'ripe' => (bool) $notes, 'note' => implode( '; ', $notes ) );
}

/**
 * Verdict over administrators' Two Factor methods.
 *
 * @param array|null $admins login => enabled provider keys; null when Two Factor is inactive.
 * @return array{ripe:bool,note:string}
 */
function snt_sec_admin_2fa_verdict( $admins ) {
	if ( null === $admins ) {
		return array( 'ripe' => true, 'note' => 'Two Factor is not active: administrators sign in with a password alone' );
	}
	$weak  = array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes', 'Two_Factor_Dummy' );
	$short = array();
	foreach ( $admins as $login => $providers ) {
		if ( ! array_diff( (array) $providers, $weak ) ) {
			$short[] = $login;
		}
	}
	return array( 'ripe' => (bool) $short, 'note' => $short ? 'no passkey or authenticator app for: ' . implode( ', ', $short ) : '' );
}

/**
 * Compare the zone's routes with the door's expected patterns. Counts only.
 *
 * @param array    $routes   Routes from the Cloudflare API.
 * @param string[] $patterns Expected door patterns.
 * @param int      $now      Unix time.
 * @param string   $error    Read error, '' on success.
 * @return array{at:int,covered:int,missing:int,stale:int,error:string}
 */
function snt_sec_guard_routes_check( array $routes, array $patterns, $now, $error = '' ) {
	$script = defined( 'SN_LG_SCRIPT' ) ? SN_LG_SCRIPT : 'sn-login-guard';
	$guard  = array();
	foreach ( $routes as $r ) {
		if ( $script === ( $r['script'] ?? '' ) && false === strpos( (string) ( $r['pattern'] ?? '' ), '/_sn/' ) ) {
			$guard[] = (string) $r['pattern'];
		}
	}
	$covered = count( array_intersect( $patterns, $guard ) );
	return array(
		'at'      => (int) $now,
		'covered' => $covered,
		'missing' => count( $patterns ) - $covered,
		'stale'   => count( array_diff( $guard, $patterns ) ),
		'error'   => (string) $error,
	);
}

/** Daily: read the routes and store the counts. */
function snt_sec_guard_routes_run() {
	if ( ! function_exists( 'sn_lg_list_routes' ) || ! function_exists( 'sn_login_get_slug' ) ) {
		return;
	}
	$list = sn_lg_list_routes();
	update_option( SN_SEC_GUARD_ROUTES_OPT, snt_sec_guard_routes_check( $list['routes'], sn_lg_door_patterns( sn_login_get_slug() ), time(), $list['ok'] ? '' : (string) $list['error'] ), false );
}

/** Watch: guard routes. */
function snt_watch_ripe_guard_routes( $watch, $now ) {
	unset( $watch );
	$last = get_option( SN_SEC_GUARD_ROUTES_OPT, array() );
	if ( ! is_array( $last ) || empty( $last['at'] ) ) {
		return array( 'ripe' => false, 'note' => 'not run yet' );
	}
	if ( '' !== (string) $last['error'] ) {
		return array( 'ripe' => true, 'note' => 'Cloudflare routes could not be read: ' . $last['error'] );
	}
	if ( (int) $last['missing'] > 0 || (int) $last['stale'] > 0 ) {
		return array( 'ripe' => true, 'note' => sprintf( '%d of the login address\'s guard routes missing, %d left from a retired address. Save the Login URL again in S&N Home: one save restores and prunes them.', (int) $last['missing'], (int) $last['stale'] ) );
	}
	if ( (int) $now - (int) $last['at'] > 2 * DAY_IN_SECONDS ) {
		return array( 'ripe' => true, 'note' => 'the daily route check has not run since ' . gmdate( 'Y-m-d H:i', (int) $last['at'] ) . ' UTC' );
	}
	return array( 'ripe' => false, 'note' => 'both guard routes in place' );
}

/**
 * How a note names an account: display name and user ID, never the login
 * username, which may be the secret half of a credential.
 *
 * @param object $u User with ID and display_name.
 * @return string
 */
function snt_sec_account_label( $u ) {
	return (string) $u->display_name . ' (user #' . (int) $u->ID . ')';
}

/** Watch: application passwords, read live from core. */
function snt_watch_ripe_app_passwords( $watch, $now ) {
	unset( $watch );
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		return array( 'ripe' => false, 'note' => '' );
	}
	$rows = array();
	foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) {
		foreach ( (array) WP_Application_Passwords::get_user_application_passwords( (int) $u->ID ) as $p ) {
			$rows[] = array( 'user' => snt_sec_account_label( $u ), 'name' => (string) $p['name'], 'created' => (int) $p['created'], 'last_used' => empty( $p['last_used'] ) ? null : (int) $p['last_used'] );
		}
	}
	return snt_sec_app_passwords_verdict( $rows, $now );
}

/** Watch: administrators' Two Factor methods, read live from Two Factor. */
function snt_watch_ripe_admin_2fa( $watch, $now ) {
	unset( $watch, $now );
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return snt_sec_admin_2fa_verdict( null );
	}
	$admins = array();
	foreach ( get_users( array( 'role' => 'administrator' ) ) as $u ) {
		$admins[ snt_sec_account_label( $u ) ] = array_keys( (array) Two_Factor_Core::get_enabled_providers_for_user( $u ) );
	}
	return snt_sec_admin_2fa_verdict( $admins );
}

if ( ! defined( 'SN_SECURITY_WATCHES_TEST' ) || ! SN_SECURITY_WATCHES_TEST ) {
	add_action( SN_SEC_GUARD_ROUTES_HOOK, 'snt_sec_guard_routes_run' );
	add_action(
		'init',
		static function () {
			if ( ! wp_next_scheduled( SN_SEC_GUARD_ROUTES_HOOK ) ) {
				wp_schedule_event( time() + 20 * MINUTE_IN_SECONDS, 'daily', SN_SEC_GUARD_ROUTES_HOOK );
			}
		}
	);
}
