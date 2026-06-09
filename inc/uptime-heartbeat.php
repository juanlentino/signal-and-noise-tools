<?php
/**
 * Signal & Noise Tools — Uptime Kuma push-monitor heartbeat (v4.9.0, T4).
 *
 * Opt-in. When enabled with a push URL, a namespaced 5-minute cron event
 * GETs the configured Uptime Kuma "push" monitor endpoint, appending
 * `status=up`. Uptime Kuma flips the monitor to DOWN when it stops
 * receiving the heartbeat — giving external "is the site alive + is
 * WP-Cron firing" monitoring with no inbound surface.
 *
 * Settings (inc/settings.php, default OFF, migration-free deep-merge):
 *   monitoring.uptime_kuma_enabled   bool
 *   monitoring.uptime_kuma_push_url  string
 *
 * Security posture (mirrors the webhook SSRF hardening):
 *   - wp_http_validate_url() before the request
 *   - redirection => 0 (no following redirects off the configured host)
 *   - sslverify => true
 *   - the worker RE-READS enabled+url so toggling off mid-flight drops it
 *
 * @package SignalNoiseTools
 * @since 4.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_UPTIME_HEARTBEAT_HOOK', 'sn_uptime_kuma_heartbeat' );

/**
 * Register a namespaced 5-minute recurrence. WordPress ships hourly /
 * twicedaily / daily only; the heartbeat needs sub-hourly resolution.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function sn_uptime_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['sn_five_minutes'] ) ) {
		$schedules['sn_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Signal & Noise)', 'signal-noise-tools' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'sn_uptime_cron_schedules' );

/**
 * Reconcile the scheduled event with the current settings. Runs on init:
 *   - enabled + url + not scheduled → schedule it (first run in +60s)
 *   - not enabled + scheduled       → clear it
 *
 * Idempotent — the wp_next_scheduled guard prevents double-scheduling.
 *
 * @since 4.9.0
 */
function sn_uptime_heartbeat_schedule() {
	$enabled = (bool) sn_setting( 'monitoring.uptime_kuma_enabled', false );
	$url     = (string) sn_setting( 'monitoring.uptime_kuma_push_url', '' );
	$next    = wp_next_scheduled( SN_UPTIME_HEARTBEAT_HOOK );

	if ( $enabled && '' !== $url ) {
		if ( ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'sn_five_minutes', SN_UPTIME_HEARTBEAT_HOOK );
		}
		return;
	}

	// Disabled (or url cleared) — tear down any existing schedule.
	if ( $next ) {
		wp_clear_scheduled_hook( SN_UPTIME_HEARTBEAT_HOOK );
	}
}
add_action( 'init', 'sn_uptime_heartbeat_schedule' );

/**
 * Cron worker. Re-reads enabled + url (so a mid-flight toggle-off is
 * respected), validates the URL, then GETs it with status=up.
 *
 * @since 4.9.0
 */
function sn_uptime_heartbeat_worker() {
	$enabled = (bool) sn_setting( 'monitoring.uptime_kuma_enabled', false );
	$url     = (string) sn_setting( 'monitoring.uptime_kuma_push_url', '' );

	if ( ! $enabled || '' === $url ) {
		return;
	}
	// T4 (Fix C): defence in depth. The save handler rejects non-https, but a
	// URL persisted before that guard (or via a direct sn_setting_update) must
	// not leak the monitor token over plaintext http.
	// wp_http_validate_url() omits 169.254.0.0/16 (link-local / cloud metadata);
	// reject it explicitly, consistent with the other outbound modules.
	if ( ! wp_http_validate_url( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 1 === preg_match( '#^169\.254\.#', (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
		return;
	}

	$push_url = add_query_arg( 'status', 'up', $url );

	$resp = wp_remote_get( $push_url, array(
		'timeout'     => 10,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array(
			'User-Agent' => 'SignalNoiseTools/' . SNT_VERSION . ' uptime-heartbeat',
		),
	) );

	$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );

	// Record last ping (timestamp + code) for the admin status line. 1h TTL is
	// fine — at a 5-min cadence the transient is always fresh while enabled.
	set_transient( 'sn_uptime_last_ping', array(
		'ts'   => time(),
		'code' => $code,
		'ok'   => ( $code >= 200 && $code < 400 ),
	), HOUR_IN_SECONDS );
}
add_action( SN_UPTIME_HEARTBEAT_HOOK, 'sn_uptime_heartbeat_worker' );
