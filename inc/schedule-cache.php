<?php
/**
 * Signal & Noise Tools: scheduled-content purge seam.
 *
 * Task 6 of the scheduled-content subsystem. One thin wrapper, sn_schedule_purge_urls,
 * over the plugin's existing Cloudflare purge-by-URL function (inc/cloudflare-purge.php).
 * The fire handler (inc/schedule-engine.php) calls THIS, never sn_cf_purge_urls
 * directly, so there is a single named seam to stub in tests and a single place
 * that decides "is purging even possible right now".
 *
 * REUSE, not rebuild: the actual Cloudflare API call, the de-dupe, and the
 * 30-URL chunking all live in sn_cf_purge_urls already. This file adds nothing
 * to that; it only guards the configured/empty cases and forwards the array
 * verbatim. Re-implementing the CF call here would fork a second, drift-prone
 * copy of the purge logic, which is exactly what the seam exists to avoid.
 *
 * NOT yet required from the plugin bootstrap; that wiring lands in the final task
 * of this subsystem, alongside the engine + sync modules.
 *
 * @package SignalNoiseTools
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge a list of URLs from Cloudflare's edge cache, via the plugin's existing
 * sn_cf_purge_urls (inc/cloudflare-purge.php). The single purge seam the
 * scheduled-content fire handler calls at each window boundary.
 *
 * Fire-and-forget semantics (inherited from sn_cf_purge_urls, which dispatches a
 * non-blocking wp_remote_post): a TRUE return means "the purge was DISPATCHED",
 * NOT "Cloudflare acknowledged it". The caller cannot learn synchronously whether
 * the edge actually dropped the URLs. A FALSE return is the only failure the
 * caller can act on synchronously, and it means exactly one of two things:
 *   - the site has no Cloudflare credentials (sn_cf_is_configured() is false), or
 *   - there was nothing valid to purge (empty $urls).
 * The fire handler treats FALSE as "could not purge -> hold the row for retry";
 * on a configured site with a non-empty list it always returns TRUE and the
 * boundary advances.
 *
 * The array is passed through UNTOUCHED: sn_cf_purge_urls already de-dupes,
 * filters non-string/empty entries, and chunks at 30 URLs per CF call, so this
 * wrapper must not pre-process the list (doing so would duplicate that logic and
 * risk drifting from it).
 *
 * @param array $urls Absolute URLs to purge.
 * @return bool True when the purge was dispatched; false when Cloudflare is
 *              unconfigured or there is nothing to purge.
 */
function sn_schedule_purge_urls( array $urls ) {
	// No Cloudflare integration available: nothing to dispatch. The
	// function_exists guards keep this safe even if cloudflare-purge.php is not
	// loaded (e.g. an isolated test or a partial bootstrap).
	if (
		! function_exists( 'sn_cf_purge_urls' )
		|| ! function_exists( 'sn_cf_is_configured' )
		|| ! sn_cf_is_configured()
	) {
		return false;
	}

	// Nothing to purge: a no-op, reported as false so the caller does not treat
	// an empty boundary as a successful purge.
	if ( empty( $urls ) ) {
		return false;
	}

	// v8.0.0: per-request coalescing. A version swap fires TWO rows at one
	// boundary with the same URL set; the second dispatch would be redundant
	// CF API work — the render gate is pure time, so one edge refetch after
	// the boundary sees every flip. The key is order-insensitive (sorted) and
	// only a SUCCESSFUL dispatch marks the memo, so a failed/unconfigured
	// attempt stays retryable.
	$sorted = array_map( 'strval', $urls );
	sort( $sorted );
	$key = md5( implode( "\n", $sorted ) );
	if ( sn_schedule_purge_memo_seen( $key ) ) {
		return true; // already dispatched this exact set in this request.
	}

	// Pass through verbatim; sn_cf_purge_urls owns de-dupe + chunk-at-30.
	$dispatched = (bool) sn_cf_purge_urls( $urls );
	if ( $dispatched ) {
		sn_schedule_purge_memo_seen( $key, true );
	}
	return $dispatched;
}

