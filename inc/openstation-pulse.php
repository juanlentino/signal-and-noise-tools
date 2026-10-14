<?php
/**
 * Signal & Noise Tools: feeding OpenStation's Pulse Monitor (1.1.14).
 *
 * The Pulse Monitor's server feed (OpenStation includes/pulse-monitor.php,
 * every hook Experimental) beats once per origin request and diagnoses site
 * health from a snapshot. This module tells it what S&N knows, and nothing
 * else. It is deliberately thin and deletable: remove this file and its one
 * require line and nothing else changes.
 *
 *   - openstation_pulse_request_kind: two public routes stop reading as API
 *     use. The feed-open pixel is a feed reader opening a note, so it beats as
 *     a visitor (or a bot) when an admin has turned page views on
 *     (openstation_pulse_counts_visitors()), and stays off the trace when not.
 *     The live-now poll reaching origin is an edge-cache miss, not a reader,
 *     so it never beats. Only the logged-out 'api' lane is touched.
 *     The analytics beacon is not here on purpose: it posts to /_sn/px, which
 *     the analytics Worker owns at the edge, so it never reaches WordPress.
 *   - openstation_pulse_is_bot: S&N's own UA classifier
 *     (sn_rss_tracker_is_bot(), pure, no DB) can only ADD a bot verdict, so
 *     fediverse preview fetchers stop counting as visitors.
 *   - openstation_pulse_health: a Better Stack monitor reporting down counts
 *     as one failed request, read from the cached snapshot only.
 *   - openstation_pulse_record: two beats, 'provenance' (each signing dispatch
 *     to the provenance Worker, failed when it does not return 2xx) and
 *     'cache-purge' (sn_cf_purge_everything(), failed when Cloudflare refused
 *     it; a purge queued for a retry is not a failure). Upstream drops a
 *     listener's own requests, so a purge the owner clicks while watching
 *     does not beat; cron-driven ones do.
 *
 * Privacy: nothing here stores or relays a URL, an IP or a user agent. The UA
 * is classified in memory and the provenance URL only compared.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_PULSE_FEED_OPEN_ROUTE = '/signal-noise/v1/feed-open';
const SNT_PULSE_LIVE_ROUTE      = '/signal-noise/v1/live';

/**
 * The Pulse kind for one request. PURE.
 *
 * @param string $kind            Upstream's classification.
 * @param string $route           REST route ('' when not a REST request).
 * @param bool   $counts_visitors Whether page views beat (the admin opt-in).
 * @param bool   $is_bot          Whether the user agent is automated.
 * @return string Kind, or '' to keep the request off the monitor.
 */
function snt_pulse_kind_for( $kind, $route, $counts_visitors, $is_bot ) {
	if ( 'api' !== $kind ) {
		return $kind;
	}
	if ( SNT_PULSE_FEED_OPEN_ROUTE === $route ) {
		if ( ! $counts_visitors ) {
			return '';
		}
		return $is_bot ? 'bots' : 'visitors';
	}
	return SNT_PULSE_LIVE_ROUTE === $route ? '' : $kind;
}

/**
 * Filter: openstation_pulse_request_kind.
 *
 * @param string $kind Upstream's classification.
 * @return string
 */
function snt_pulse_request_kind( $kind ) {
	$vars  = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars ) ? (array) $GLOBALS['wp']->query_vars : array();
	$route = isset( $vars['rest_route'] ) ? untrailingslashit( (string) $vars['rest_route'] ) : '';
	if ( 'api' !== $kind || SNT_PULSE_FEED_OPEN_ROUTE !== $route ) {
		return snt_pulse_kind_for( (string) $kind, $route, false, false );
	}
	$counts = function_exists( 'openstation_pulse_counts_visitors' ) && openstation_pulse_counts_visitors();
	$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$is_bot = $counts && function_exists( 'openstation_pulse_is_bot' ) && openstation_pulse_is_bot( $ua );
	return snt_pulse_kind_for( (string) $kind, $route, $counts, $is_bot );
}

/**
 * Filter: openstation_pulse_is_bot. S&N's classifier only adds a verdict.
 *
 * @param bool   $is_bot     Upstream's verdict.
 * @param string $user_agent Raw user agent.
 * @return bool
 */
