<?php
/**
 * Signal & Noise — the `core` and `runtime` rows of get-deploy-status.
 *
 * core: the WordPress version, the newest version the CACHED update_core
 * transient offers, a state, WordPress's own auto-update mode, and a reason.
 * runtime: PHP_VERSION and register_argc_argv, local door only.
 *
 * auto_updates describes WordPress's updater only. A host that updates core
 * outside it (Cloudways can) is invisible here.
 *
 * @package SignalNoiseTools
 * @since 19.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress's own updater, reduced to one word. Pure so it can be pinned: the
 * inputs are constants that cannot vary inside one PHP process.
 * A major-only configuration still applies releases, so it reads 'all'.
 */
function snt_core_auto_updates_mode( $file_mods_allowed, $updater_disabled, $allow_major, $allow_minor ) {
	if ( ! $file_mods_allowed || $updater_disabled ) {
		return 'off';
	}
	if ( $allow_major ) {
		return 'all';
	}
	return $allow_minor ? 'minor' : 'off';
}

/** Resolve the inputs the way WP_Automatic_Updater does, filters included. */
function snt_core_auto_updates() {
	$file_mods = ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	if ( function_exists( 'wp_is_file_mod_allowed' ) ) {
		$file_mods = wp_is_file_mod_allowed( 'automatic_updater' );
	}
	$disabled = (bool) apply_filters( 'automatic_updater_disabled', defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED );
	$major    = false;
	$minor    = true;
	if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		$mode  = WP_AUTO_UPDATE_CORE;
		$minor = false !== $mode;
		$major = true === $mode || in_array( $mode, array( 'beta', 'rc', 'development', 'branch-development' ), true );
	} else {
		$major = 'enabled' === get_site_option( 'auto_update_core_major' );
	}
	$major = (bool) apply_filters( 'allow_major_auto_core_updates', $major );
	$minor = (bool) apply_filters( 'allow_minor_auto_core_updates', $minor );
	return snt_core_auto_updates_mode( $file_mods, $disabled, $major, $minor );
}

/** Reason text for a waiting point release. */
const SNT_CORE_POINT_REASON = 'A point release is waiting (WordPress ships security fixes as point releases but does not flag them).';

/**
 * The `core` row. Reads the CACHED update_core site transient only; never calls
 * wp_version_check or anything that reaches the network.
 *
 * offer: WordPress offers carry no security flag. Response 'autoupdate' marks a
 * same-branch point release (the only way core ships a security fix), so
 * offer 'point' means one is waiting, security or maintenance alike; 'major'
 * means only an 'upgrade' offer; '' when ok or unknown. Point wins.
 */
function snt_core_status() {
	$current = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
	$row     = array( 'current' => $current, 'latest' => $current, 'state' => 'unknown', 'offer' => '', 'auto_updates' => snt_core_auto_updates(), 'reason' => '' );
	$cached  = get_site_transient( 'update_core' );
	if ( '' === $current || ! is_object( $cached ) || ! isset( $cached->updates ) || ! is_array( $cached->updates ) ) {
		// Object-cache flushes empty it: the theme's purge after an update (refilled
		// by snt_core_refill_after_flush) and the nightly Breeze purge reaching the
		// Cloudways app purge, documented to clear Redis too (refilled by
		// snt_core_refill_guard). The reason states only the observation.
		$row['reason'] = 'WordPress\'s core update check is not in the cache right now, so there is nothing to compare against. It reappears when WordPress next checks. Read only; nothing is fetched here.';
		return $row;
	}
	$newest = '';
	$point  = '';
	foreach ( $cached->updates as $offer ) {
		$response = is_object( $offer ) && isset( $offer->response ) ? (string) $offer->response : '';
		$version  = is_object( $offer ) && isset( $offer->version ) ? (string) $offer->version : '';
		if ( ! in_array( $response, array( 'upgrade', 'autoupdate' ), true ) || '' === $version || ! version_compare( $version, $current, '>' ) ) {
			continue;
		}
		if ( 'autoupdate' === $response && ( '' === $point || version_compare( $version, $point, '>' ) ) ) {
			$point = $version;
		}
		if ( '' === $newest || version_compare( $version, $newest, '>' ) ) {
			$newest = $version;
		}
	}
	if ( '' === $newest ) {
		$row['state'] = 'ok';
		return $row;
	}
	$row['latest'] = $newest;
	$row['state']  = 'behind';
	$row['offer']  = '' !== $point ? 'point' : 'major';
	$row['reason'] = '' !== $point ? SNT_CORE_POINT_REASON : "WordPress $newest is available.";
	return $row;
}

