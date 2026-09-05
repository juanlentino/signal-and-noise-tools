<?php
/**
 * Take WP-Cron out of the request path.
 *
 * ── The measurement ───────────────────────────────────────────────────────
 *
 * Cloudways PHP analytics for this app, 24h (issue #1002):
 *
 *   /wp-cron.php?doing_wp_cron   62 runs   avg 10.6s   max 51.7s
 *   /index.php                   99 runs   avg  8.25s
 *
 * With `DISABLE_WP_CRON` unset, WordPress spawns cron from inside an ordinary
 * page request. On a 2 GB / 2 vCPU box sitting at ~90% memory, a PHP worker
 * held for ten seconds - fifty at the tail - is a worker not serving anything
 * else, and Varnish answers the requests it cannot place with 503. Ten of those
 * were recorded in the same 24 hours.
 *
 * The visitor whose pageview happens to spawn cron pays for all of it.
 *
 * ── Why this is safe HERE, and the one thing that makes it unsafe ─────────
 *
 * Setting this constant with nothing else driving cron would stop every
 * scheduled job on the site, silently. It is safe here because an external
 * driver is already installed and was verified before this shipped:
 *
 *   every 5 minutes:  wget https://juanlentino.com/wp-cron.php?doing_wp_cron  #CloudwaysApps
 *   (the literal crontab spec is not reproduced here - its slash-star would
 *    close this comment, which is how the first draft of this file failed to parse)
 *
 * That is Cloudways' Cron Optimizer. Note what it does NOT do: it installs the
 * system cron but leaves `DISABLE_WP_CRON` unset, so before this file the site
 * had BOTH - a five-minute external tick AND in-request spawning. Only the
 * second one hurts, and only the second one is removed here.
 *
 * The failure mode - external cron disappears, nothing runs - is exactly what
 * `snt_cron_health_model()` already detects: a scheduled hook whose time has
 * passed is `overdue`, and overdue elevates cron health to critical. That check
 * is the safety net this change leans on, and it predates it.
 *
 * ── Turning it back off ───────────────────────────────────────────────────
 *
 * `add_filter( 'snt_offload_wp_cron', '__return_false' );` from a mu-plugin, or
 * define `DISABLE_WP_CRON` yourself in wp-config.php - a constant already
 * defined is never overridden here.
 *
 * @package Signal_And_Noise_Tools
 * @since 13.97.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether to keep WP-Cron out of the request path.
 *
 * Runs at PLUGIN LOAD, before `init` - which is the only window that matters,
 * because `spawn_cron()` reads the constant during `init`. A filter added from
 * a theme's `functions.php` would be too late; a mu-plugin is the place.
 *
 * @return bool
 */
function snt_should_offload_wp_cron() {
	// Someone else already decided. Never override an explicit wp-config value,
	// in either direction.
	if ( defined( 'DISABLE_WP_CRON' ) ) {
		return false;
	}

	// WP-CLI and a real cron request drive cron themselves; the constant is
	// irrelevant there, and defining it would only muddy `wp cron event run`.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return false;
	}

	/**
	 * Filters whether the plugin removes WP-Cron from the request path.
	 *
	 * Returning false leaves WordPress's default behaviour intact: cron spawns
	 * from a visitor's pageview. Only do that if no external cron is driving
	 * wp-cron.php, or the site's scheduled work stops.
	 *
	 * @since 13.97.2
	 * @param bool $offload Default true.
	 */
	return (bool) apply_filters( 'snt_offload_wp_cron', true );
}

if ( snt_should_offload_wp_cron() ) {
	define( 'DISABLE_WP_CRON', true );
}
