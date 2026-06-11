<?php
/**
 * Signal & Noise — Cloudflare Analytics Engine SQL read-client.
 *
 * Library module (P2 data layer). Exposes two public surfaces:
 *
 *   sn_analytics_config()        — resolves account_id + token from wp-config
 *                                   constants; returns null when not configured.
 *   sn_analytics_query( $sql )   — POSTs a SQL query to the AE SQL API and
 *                                   returns the decoded `data` array, or null
 *                                   on any failure (error captured in transient).
 *   sn_analytics_last_error()    — reads the last captured error, if any.
 *
 * ── Analytics Engine dataset contract (for downstream rollup queries) ────────
 *
 * Each event written by the edge worker uses this field layout:
 *
 *   blob1  = event_type   ('pv' | 'sc' | 'tm')
 *   blob2  = path          e.g. '/notes/my-note'
 *   blob3  = referrer_host e.g. 'twitter.com'
 *   blob4  = country       ISO-3166 alpha-2
 *   blob5  = device        'mobile' | 'tablet' | 'desktop'
 *   blob6  = host          e.g. 'juanlentino.com'
 *
 *   double1 = scroll_pct   0–100 (scroll depth percentage)
 *   double2 = time_ms      dwell time in milliseconds
 *   double3 = bot_score    0–99 (CF bot management score)
 *
 *   index1  = visitor_day_hash   SHA-256(IP + date) for approx-unique counting
 *
 * Aggregation notes:
 *   - Use sum(_sample_interval) for true event counts (AE samples; this corrects
 *     for the sampling rate).
 *   - Use count(distinct index1) for approximate unique visitor counts.
 *
 * ── Architecture ─────────────────────────────────────────────────────────────
 *
 * This is a pure library module — no WP actions or filters are registered here.
 * Wire it into a cron callback or REST endpoint in a downstream module once
 * the rollup table is ready.
 *
 * Mirrors the patterns of inc/plausible-api.php:
 *   - Constant-first config resolution (file-based wp-config, read-only token).
 *   - wp_remote_post with timeout=6 + redirection=0 (SSRF hardening).
 *   - Error capture: url + code + 240-char body excerpt → transient.
 *
 * @package SignalNoise
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient key for the last Analytics Engine API error.
 */
const SN_ANALYTICS_ERR_KEY = 'sn_analytics_last_error';

/**
 * Resolve account_id + token from wp-config constants.
 *
 * Config resolution (mirrors inc/plausible-api.php and inc/cloudflare-purge.php):
 *
 *   SN_CF_ANALYTICS_TOKEN  — Account Analytics Read token (read-only, file-based
 *                             in wp-config.php). Obtain from Cloudflare dashboard →
 *                             My Profile → API Tokens → "Account Analytics Read".
 *   SN_CF_ACCOUNT_ID       — 32-char Cloudflare account ID. Found in the Cloudflare
 *                             dashboard URL: dash.cloudflare.com/<account_id>/.
 *
 * Both must be non-empty strings for a non-null return. No option fallback is
 * provided: this credential is read-only and belongs in wp-config, not the admin UI.
 *
 * @return array{account_id: string, token: string}|null
 */
function sn_analytics_config() {
	if ( ! defined( 'SN_CF_ANALYTICS_TOKEN' ) || ! defined( 'SN_CF_ACCOUNT_ID' ) ) {
		return null;
	}
	$token      = (string) SN_CF_ANALYTICS_TOKEN;
	$account_id = (string) SN_CF_ACCOUNT_ID;
	if ( '' === $token || '' === $account_id ) {
		return null;
	}
	return array(
		'account_id' => $account_id,
		'token'      => $token,
	);
}

/**
 * Record an Analytics Engine API failure.
 *
 * Stores url + HTTP status + 240-char body excerpt. Token is never written.
 * TTL mirrors the freshness window downstream callers will use so a successful
 * refresh naturally ages the diagnostic out.
 *
 * @param string     $url     The request URL (no credentials in AE SQL URLs).
 * @param int        $code    HTTP status code (0 for WP_Error / transport failure).
 * @param string     $message Body excerpt or WP_Error message.
 */
function sn_analytics_record_error( $url, $code, $message ) {
	set_transient( SN_ANALYTICS_ERR_KEY, array(
		'url'     => (string) $url,
		'code'    => (int) $code,
		'message' => (string) $message,
		'when'    => time(),
	), 5 * MINUTE_IN_SECONDS );
}

/**
 * Read the most recently recorded Analytics Engine API error.
 *
 * @return array{url: string, code: int, message: string, when: int}|null
 */
function sn_analytics_last_error() {
	$err = get_transient( SN_ANALYTICS_ERR_KEY );
	return is_array( $err ) ? $err : null;
}

/**
 * Run a SQL query against the Cloudflare Analytics Engine SQL API.
 *
 * The AE SQL API accepts the query as the raw POST body (not a JSON envelope).
 * The response shape is:
 *   { "meta": [...], "data": [ {row}, ... ], "rows": N }
 *
 * On any failure (transport error, non-200, JSON parse error) the failure
 * context is captured in the SN_ANALYTICS_ERR_KEY transient and null is
 * returned. On success the prior error transient is cleared.
 *
 * @param string $sql Raw SQL string accepted by the AE SQL API.
 * @return array|null Array of row objects (may be empty), or null on failure.
 */
function sn_analytics_query( $sql ) {
	$cfg = sn_analytics_config();
	if ( ! $cfg ) {
		return null;
	}

	$url = 'https://api.cloudflare.com/client/v4/accounts/' . $cfg['account_id'] . '/analytics_engine/sql';

	$response = wp_remote_post( $url, array(
		'headers'     => array( 'Authorization' => 'Bearer ' . $cfg['token'] ),
		'body'        => $sql,
		'timeout'     => 6,
		// Do not follow redirects — keeps the Bearer token on the validated host.
		// Mirrors inc/plausible-api.php + inc/webhooks.php + inc/uptime-heartbeat.php.
		'redirection' => 0,
	) );

	if ( is_wp_error( $response ) ) {
		sn_analytics_record_error( $url, 0, $response->get_error_message() );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( 200 !== (int) $code ) {
		sn_analytics_record_error( $url, (int) $code, substr( (string) $body, 0, 240 ) );
		return null;
	}

	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		// Unparseable JSON — record with code 200 so the caller knows the request
		// landed but the response shape was unexpected.
		sn_analytics_record_error( $url, 200, substr( (string) $body, 0, 240 ) );
		return null;
	}

	// Success — clear any stale error.
	delete_transient( SN_ANALYTICS_ERR_KEY );

	return $decoded['data'] ?? null;
}
