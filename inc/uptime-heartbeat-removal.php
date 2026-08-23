<?php
/**
 * Signal & Noise Tools — one-shot janitor for the removed push heartbeat.
 *
 * WHY A JANITOR AND NOT JUST A FILE DELETE: `sn_uptime_kuma_heartbeat` is
 * SCHEDULED in the live cron table. Deleting inc/uptime-heartbeat.php removes
 * the handler and nothing else — the event keeps firing every five minutes
 * forever, against no listener, and shows up in Connections → Cron as an
 * orphan. The removed module's own docblock warned about exactly this shape
 * ("renaming the hook orphans the already-scheduled cron event on live
 * installs"); removing the module is the same hazard with the same fix.
 *
 * It also deletes the two settings keys the feature owned. Doing that here,
 * rather than leaving them to rot, is what keeps this a MINOR: the project's
 * definition of breaking is a settings-schema change *without* a migration.
 *
 * Runs once per SNT_VERSION on admin_init, the same shape as the legacy-deploy
 * janitor in inc/plugin-footprint.php. It is idempotent and cheap: after the
 * first pass wp_next_scheduled() returns false and the option is absent, so
 * every later call does nothing.
 *
 * SAFE TO DELETE once every install that ever ran the heartbeat has upgraded
 * past v12.19.0. On a single-install plugin that is one upgrade.
 *
 * @package SignalNoiseTools
 * @since 12.19.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_UPTIME_HEARTBEAT_REMOVED_HOOK = 'sn_uptime_kuma_heartbeat';
const SN_UPTIME_HEARTBEAT_JANITOR_OPT  = 'sn_uptime_heartbeat_removed_at';

/**
 * Clear every scheduled instance of the retired hook.
 *
 * wp_clear_scheduled_hook() removes ALL instances regardless of args, which is
 * what we want: the reconciler could have left more than one behind, and a
 * single wp_unschedule_event() would clear only the one it was handed.
 *
 * @return int Number of events cleared (0 when there was nothing to do).
 */
function sn_uptime_heartbeat_unschedule_removed() {
	if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
		return 0;
	}
	$cleared = wp_clear_scheduled_hook( SN_UPTIME_HEARTBEAT_REMOVED_HOOK );
	return is_int( $cleared ) ? $cleared : 0;
}

/**
 * PURE-ish: drop the two retired keys from the stored settings option.
 *
 * Reads and writes SN_SETTINGS_OPTION directly. Returns false when there was
 * nothing to remove, so the caller — and the tests — can tell "cleaned" from
 * "already clean" rather than inferring it.
 *
 * @return bool True when the option was actually rewritten.
 */
function sn_uptime_heartbeat_prune_settings() {
	if ( ! function_exists( 'get_option' ) || ! defined( 'SN_SETTINGS_OPTION' ) ) {
		return false;
	}
	$stored = get_option( SN_SETTINGS_OPTION, array() );
	if ( ! is_array( $stored ) || ! isset( $stored['monitoring'] ) || ! is_array( $stored['monitoring'] ) ) {
		return false;
	}
	$before = $stored['monitoring'];
	unset( $stored['monitoring']['uptime_kuma_enabled'], $stored['monitoring']['uptime_kuma_push_url'] );
	if ( $before === $stored['monitoring'] ) {
		return false;
	}
	update_option( SN_SETTINGS_OPTION, $stored );
	if ( function_exists( 'sn_setting_reset_cache' ) ) {
		// sn_setting() static-caches the merged array for the request; without
		// this the rest of THIS request would still see the deleted keys.
		sn_setting_reset_cache();
	}
	return true;
}

/**
 * The one-shot pass: unschedule, then drop the two dead settings keys.
 *
 * @return void
 */
function sn_uptime_heartbeat_janitor() {
	$stamp = function_exists( 'get_option' ) ? (string) get_option( SN_UPTIME_HEARTBEAT_JANITOR_OPT, '' ) : '';
	$version = defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '';
	if ( '' !== $stamp && $stamp === $version ) {
		return;
	}

	sn_uptime_heartbeat_unschedule_removed();

	// The keys live inside the 'monitoring' group, which SURVIVES — spend-watch
	// and uptime-status both write credentials under it. Only the two the
	// heartbeat owned are removed, and only if still present.
	//
	// Operates on the STORED option directly, not through sn_settings_save():
	// that function sanitizes a raw $_POST submission from the Identity form and
	// would be the wrong shape entirely here. It also unslashes, which on an
	// already-stored array would strip a backslash layer that belongs to the data.
	sn_uptime_heartbeat_prune_settings();

	if ( function_exists( 'update_option' ) ) {
		update_option( SN_UPTIME_HEARTBEAT_JANITOR_OPT, $version, false );
	}
}

if ( ! defined( 'SN_UPTIME_HEARTBEAT_REMOVAL_TEST' ) || ! SN_UPTIME_HEARTBEAT_REMOVAL_TEST ) {
	add_action( 'admin_init', 'sn_uptime_heartbeat_janitor' );
}
