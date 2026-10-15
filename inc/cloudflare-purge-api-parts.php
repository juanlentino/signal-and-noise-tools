<?php
/**
 * Signal & Noise: Cloudflare cache purge, the API (the token and zone readers, the URL and zone purges, the blocking post, the verified zone purge).
 *
 * Split from inc/cloudflare-purge.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the active token. Constant wins over option when set.
 *
 * @return string Empty string if neither configured.
 */
function sn_cf_get_token() {
	if ( defined( 'SN_CLOUDFLARE_API_TOKEN' ) && SN_CLOUDFLARE_API_TOKEN ) {
		return (string) SN_CLOUDFLARE_API_TOKEN;
	}
	return (string) get_option( SN_CF_TOKEN_OPT, '' );
}

/**
 * Resolve the active zone ID. Constant wins over option when set.
 *
 * @return string Empty string if neither configured.
 */
function sn_cf_get_zone() {
	if ( defined( 'SN_CLOUDFLARE_ZONE_ID' ) && SN_CLOUDFLARE_ZONE_ID ) {
		return (string) SN_CLOUDFLARE_ZONE_ID;
	}
	return (string) get_option( SN_CF_ZONE_OPT, '' );
}

/**
 * True if both token and zone are configured.
 *
 * @return bool
 */
function sn_cf_is_configured() {
	return '' !== sn_cf_get_token() && '' !== sn_cf_get_zone();
}

/**
 * Purge a list of specific URLs from Cloudflare's edge cache.
 *
 * Since 20.9.0 each call blocks and its answer is read
 * (sn_cf_api_send): a transient failure is retried on a schedule and
 * one nothing can retry is recorded for the alert email. The return
 * value keeps its old meaning, handled, so callers are unchanged.
 *
 * @param string[] $urls Absolute URLs to purge. Filters out anything
 *                       that isn't a non-empty string.
 * @return bool true if the purge was handled (sent, or queued to retry),
 *              false if not configured or no valid URLs remain.
 */
function sn_cf_purge_urls( $urls ) {
	if ( ! sn_cf_is_configured() ) {
		return false;
	}
	$urls = array_values( array_unique( array_filter( (array) $urls, function( $u ) {
		return is_string( $u ) && '' !== $u;
	} ) ) );
	if ( empty( $urls ) ) {
		return false;
	}

	// Cloudflare takes up to 100 URLs per single-file purge call on every plan
	// (it was 30 when this was written).
	$chunks  = array_chunk( $urls, 100 );
	$handled   = true;
	$confirmed = true;
	foreach ( $chunks as $chunk ) {
		$confirmed = sn_cf_api_send( 'purge_cache', array( 'files' => $chunk ) ) && $confirmed;
		$handled   = $handled && 'failed' !== sn_cf_send_last();
	}
	if ( ! $handled ) {
		return false; // Cloudflare refused and nothing will retry: recorded for the alert.
	}
	if ( ! $confirmed ) {
		return true; // a retry is waiting: handled, but no purge to stamp yet.
	}

	update_option( SN_CF_LAST_PURGE_OPT, array(
		'time'  => time(),
		'kind'  => 'urls',
		'count' => count( $urls ),
	), false );

	return true;
}

/**
 * Refresh the entire zone. A code update (trigger update or rollover)
 * marks it stale; every other caller deletes it (sn_cf_edge_endpoint).
 *
 * @param string $trigger Ledger trigger for a call outside the theme's chain
 *                        ('' asks snt_purge_trigger()); 'probe' marks a stale
 *                        probe's fallback when the tag purge was refused.
 * @return bool true if handled (sent, or queued to retry), false if not configured.
 */
