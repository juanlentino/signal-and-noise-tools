<?php
/**
 * Signal & Noise — IndexNow submission.
 *
 * On publish / update / unpublish / delete of a post or page, POSTs the
 * changed URL(s) to https://api.indexnow.org/indexnow so participating
 * search engines (Bing, Yandex, Seznam, Naver, …) re-crawl promptly.
 * Google is NOT an IndexNow participant.
 *
 * Turnkey: the plugin auto-generates a key and serves /<key>.txt itself
 * (plugins_loaded intercept, mirroring inc/login-hide.php) — the owner
 * only flips the Enable toggle, no filesystem step. Submission is deferred
 * to a single WP-Cron event so it adds zero latency to the publish request;
 * the cron callback makes the blocking POST and records the HTTP result.
 *
 * @package SignalNoiseTools
 * @since 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_INDEXNOW_ENDPOINT   = 'https://api.indexnow.org/indexnow';
const SN_INDEXNOW_KEY_OPT    = 'sn_indexnow_key';
const SN_INDEXNOW_RESULT_OPT = 'sn_indexnow_last_result';
const SN_INDEXNOW_CRON_HOOK  = 'sn_indexnow_submit';

/** Whether IndexNow submission is enabled (Automation → IndexNow toggle). */
function sn_indexnow_is_enabled() {
	return (bool) sn_setting( 'indexnow.enabled', false );
}

/** The stored submission key (empty string when never generated). */
function sn_indexnow_get_key() {
	return (string) get_option( SN_INDEXNOW_KEY_OPT, '' );
}

/**
 * Generate + persist a key if none exists; return the key. 32 lowercase hex
 * chars — unambiguously valid per the IndexNow charset ([a-f0-9], 8–128).
 * Idempotent: returns the existing key unchanged when one is already stored.
 */
function sn_indexnow_ensure_key() {
	$key = sn_indexnow_get_key();
	if ( '' === $key ) {
		$key = bin2hex( random_bytes( 16 ) );
		update_option( SN_INDEXNOW_KEY_OPT, $key, false );
	}
	return $key;
}

/** Force-regenerate the key (admin "Regenerate" action). Invalidates the old file. */
function sn_indexnow_regenerate_key() {
	$key = bin2hex( random_bytes( 16 ) );
	update_option( SN_INDEXNOW_KEY_OPT, $key, false );
	return $key;
}

/** The advertised keyLocation URL: home-root /<key>.txt (empty when no key). */
function sn_indexnow_key_url() {
	$key = sn_indexnow_get_key();
	return '' === $key ? '' : home_url( '/' . $key . '.txt' );
}
