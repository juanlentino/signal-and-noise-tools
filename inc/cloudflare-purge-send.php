<?php
/**
 * Cloudflare cache calls that read the answer (20.9.0).
 *
 * Every automatic purge used to be fire-and-forget: a non-blocking POST whose
 * response nobody read. A rejected token, a throttle or a timeout looked the
 * same as success, and the post-save probe was the only thing that could
 * notice, two minutes later, by finding a stale page. 3 of the last 20 saves
 * escalated to a zone purge that way.
 *
 * Now the call blocks, the answer is read, a transient failure is retried on
 * a schedule, and a failure nothing can retry is written down for the alert
 * email. Nothing here needs a person unless that record exists.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CF_RETRY_HOOK    = 'sn_cf_purge_retry';
const SN_CF_FAILURE_OPT   = 'sn_cf_purge_failure';
const SN_CF_RETRY_DELAYS  = array( 60, 300, 900 ); // seconds before tries 2, 3 and 4.
const SN_CF_SOFT_TRIGGERS = array( 'update', 'rollover' );

/**
 * Whether a failed call is worth another try. PURE. A transport error (0), a
 * throttle (429) and a Cloudflare 5xx pass with time; a 4xx does not.
 *
 * @param int $http HTTP status, 0 on a transport error.
 * @return bool
 */
function sn_cf_retryable( $http ) {
	$http = (int) $http;
	return 0 === $http || 429 === $http || $http >= 500;
}

/**
 * Which endpoint a whole-zone refresh uses. PURE. A code update marks pages
 * stale (`invalidate_cache`, Cloudflare 2026-09-28): they refresh on the next
 * request and the old copy stays available if the new code fails. Everything
 * else deletes (`purge_cache`): changed content must not be served again.
 *
 * @param string $trigger The purge ledger's trigger.
 * @return string
 */
function sn_cf_edge_endpoint( $trigger ) {
	return in_array( (string) $trigger, SN_CF_SOFT_TRIGGERS, true ) ? 'invalidate_cache' : 'purge_cache';
}

/**
 * Send one cache call and act on the answer.
 *
 * @param string $endpoint `purge_cache` or `invalidate_cache`.
 * @param array  $body     Request body (files, tags or purge_everything).
 * @param int    $attempt  0 on the first try.
 * @return bool True when Cloudflare confirmed it.
 */
function sn_cf_api_send( $endpoint, $body, $attempt = 0 ) {
	$endpoint = 'invalidate_cache' === $endpoint ? 'invalidate_cache' : 'purge_cache';
	$body     = (array) $body;
	$attempt  = max( 0, (int) $attempt );
	if ( ! sn_cf_is_configured() ) {
		return false;
	}
	$GLOBALS['sn_cf_send_last'] = 'failed';
	$r = sn_cf_api_post_blocking( '/zones/' . sn_cf_get_zone() . '/' . $endpoint, $body );
	if ( ! empty( $r['cf_success'] ) ) {
		$GLOBALS['sn_cf_send_last'] = 'ok';
		sn_cf_purge_failure_clear( $body, $endpoint );
		if ( isset( $body['purge_everything'] ) ) {
			// Stamped on confirmation only (first try or a retry): the attention
			// list reads the zone time as proof a refresh superseded older stale
			// rows, and the Cloudflare screen shows the other as "Last purge".
			update_option( SN_CF_LAST_ZONE_PURGE_OPT, time(), false );
			update_option( SN_CF_LAST_PURGE_OPT, array( 'time' => time(), 'kind' => 'all' ), false );
			// A retry has no open ledger row: the request that queued it wrote
			// edge false. Its success is a row of its own.
			if ( $attempt > 0 && function_exists( 'snt_purge_ledger_add' ) ) {
				snt_purge_ledger_add( array( 'trigger' => 'cron:' . SN_CF_RETRY_HOOK, 'redis' => false, 'pages' => false, 'edge' => true, 'cloudways' => 'not run' ) );
			}
		}
		return true;
	}
	$retry = sn_cf_retryable( $r['http'] );
	// Invalidate refused outright: do the purge it replaced.
	if ( 'invalidate_cache' === $endpoint && ! $retry ) {
		return sn_cf_api_send( 'purge_cache', $body, $attempt );
	}
	$delays = SN_CF_RETRY_DELAYS;
	// A retry WordPress could not store is no retry: fall through and record
	// it. One already waiting with the same arguments (WordPress refuses a
	// duplicate within ten minutes) is a retry all the same.
	if ( $retry && isset( $delays[ $attempt ] ) && function_exists( 'wp_schedule_single_event' ) ) {
		$args = array( $endpoint, $body, $attempt + 1 );
		if ( ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( SN_CF_RETRY_HOOK, $args ) )
			|| true === wp_schedule_single_event( time() + $delays[ $attempt ], SN_CF_RETRY_HOOK, $args ) ) {
			$GLOBALS['sn_cf_send_last'] = 'queued';
			return false;
		}
	}
	update_option(
		SN_CF_FAILURE_OPT,
		array(
			'time'     => time(),
			'http'     => (int) $r['http'],
			'endpoint' => $endpoint,
			'attempts' => $attempt + 1,
			'scope'    => md5( (string) wp_json_encode( $body ) ),
			'what'     => isset( $body['purge_everything'] ) ? 'everything' : ( isset( $body['tags'] ) ? 'tags' : count( (array) ( $body['files'] ?? array() ) ) . ' urls' ),
		),
		false
	);
	return false;
}
if ( function_exists( 'add_action' ) ) {
	add_action( SN_CF_RETRY_HOOK, 'sn_cf_api_send', 10, 3 );
}