function snt_pulse_is_bot( $is_bot, $user_agent ) {
	return (bool) $is_bot || ( function_exists( 'sn_rss_tracker_is_bot' ) && sn_rss_tracker_is_bot( (string) $user_agent ) );
}

/**
 * The health snapshot with Better Stack's verdict folded in. PURE.
 *
 * Each monitor reporting down counts as one request that failed, added to
 * both sides so the share stays a share.
 * ponytail: the client ignores failures until requests >= 4, and the
 * snapshot is a 90 s transient warmed only when an admin panel reads uptime;
 * a cron-warmed status would make this always fresh.
 *
 * @param array       $snapshot Upstream snapshot {siteHealth, requests, failures, cronLate}.
 * @param array|false $uptime   The cached uptime snapshot {fetched_at, rows}.
 * @return array
 */
function snt_pulse_health_with_uptime( $snapshot, $uptime ) {
	if ( ! is_array( $uptime ) || ! isset( $uptime['rows'] ) || ! is_array( $uptime['rows'] ) ) {
		return $snapshot;
	}
	$down = 0;
	foreach ( $uptime['rows'] as $row ) {
		if ( is_array( $row ) && 'monitor' === ( $row['kind'] ?? '' ) && 'down' === ( $row['status'] ?? '' ) ) {
			++$down;
		}
	}
	if ( $down > 0 ) {
		$snapshot['requests'] = (int) ( $snapshot['requests'] ?? 0 ) + $down;
		$snapshot['failures'] = (int) ( $snapshot['failures'] ?? 0 ) + $down;
	}
	return $snapshot;
}

/**
 * Filter: openstation_pulse_health. Reads the transient, never the API.
 *
 * @param array $snapshot Upstream snapshot.
 * @return array
 */
function snt_pulse_health( $snapshot ) {
	$uptime = defined( 'SN_UPTIME_STATUS_TRANSIENT' ) ? get_transient( SN_UPTIME_STATUS_TRANSIENT ) : false;
	return snt_pulse_health_with_uptime( (array) $snapshot, $uptime );
}

/**
 * Queue one S&N beat on the Pulse Monitor. A no-op without the feed.
 *
 * @param string $kind  Slug-style kind.
 * @param bool   $error Draw it as a failed beat.
 * @return bool Whether it was queued.
 */
function snt_pulse_beat( $kind, $error = false ) {
	if ( ! function_exists( 'openstation_pulse_record' ) ) {
		return false;
	}
	return (bool) openstation_pulse_record( $kind, array( 'error' => (bool) $error ) );
}

/**
 * Whether a whole-zone purge beats as failed. PURE. Only a refusal is a
 * failure: a confirmed purge is good, and one Cloudflare queued for a retry
 * (rate limited) has not failed yet.
 *
 * @param bool   $confirmed Cloudflare confirmed it in this request.
 * @param string $send_last sn_cf_send_last(): 'queued' when a retry is queued.
 * @return bool
 */
function snt_pulse_purge_failed( $confirmed, $send_last ) {
	return ! $confirmed && 'queued' !== $send_last;
}

/**
 * Action: http_api_debug. Beats once per provenance signing dispatch.
 *
 * @param array|WP_Error $response Response or error.
 * @param string         $context  'response' after the request.
 * @param string         $class    Transport class.
 * @param array          $args     Request args.
 * @param string         $url      Request URL, compared and dropped.
 * @return void
 */
function snt_pulse_on_http( $response, $context, $class, $args, $url ) {
	if ( 'response' !== $context || ! function_exists( 'sn_prov_worker_url' ) ) {
		return;
	}
	$hook = (string) sn_prov_worker_url();
	if ( '' === $hook || $hook !== (string) $url ) {
		return;
	}
	$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	snt_pulse_beat( 'provenance', $code < 200 || $code >= 300 );
}

// Every seam is Experimental and lives in OpenStation's
// includes/pulse-monitor.php; the guard reads on plugins_loaded so plugin
// order cannot decide it. Post-rename only: no desktop_mode_* twin exists.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'openstation_pulse_record' ) ) {
			return;
		}
		add_filter( 'openstation_pulse_request_kind', 'snt_pulse_request_kind' );
		add_filter( 'openstation_pulse_is_bot', 'snt_pulse_is_bot', 10, 2 );
		add_filter( 'openstation_pulse_health', 'snt_pulse_health' );
		add_action( 'http_api_debug', 'snt_pulse_on_http', 10, 5 );
	}
);