/** The `runtime` row. Local only: the remote twin drops it (see abilities-remote-set.php). */
function snt_runtime_status() {
	return array(
		'php'                => PHP_VERSION,
		'register_argc_argv' => (bool) filter_var( ini_get( 'register_argc_argv' ), FILTER_VALIDATE_BOOLEAN ),
	);
}

/**
 * Refill WordPress's core update check after the theme's full cache purge.
 *
 * On this site update_core lives in the persistent object cache, and a full
 * sn_purge_all_caches() (the manual purge-everything; every update did until
 * theme 14.10.0) calls wp_cache_flush(), which empties it. The Core row then read "update check not cached" until the next
 * twice-daily check. Other emptiers are caught by snt_core_refill_guard(). A one-off cron event a minute later re-runs the check, so
 * the purge request itself never waits on wordpress.org. snt_core_status()
 * stays read-only; only this hook fetches.
 *
 * @param array $args The purge's parsed args (sn_after_full_cache_flush).
 * @return void
 */
function snt_core_refill_after_flush( $args = array() ) {
	if ( empty( $args['object_cache'] ) ) {
		return; // The object cache was not flushed; update_core is intact.
	}
	if ( ! wp_next_scheduled( 'snt_core_version_refill' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'snt_core_version_refill' );
	}
}
add_action( 'sn_after_full_cache_flush', 'snt_core_refill_after_flush', 30, 1 );

/**
 * The one-off refill: WordPress's own version check.
 *
 * @return void
 */
function snt_core_version_refill() {
	if ( ! function_exists( 'wp_version_check' ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
		require_once ABSPATH . WPINC . '/update.php';
	}
	if ( function_exists( 'wp_version_check' ) ) {
		wp_version_check();
	}
}
add_action( 'snt_core_version_refill', 'snt_core_version_refill' );

/**
 * The guard behind the refill: whatever emptied update_core, notice it.
 *
 * The purge hook above catches the theme's flush, but not every emptier says
 * so: Breeze's nightly purge (00:00 UTC) fires breeze_clear_varnish, which
 * reaches the Cloudways app purge (inc/cloudways-purge.php), and that clears
 * Redis too, per the Cloudways purge tool's documentation. Core then read "not
 * cached" from about 00:00 to the 10:50 UTC check (2026-10-02). Every emptier ends in the same state, so the 5-minute
 * warm pass checks the state: update_core missing and no refill queued means
 * queue one, at most once an hour (24 wordpress.org calls a day at worst).
 * The stamp is an option, not a transient, so a Redis flush cannot reset it.
 * Hooked ahead of the worker probe (priority 5) so a slow probe cannot starve it.
 *
 * ponytail: a second flush inside the hour (Breeze fired twice on 2026-10-02,
 * 00:00:35 and 00:05:15) leaves Core blank until the hour passes; shorten the
 * throttle if that shows up.
 *
 * @return void
 */
function snt_core_refill_guard() {
	if ( false !== get_site_transient( 'update_core' ) || wp_next_scheduled( 'snt_core_version_refill' ) ) {
		return;
	}
	$now = time();
	if ( $now - (int) get_option( 'snt_core_refill_guard_at', 0 ) < HOUR_IN_SECONDS ) {
		return;
	}
	update_option( 'snt_core_refill_guard_at', $now, false );
	wp_schedule_single_event( $now, 'snt_core_version_refill' );
}
add_action( 'snt_deploy_workers_warm', 'snt_core_refill_guard', 5 );
