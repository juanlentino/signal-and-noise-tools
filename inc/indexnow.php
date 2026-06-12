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

/**
 * Decide whether a request path should serve the key file. Returns the key to
 * emit, or '' if the path isn't the key file / IndexNow is off / no key stored.
 * Pure + side-effect-free so it's CLI-testable independent of header()/exit.
 * A strict regex pre-check means the option is only read when the path LOOKS
 * like a key file (no per-request get_option on normal traffic); hash_equals()
 * is constant-time and means no other *.txt is ever served.
 */
function sn_indexnow_key_for_request( $request_uri ) {
	$path = '/' . ltrim( (string) strtok( (string) $request_uri, '?' ), '/' );
	if ( 1 !== preg_match( '#^/([a-f0-9]{8,128})\.txt$#', $path, $m ) ) {
		return '';
	}
	if ( ! sn_indexnow_is_enabled() ) {
		return '';
	}
	$key = sn_indexnow_get_key();
	if ( '' === $key || ! hash_equals( $key, $m[1] ) ) {
		return '';
	}
	return $key;
}

/**
 * Serve /<key>.txt before WP routes the request (so a path with no rewrite
 * rule won't 404). Mirrors inc/login-hide.php's plugins_loaded intercept.
 */
add_action( 'plugins_loaded', function() {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	$key = sn_indexnow_key_for_request( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
	if ( '' === $key ) {
		return;
	}
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $key; // raw key — a text/plain body, no markup to escape
	exit;
}, 2 );

/**
 * De-dupe + sanity-filter the given URLs and schedule a single deferred
 * submission. Keeps only same-host https URLs (IndexNow 422s on host
 * mismatch; the list is self-derived from home_url so this is data hygiene,
 * not SSRF — the endpoint is a fixed constant). WP-Cron de-dupes identical
 * (hook, serialized-args) within ~10 min → natural debounce when several
 * lifecycle hooks fire on one save.
 */
function sn_indexnow_enqueue( $urls ) {
	if ( ! sn_indexnow_is_enabled() || '' === sn_indexnow_get_key() ) {
		return;
	}
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$clean = array();
	foreach ( (array) $urls as $url ) {
		$url = (string) $url;
		if ( '' === $url
			|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
			|| wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			continue;
		}
		$clean[ $url ] = $url; // de-dupe by value
	}
	if ( empty( $clean ) ) {
		return;
	}
	// Numerically-indexed args so the callback receives $urls positionally
	// (PHP 8+ forwards string keys as named params → fatal mismatch).
	wp_schedule_single_event( time(), SN_INDEXNOW_CRON_HOOK, array( array_values( $clean ) ) );
}

/**
 * Cron callback: POST the URL list to IndexNow (blocking, so the response
 * code can be logged) and record the outcome for the admin panel.
 */
function sn_indexnow_submit( $urls ) {
	$urls = array_values( array_filter( array_map( 'strval', (array) $urls ) ) );
	$key  = sn_indexnow_get_key();
	if ( empty( $urls ) || '' === $key ) {
		return;
	}
	$response = wp_remote_post( SN_INDEXNOW_ENDPOINT, array(
		'headers'   => array( 'Content-Type' => 'application/json; charset=utf-8' ),
		'body'      => wp_json_encode( array(
			'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'key'         => $key,
			'keyLocation' => sn_indexnow_key_url(),
			'urlList'     => $urls,
		) ),
		'timeout'   => 10,
		'blocking'  => true,
		'sslverify' => true,
	) );

	$result = array( 'time' => time(), 'count' => count( $urls ), 'code' => 0, 'error' => '' );
	if ( is_wp_error( $response ) ) {
		$result['error'] = $response->get_error_message();
	} else {
		$result['code'] = (int) wp_remote_retrieve_response_code( $response );
	}
	update_option( SN_INDEXNOW_RESULT_OPT, $result, false );
}
add_action( SN_INDEXNOW_CRON_HOOK, 'sn_indexnow_submit' );

/**
 * The URL set to submit when a published post/page changes: its permalink
 * plus the /notes/ listing (which re-orders on any note change).
 */
function sn_indexnow_urls_for_post( $post_or_id ) {
	return array( get_permalink( $post_or_id ), home_url( '/notes/' ) );
}

/**
 * Publish + update — a draft→publish or an edit of an already-published post.
 * Mirrors inc/cloudflare-purge.php's wp_after_insert_post handler.
 */
function sn_indexnow_on_insert( $post_id, $post, $update, $post_before ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	sn_indexnow_enqueue( sn_indexnow_urls_for_post( $post_id ) );
}
add_action( 'wp_after_insert_post', 'sn_indexnow_on_insert', 30, 4 );

/**
 * Unpublish / trash — publish → any non-public status. transition_post_status
 * ALSO fires on same-status saves (a plain edit is publish→publish); the
 * precise `old==='publish' && new!=='publish'` condition excludes that, so a
 * normal edit never reaches here — that path is owned by sn_indexnow_on_insert.
 * draft→publish is also excluded (old≠publish) → no overlap.
 */
function sn_indexnow_on_transition( $new_status, $old_status, $post ) {
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	if ( 'publish' !== $old_status || 'publish' === $new_status ) {
		return;
	}
	sn_indexnow_enqueue( sn_indexnow_urls_for_post( $post ) );
}
add_action( 'transition_post_status', 'sn_indexnow_on_transition', 10, 3 );

/**
 * Hard delete — permanent removal (force-delete / empty-trash) of a published
 * post. before_delete_post does NOT fire on trashing (that's a transition);
 * get_permalink() is still resolvable here.
 */
function sn_indexnow_on_delete( $post_id, $post ) {
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	sn_indexnow_enqueue( sn_indexnow_urls_for_post( $post ) );
}
add_action( 'before_delete_post', 'sn_indexnow_on_delete', 10, 2 );