/**
 * Clear the failure record when a confirmed call covers what failed: the
 * whole zone, or the same call again. A success for some other URL list, or
 * for the theme's tag, leaves it: that content may still be stale.
 *
 * @param array  $body     The confirmed call's body.
 * @param string $endpoint The endpoint that confirmed it.
 * @return void
 */
function sn_cf_purge_failure_clear( array $body, $endpoint = 'purge_cache' ) {
	// Only the whole zone covers everything. The theme's tag covers tagged
	// pages, not a directly purged file (the resume PDF) or an asset.
	// And only a PURGE of it: an invalidate keeps the old copy at the edge, so
	// it does not stand in for a failed purge of content that was removed.
	$wide = isset( $body['purge_everything'] ) && 'purge_cache' === $endpoint;
	// A zone refresh covers every narrower call still waiting to retry; left
	// scheduled, one could fail later and report content already fresh.
	if ( $wide && function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( SN_CF_RETRY_HOOK );
	}
	$f = get_option( SN_CF_FAILURE_OPT, false );
	if ( ! is_array( $f ) ) {
		return;
	}
	if ( $wide || md5( (string) wp_json_encode( $body ) ) === (string) ( $f['scope'] ?? '' ) ) {
		delete_option( SN_CF_FAILURE_OPT );
	}
}

/**
 * How the last send in this request ended: 'ok' (confirmed), 'queued' (a
 * retry is stored) or 'failed' (recorded).
 *
 * @return string
 */
function sn_cf_send_last() {
	return (string) ( $GLOBALS['sn_cf_send_last'] ?? 'failed' );
}

/**
 * Purge everything the theme tagged (theme 15.2.0: every cached page, feed
 * and machine file carries SN_EDGE_CACHE_TAG). One call covers every address
 * a save can touch. False when the theme does not tag yet.
 *
 * @return bool
 */
function sn_cf_purge_tag() {
	if ( ! defined( 'SN_EDGE_CACHE_TAG' ) || '' === (string) SN_EDGE_CACHE_TAG ) {
		return false;
	}
	return sn_cf_api_send( 'purge_cache', array( 'tags' => array( (string) SN_EDGE_CACHE_TAG ) ) );
}

/**
 * The failure nothing could retry, or null. Cleared by the next confirmed call.
 *
 * @return array{time:int,http:int,endpoint:string,attempts:int,what:string}|null
 */
function sn_cf_purge_failure() {
	$f = get_option( SN_CF_FAILURE_OPT, false );
	return is_array( $f ) && ! empty( $f['time'] ) ? $f : null;
}
