<?php
/**
 * Authenticated rollup-refresh trigger — the seam a Cloudflare Cron Trigger uses to
 * drive the durable rollup + realtime refresh on a GUARANTEED schedule.
 *
 * WHY: the rollup + "views today" freshness otherwise ride on WP-Cron, which only
 * fires when the site receives front-end traffic and silently stalls on an idle site
 * — the "views today went cold / stale" failure mode. The analytics Worker already
 * runs at the edge; a Worker Cron Trigger POSTing here every N minutes drives the
 * refresh on CF's reliable schedule regardless of site traffic or WP-Cron health.
 *
 *   POST signal-noise/v1/analytics/refresh
 *     Auth : X-SN-Refresh-Key header, constant-time-compared against SN_SRV_TOKEN —
 *            the PRIVATE server token already shared with the Worker (it also gates
 *            srv:1 beacons), so this adds NO new secret. Fails CLOSED (503) when
 *            SN_SRV_TOKEN is unset, so a misconfigured deploy never runs open.
 *     Runs : sn_analytics_run_rollup() + sn_analytics_realtime_refresh(). Both are
 *            internally guarded (no-op without AE config) and idempotent, so a
 *            re-poke never double-counts. Never manage_options — this is a machine
 *            caller (the Worker), not an admin session.
 *
 * @package SignalAndNoiseTools
 * @since   9.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The private shared secret used to authenticate the refresh poke: SN_SRV_TOKEN
 * (constant), overridable via the `sn_analytics_refresh_secret` filter. '' when the
 * constant is unset — which the permission gate treats as "fail closed".
 *
 * @return string
 */
function sn_analytics_refresh_secret() {
	$token = defined( 'SN_SRV_TOKEN' ) ? (string) SN_SRV_TOKEN : '';
	return (string) apply_filters( 'sn_analytics_refresh_secret', $token );
}

/**
 * Permission gate: allow ONLY when a secret is configured AND the request presents
 * it in X-SN-Refresh-Key (constant-time compare). No secret → 503 (fail closed);
 * absent/wrong key → 403.
 *
 * @param WP_REST_Request $request
 * @return true|WP_Error
 */
function sn_analytics_refresh_permission( $request ) {
	$secret = sn_analytics_refresh_secret();
	if ( '' === $secret ) {
		return new WP_Error(
			'sn_refresh_unconfigured',
			'Analytics refresh trigger is not configured (SN_SRV_TOKEN unset).',
			array( 'status' => 503 )
		);
	}
	// WP normalizes the inbound header 'X-SN-Refresh-Key' to 'x_sn_refresh_key'.
	$given = (string) $request->get_header( 'x_sn_refresh_key' );
	if ( '' === $given || ! hash_equals( $secret, $given ) ) {
		return new WP_Error(
			'sn_refresh_forbidden',
			'Invalid analytics refresh key.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Run the durable rollup + realtime refresh. Both are internally guarded and
 * idempotent; a missing AE config no-ops rather than erroring. Returns a small
 * status envelope for the caller's observability.
 *
 * @param WP_REST_Request $request
 * @return array{ok:bool, ran:string[]}
 */
function sn_analytics_refresh_run( $request ) {
	$ran = array();
	if ( function_exists( 'sn_analytics_run_rollup' ) ) {
		sn_analytics_run_rollup();
		$ran[] = 'rollup';
	}
	if ( function_exists( 'sn_analytics_realtime_refresh' ) ) {
		sn_analytics_realtime_refresh();
		$ran[] = 'realtime';
	}
	return array( 'ok' => true, 'ran' => $ran );
}

add_action( 'rest_api_init', function () {
	$ns = defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';
	register_rest_route( $ns, '/analytics/refresh', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => 'sn_analytics_refresh_permission',
		'callback'            => 'sn_analytics_refresh_run',
	) );
} );
