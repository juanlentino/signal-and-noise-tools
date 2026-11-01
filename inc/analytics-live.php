<?php
/**
 * Signal & Noise Tools: the live pair, "reading now" and "views today".
 *
 * Two routes over the realtime transient (inc/analytics-realtime.php), never
 * an Analytics Engine call on the request path:
 *
 *   GET signal-noise/v1/live        public, human class only, edge-cacheable
 *                                   for 30 s (the client puts a 30 s bucket in
 *                                   the query, since the edge keeps /wp-json/
 *                                   far longer than its headers ask);
 *   GET signal-noise/v1/live/admin  view_stats, every class, never cached.
 *
 * Either one schedules a refresh when the pair is older than 30 s, through
 * the same throttle the admin warmer uses, so at most one refresh is queued
 * however many readers poll. Only the authenticated admin read may also run
 * it now with spawn_cron(); the public route never spawns a loopback request
 * (2026-10-16 audit), so anonymous polling cannot drive origin work. Cookieless as the beacon is: the counts are the
 * daily visitor hashes the edge already keeps; nothing new is collected.
 *
 * Null and 0 stay apart: a cold cache answers null ("not measured"), a
 * warmed quiet site answers 0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_LIVE_CACHE_CONTROL = 'public, max-age=0, s-maxage=30';

/**
 * The payload. PURE over the realtime transient.
 *
 * @param bool $all_classes True for the admin answer (every class).
 * @return array{now:int|null,today:int|null,fetched:int|null,classes?:array<string,int>}
 */
function sn_analytics_live_payload( $all_classes ) {
	$cached  = get_transient( SN_ANALYTICS_REALTIME_KEY );
	$fetched = is_array( $cached ) && isset( $cached['fetched'] ) ? (int) $cached['fetched'] : null;
	$out     = array(
		'now'     => sn_analytics_realtime( 'human' ),
		'today'   => sn_analytics_views_today(),
		'pages'   => is_array( $cached ) && isset( $cached['pages'] ) && is_array( $cached['pages'] ) ? array_values( $cached['pages'] ) : null,
		'hour'    => is_array( $cached ) && isset( $cached['hour'] ) && is_array( $cached['hour'] ) ? array_values( $cached['hour'] ) : null,
		'fetched' => $fetched,
	);
	if ( ! $all_classes && is_array( $out['pages'] ) ) { // Scroll depth is admin only: /stats keeps its shape.
		$out['pages'] = array_map( static fn( $p ) => is_array( $p ) ? array_diff_key( $p, array( 'scroll' => 1 ) ) : $p, $out['pages'] );
	}
	if ( $all_classes ) {
		// Admin only: the public answer never says where readers came from.
		$out['surge']   = is_array( $cached ) && isset( $cached['surge'] ) && is_array( $cached['surge'] ) ? $cached['surge'] : null;
		$out['sources'] = is_array( $cached ) && isset( $cached['sources'] ) && is_array( $cached['sources'] ) ? array_values( $cached['sources'] ) : null;
		// Admin only (2026-11-01): one line per current reader, place, device and entry source; no visitor code.
		$out['readers'] = is_array( $cached ) && isset( $cached['readers'] ) && is_array( $cached['readers'] ) ? array_values( $cached['readers'] ) : null;
		// Admin only: a reader on a coded résumé link, labeled with its code
		// ("Resume via hera", inc/link-codes.php). The public list never names one.
		if ( is_array( $out['pages'] ) && is_array( $cached['links'] ?? null ) && $cached['links'] ) {
			$out['pages'] = array_merge( $out['pages'], array_values( $cached['links'] ) );
			usort( $out['pages'], static fn( $a, $b ) => (int) ( $b['readers'] ?? 0 ) <=> (int) ( $a['readers'] ?? 0 ) );
		}
		$out['classes'] = array();
		foreach ( array( 'human', 'suspect', 'bot' ) as $class ) {
			$out['classes'][ $class ] = sn_analytics_realtime( $class );
		}
	}
	return $out;
}

/**
 * The attributes a live figure carries, for assets/live-now.js to find it.
 * The class is the view's traffic class; the public strip is always human.
 *
 * @param string $key   'now' or 'today'.
 * @param string $class human|suspect|bot.
 * @return array<string,string>
 */
function sn_analytics_live_attrs( $key, $class = 'human' ) {
	return array( 'data-sn-live' => (string) $key, 'data-sn-live-class' => (string) $class );
}

/**
 * Gate for the admin route.
 *
 * @return bool
 */
function sn_analytics_live_can_read() {
	return current_user_can( 'view_stats' ) || current_user_can( 'manage_options' );
}

/**
 * Keep the pair warm for whoever is reading; one refresh at a time.
 *
 * @param bool $may_spawn True only for the authenticated admin read: run a
 *                        due refresh now with spawn_cron(). The public route
 *                        passes false and leaves it to the scheduler.
 * @return void
 */
function sn_analytics_live_touch( $may_spawn = false ) {
	$due = sn_analytics_realtime_schedule_if_stale();
	if ( ! $due && sn_analytics_realtime_age() > SN_ANALYTICS_REALTIME_TTL ) {
		// A stale cache with a refresh already queued and overdue: the run
		// spawned for it died (a plugin update's maintenance mode does that).
		// Run it now; otherwise every poll sees "already queued" and the cache
		// stays cold until the host's next system cron.
		$next = wp_next_scheduled( SN_ANALYTICS_REALTIME_HOOK );
		$due  = false !== $next && (int) $next <= time();
	}
	if ( $may_spawn && $due && function_exists( 'spawn_cron' ) ) {
		// Run it now rather than on the next origin request: behind the edge
		// cache the next request may be a whole bucket away. spawn_cron()
		// keeps its own lock, so polls cannot stack runs.
		spawn_cron();
	}
}

/**
 * @param array  $payload Body.
 * @param string $cache   Cache-Control value.
 * @return WP_REST_Response
 */
function sn_analytics_live_response( array $payload, $cache ) {
	$res = new WP_REST_Response( $payload, 200 );
	$res->header( 'Cache-Control', $cache );
	return $res;
}

/**
 * Register both routes.
 *
 * @return void
 */
function sn_analytics_live_register_routes() {
	register_rest_route( 'signal-noise/v1', '/live', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => static function () {
			sn_analytics_live_touch();
			return sn_analytics_live_response( sn_analytics_live_payload( false ), SN_ANALYTICS_LIVE_CACHE_CONTROL );
		},
	) );
	register_rest_route( 'signal-noise/v1', '/live/admin', array(
		'methods'             => 'GET',
		'permission_callback' => 'sn_analytics_live_can_read',
		'callback'            => static function () {
			sn_analytics_live_touch( true );
			return sn_analytics_live_response( sn_analytics_live_payload( true ), 'private, no-store' );
		},
	) );
}
add_action( 'rest_api_init', 'sn_analytics_live_register_routes' );

/**
 * One file, two handles: the public one carries its config (public route,
 * 60 s) and needs nothing; the admin one reads the gated route through
 * wp.apiFetch. Self-gating: a page with no [data-sn-live] does nothing.
 * The admin handle carries no localized data, since OpenStation repeats a
 * dependency's localize per entry.
 *
 * @return void
 */
function sn_analytics_live_register_scripts() {
	$src = SNT_URL . 'assets/live-now.js';
	wp_register_script( 'sn-live-now', $src, array(), SNT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_register_script( 'sn-live-now-admin', $src, array( 'wp-api-fetch' ), SNT_VERSION, true );
}
add_action( 'init', 'sn_analytics_live_register_scripts', 4 );
