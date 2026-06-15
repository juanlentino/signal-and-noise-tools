<?php
/**
 * Signal & Noise Tools — WebSub (PubSubHubbub) publisher ping (D4).
 *
 * The push counterpart to IndexNow: on publish / update / unpublish / delete of
 * a post, the plugin notifies a WebSub hub that the feed changed, so the hub
 * re-fetches it and fans out to subscribed feed readers instantly (instead of
 * them polling). The companion theme advertises `<atom:link rel="hub">` in the
 * RSS2 + Atom feeds (inc/feed-websub.php) — discovery there + this ping = push.
 *
 * Hub: the public default `https://pubsubhubbub.appspot.com/`, overridable via
 * the `sn_websub_hub` filter. That SAME filter (same tag + identical default
 * literal) is read by the theme's feed advertisement, so one add_filter keeps
 * the advertised hub and the pinged hub in sync. Disable the whole feature with
 * the `sn_websub_enabled` filter (default true).
 *
 * Like IndexNow, the ping is deferred to a single WP-Cron event so it adds zero
 * latency to the publish request; WP-Cron de-dupes identical (hook, args) within
 * ~10 min, which debounces the several lifecycle hooks one save fires. The hub
 * URL is filterable, so the ping resolves the hub host through the shared
 * resolve-then-range-check SSRF guard (inc/ssrf-guard.php) and fails closed —
 * the topic feed URLs are self-derived from home_url and need no guard.
 *
 * @package SignalNoiseTools
 * @since 6.17.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// MUST match the theme's inc/feed-websub.php default literal so the advertised
// hub and the pinged hub agree when no sn_websub_hub filter is registered.
const SN_WEBSUB_DEFAULT_HUB = 'https://pubsubhubbub.appspot.com/';
const SN_WEBSUB_RESULT_OPT  = 'sn_websub_last_result';
const SN_WEBSUB_CRON_HOOK   = 'sn_websub_ping';

/** The WebSub hub to notify (public default, filterable). '' disables advertising/pinging. */
function sn_websub_hub() {
	return trim( (string) apply_filters( 'sn_websub_hub', SN_WEBSUB_DEFAULT_HUB ) );
}

/** Whether WebSub publishing is on (default true; disable via the filter). */
function sn_websub_is_enabled() {
	return (bool) apply_filters( 'sn_websub_enabled', true );
}

/**
 * The feed topic URLs the hub should re-fetch: the RSS2 + Atom feeds the theme
 * advertises the hub in. De-duplicated, empties dropped.
 *
 * @return string[]
 */
function sn_websub_topics() {
	$topics = array( get_feed_link( 'rss2' ), get_feed_link( 'atom' ) );
	$clean  = array();
	foreach ( $topics as $t ) {
		$t = (string) $t;
		if ( '' !== $t ) {
			$clean[ $t ] = $t; // de-dupe by value
		}
	}
	return array_values( $clean );
}

/**
 * Build the form-encoded WebSub publish notification body. The WebSub spec uses
 * a repeated `hub.url` param per topic (not array-bracketed keys), so this is
 * built by hand rather than http_build_query.
 *
 * @param string[] $topics Feed topic URLs.
 * @return string `hub.mode=publish&hub.url=<enc>&hub.url=<enc>`.
 */
function sn_websub_build_body( $topics ) {
	$parts = array( 'hub.mode=publish' );
	foreach ( (array) $topics as $t ) {
		$parts[] = 'hub.url=' . rawurlencode( (string) $t );
	}
	return implode( '&', $parts );
}

/**
 * Schedule a single deferred ping. WP-Cron de-dupes identical (hook, args)
 * within ~10 min → natural debounce when several lifecycle hooks fire on one
 * save. Topics are derived at run time (in the callback), so no args are passed.
 */
function sn_websub_enqueue() {
	if ( ! sn_websub_is_enabled() ) {
		return;
	}
	wp_schedule_single_event( time(), SN_WEBSUB_CRON_HOOK );
}

/**
 * Cron callback: POST the publish notification to the hub (blocking, so the
 * result can be recorded). Routes the filterable hub host through the shared
 * SSRF guard and fails closed; uses wp_safe_remote_post + redirection=0 so a
 * validated host can't redirect to an internal one.
 */
function sn_websub_ping() {
	if ( ! sn_websub_is_enabled() ) {
		return;
	}
	$hub = sn_websub_hub();
	if ( '' === $hub ) {
		return;
	}

	$result = array( 'time' => time(), 'hub' => $hub, 'code' => 0, 'error' => '' );

	$host = (string) wp_parse_url( $hub, PHP_URL_HOST );
	if ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) ) {
		$result['error'] = 'hub host blocked (SSRF guard)';
		update_option( SN_WEBSUB_RESULT_OPT, $result, false );
		return;
	}

	$topics = sn_websub_topics();
	if ( empty( $topics ) ) {
		return;
	}

	$response = wp_safe_remote_post( $hub, array(
		'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
		'body'        => sn_websub_build_body( $topics ),
		'timeout'     => 10,
		'redirection' => 0,
		'blocking'    => true,
		'sslverify'   => true,
	) );

	if ( is_wp_error( $response ) ) {
		$result['error'] = $response->get_error_message();
	} else {
		$result['code'] = (int) wp_remote_retrieve_response_code( $response );
	}
	update_option( SN_WEBSUB_RESULT_OPT, $result, false );
}

/**
 * Publish + update — draft→publish or an edit of an already-published post.
 * Scoped to post_type 'post' (only posts appear in the main feed). Mirrors
 * inc/indexnow.php's wp_after_insert_post handler.
 */
function sn_websub_on_insert( $post_id, $post, $update, $post_before ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return;
	}
	sn_websub_enqueue();
}

/**
 * Unpublish — publish → any non-public status. The precise
 * `old==='publish' && new!=='publish'` condition excludes plain edits
 * (publish→publish) and draft→publish (both owned by sn_websub_on_insert).
 */
function sn_websub_on_transition( $new_status, $old_status, $post ) {
	if ( 'post' !== $post->post_type ) {
		return;
	}
	if ( 'publish' !== $old_status || 'publish' === $new_status ) {
		return;
	}
	sn_websub_enqueue();
}

/** Hard delete of a published post (the feed loses an item). */
function sn_websub_on_delete( $post_id, $post ) {
	if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return;
	}
	sn_websub_enqueue();
}

// Registration — skipped under the CLI test harness (which calls the functions
// directly and does not stub add_action).
if ( ! defined( 'SN_WEBSUB_TEST' ) || ! SN_WEBSUB_TEST ) {
	add_action( 'wp_after_insert_post', 'sn_websub_on_insert', 30, 4 );
	add_action( 'transition_post_status', 'sn_websub_on_transition', 10, 3 );
	add_action( 'before_delete_post', 'sn_websub_on_delete', 10, 2 );
	add_action( SN_WEBSUB_CRON_HOOK, 'sn_websub_ping' );
}