/**
 * Per-request purge memo backing the v8.0.0 coalescing above. One static
 * store, three modes: seen($key) queries, seen($key, true) marks, and
 * sn_schedule_purge_memo_reset() clears (tests + long-running CLI).
 *
 * @param string $key  Digest of the sorted URL set ('*zone*' for a zone purge).
 * @param bool   $mark When true, record the key as dispatched.
 * @return bool Whether the key was already recorded (query mode).
 */
function sn_schedule_purge_memo_seen( $key, $mark = false ) {
	static $seen = array();
	if ( '' === (string) $key ) {
		$seen = array(); // reset sentinel from sn_schedule_purge_memo_reset().
		return false;
	}
	if ( $mark ) {
		$seen[ (string) $key ] = true;
		return true;
	}
	return isset( $seen[ (string) $key ] );
}

/**
 * Clear the per-request purge memo. Tests pin purge counts across cases with
 * this; production requests never need it (the memo dies with the request).
 *
 * @return void
 */
function sn_schedule_purge_memo_reset() {
	sn_schedule_purge_memo_seen( '' );
}

/**
 * Purge for a fire-handler boundary transition (v7.3.0) — escalate or union.
 *
 * Two shipped gaps this closes:
 *  1. SLUG CHANGE: purge_urls is snapshotted at save (schedule-sync.php:129); a
 *     later slug change left the row purging only the stale URL. Fragments now
 *     purge the UNION of the snapshot and the host post's CURRENT permalink —
 *     self-healing at every boundary, and the old URL (possibly edge-cached as
 *     a redirect) still gets purged.
 *  2. REUSED CONTAINERS: sync has no post-type gate, so a scheduled block in a
 *     synced pattern (wp_block) or FSE template/part gets a row whose single
 *     snapshot URL under-purges by construction (the real render surfaces are
 *     unenumerable). Those hosts escalate to sn_cf_purge_everything() —
 *     boundaries are rare, a zone purge is cheap and provably complete.
 *
 * Same return contract as sn_schedule_purge_urls: TRUE = dispatched,
 * FALSE = unconfigured/nothing (the fire handler holds the row for retry).
 *
 * @param array $row A schedule row (target_type, target_ref, purge_urls).
 * @return bool
 */
function sn_schedule_fire_purge( array $row ) {
	$urls = (array) json_decode( (string) ( $row['purge_urls'] ?? '' ), true );

	if ( 'fragment' === (string) ( $row['target_type'] ?? '' ) ) {
		$host_id = (int) ( $row['target_ref'] ?? 0 );
		$type    = $host_id > 0 ? (string) get_post_type( $host_id ) : '';

		// Reused containers render on unenumerable URLs → zone purge.
		$escalate = apply_filters(
			'sn_schedule_escalate_post_types',
			array( 'wp_block', 'wp_template', 'wp_template_part' )
		);
		if ( '' !== $type && in_array( $type, (array) $escalate, true ) ) {
			if (
				! function_exists( 'sn_cf_purge_everything' )
				|| ! function_exists( 'sn_cf_is_configured' )
				|| ! sn_cf_is_configured()
			) {
				return false;
			}
			// v8.0.0: coalesce repeat zone purges within one request (a
			// reused-container version swap fires two rows at one boundary).
			// A zone purge covers every URL, so one per request is complete.
			if ( sn_schedule_purge_memo_seen( '*zone*' ) ) {
				return true;
			}
			$dispatched = (bool) sn_cf_purge_everything();
			if ( $dispatched ) {
				sn_schedule_purge_memo_seen( '*zone*', true );
			}
			return $dispatched;
		}

		// Slug-change self-heal: union with the CURRENT permalink.
		if ( $host_id > 0 ) {
			$current = (string) get_permalink( $host_id );
			if ( '' !== $current ) {
				$urls[] = $current;
			}
		}
	}

	return sn_schedule_purge_urls( array_values( array_unique( array_filter( $urls ) ) ) );
}
