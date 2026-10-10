<?php
/**
 * Signal & Noise Tools: the desktop widgets stay current without a reload.
 *
 * Two owner-only routes:
 *
 *   GET /desktop/pulse    Stamps that move when something a widget shows
 *                         changed (content: posts and the schedule; deploy:
 *                         versions and worker deploys). assets/snt-pulse.js
 *                         reads it every 20 s while the window is focused,
 *                         and a widget re-reads its own data only when its
 *                         stamp moves. One indexed query and two option reads.
 *   GET /desktop/systems  The SN Systems lines that were read once at page
 *                         load (health, cron, edge and cache), so its 2-minute
 *                         poll refreshes them too. Same functions, same caches.
 *
 * Why (owner, 2026-10-10): a post scheduled in another window did not reach
 * SN Queue until the PWA was reloaded.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stamps. Each is an opaque hash: equal means nothing that widget shows
 * changed; the values themselves are never shown.
 *
 * @return array{content:string,deploy:string,at:int}
 */
function snt_desktop_pulse() {
	global $wpdb, $wp_version;
	// Per status: how many posts and pages, and the newest edit. A schedule,
	// publish, trash or edit moves one of them.
	$rows = $wpdb->get_results( "SELECT post_status, COUNT(*) AS n, MAX(post_modified_gmt) AS m FROM {$wpdb->posts} WHERE post_type IN ('post','page') GROUP BY post_status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one grouped count, read every 20 s at most; a cache would delay exactly what it reports.
	$theme = wp_get_theme( get_template() );
	$deploy = array(
		SNT_VERSION,
		(string) $theme->get( 'Version' ),
		(string) $wp_version,
		get_option( defined( 'SNT_DEPLOY_WORKERS_SEEN_OPT' ) ? SNT_DEPLOY_WORKERS_SEEN_OPT : 'snt_deploy_workers_seen' ),
	);
	return array(
		'content' => md5( (string) wp_json_encode( is_array( $rows ) ? $rows : array() ) ),
		'deploy'  => md5( (string) wp_json_encode( $deploy ) ),
		'at'      => time(),
	);
}

/**
 * The SN Systems lines the page-load localize carries, read again.
 *
 * @return array{healthSummary:mixed,cronSummary:mixed,statusExtra:mixed}
 */
function snt_desktop_systems_lines() {
	return array(
		'healthSummary' => function_exists( 'snt_health_summary_for_localize' ) ? snt_health_summary_for_localize() : null,
		'cronSummary'   => function_exists( 'snt_cron_summary_for_localize' ) ? snt_cron_summary_for_localize() : array(),
		'statusExtra'   => function_exists( 'snt_desktop_status_extra' ) ? snt_desktop_status_extra() : null,
	);
}

add_action( 'rest_api_init', static function () {
	$route = static function ( $fn ) {
		return static function () use ( $fn ) {
			$res = rest_ensure_response( $fn() );
			$res->header( 'Cache-Control', 'private, no-store' );
			return $res;
		};
	};
	register_rest_route( 'signal-noise/v1', '/desktop/pulse', array(
		'methods'             => 'GET',
		'callback'            => $route( 'snt_desktop_pulse' ),
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
	register_rest_route( 'signal-noise/v1', '/desktop/systems', array(
		'methods'             => 'GET',
		'callback'            => $route( 'snt_desktop_systems_lines' ),
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
} );