function sn_cf_purge_everything( $trigger = '' ) {
	if ( ! sn_cf_is_configured() ) {
		return false;
	}

	// 20.9.0: a code update marks the zone stale; anything else deletes.
	$confirmed = sn_cf_api_send( sn_cf_edge_endpoint( (string) ( $GLOBALS['snt_purge_current']['trigger'] ?? '' ) ), array( 'purge_everything' => true ) );
	if ( function_exists( 'snt_pulse_beat' ) ) {
		snt_pulse_beat( 'cache-purge', snt_pulse_purge_failed( $confirmed, sn_cf_send_last() ) ); // inc/openstation-pulse.php: a whole-zone purge beats; only a refusal is a failed beat.
	}
	if ( ! $confirmed && 'queued' !== sn_cf_send_last() ) {
		return false; // refused, nothing will retry: no purge to stamp, no edge leg to mark.
	}

	// Both last-purge options are stamped by sn_cf_api_send() on confirmation.
	// 20.7.0: the purge ledger. Inside the theme's chain this marks that
	// row's edge leg; called on its own (the admin-bar button, a first
	// publish, a scheduled transition, the probe) it is a row itself.
	// The edge leg is true only when Cloudflare confirmed it in this request;
	// a queued retry has not refreshed anything yet.
	if ( isset( $GLOBALS['snt_purge_current'] ) ) {
		$GLOBALS['snt_purge_edge'] = $confirmed;
		// The chain's row says when the zone was marked stale, not deleted.
		$GLOBALS['snt_purge_edge_call'] = sn_cf_send_endpoint();
	} elseif ( function_exists( 'snt_purge_ledger_add' ) ) {
		$row = array( 'trigger' => '' !== (string) $trigger ? (string) $trigger : snt_purge_trigger(), 'redis' => false, 'pages' => false, 'edge' => $confirmed, 'cloudways' => 'not run' );
		if ( '' !== (string) $trigger ) {
			$row['scope'] = 'zone';
		}
		snt_purge_ledger_add( $row );
	}

	return true;
}

/**
 * v8.7.0 (verified-purge Tier-1): fire a BLOCKING POST against the Cloudflare API
 * and read the real response. Since 20.9.0 every purge goes through it
 * (inc/cloudflare-purge-send.php adds the retry and the failure record).
 *
 * @param string $path CF API path (starting with /).
 * @param array  $body Request body.
 * @return array{http:int,cf_success:bool} HTTP code + whether CF's body said {success:true}.
 */
function sn_cf_api_post_blocking( $path, $body ) {
	$res  = wp_remote_post( SN_CF_API_BASE . $path, array(
		'headers'  => array(
			'Authorization' => 'Bearer ' . sn_cf_get_token(),
			'Content-Type'  => 'application/json',
		),
		'body'      => wp_json_encode( $body ),
		'timeout'   => 8,
		'blocking'  => true,
		'sslverify' => true,
		'redirection' => 0, // v8.7.1 (CMA INFO-1): never re-send the Bearer on a 3xx.
	) );
	$http = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$data = is_wp_error( $res ) ? array() : (array) json_decode( wp_remote_retrieve_body( $res ), true );
	return array(
		'http'       => $http,
		'cf_success' => ( 200 === $http ) && ! empty( $data['success'] ),
	);
}

/**
 * v8.7.0 (verified-purge Tier-1): full-zone purge with a blocking
 * accept-confirmation, for the verified manual "Purge All Caches" path.
 *
 * Returns the shape the theme's report writer stashes + records:
 *   accepted   — CF took the request at the HTTP layer (200).
 *   http       — the HTTP status (0 on transport error).
 *   cf_success — CF's body confirmed {success:true}.
 * Unconfigured ⇒ no HTTP call, all-false (fail-safe like sn_cf_purge_everything).
 *
 * @return array{accepted:bool,http:int,cf_success:bool}
 */
function sn_cf_purge_everything_verified() {
	if ( ! sn_cf_is_configured() ) {
		return array( 'accepted' => false, 'http' => 0, 'cf_success' => false );
	}

	$r   = sn_cf_api_post_blocking(
		'/zones/' . sn_cf_get_zone() . '/purge_cache',
		array( 'purge_everything' => true )
	);
	$out = array(
		'accepted'   => ( 200 === $r['http'] ),
		'http'       => $r['http'],
		'cf_success' => $r['cf_success'],
	);

	if ( ! empty( $out['cf_success'] ) ) {
		sn_cf_purge_failure_clear( array( 'purge_everything' => true ) ); // the whole zone supersedes any recorded failure.
	}
	if ( ! empty( $out['cf_success'] ) && isset( $GLOBALS['snt_purge_current'] ) ) {
		$GLOBALS['snt_purge_edge'] = true; // 20.7.0: the manual purge's ledger row (Codex).
	}

	update_option( SN_CF_LAST_PURGE_OPT, array(
		'time'       => time(),
		'kind'       => 'all',
		'verified'   => true,
		'cf_success' => $out['cf_success'],
	), false );
	update_option( SN_CF_LAST_ZONE_PURGE_OPT, time(), false );

	return $out;
}

