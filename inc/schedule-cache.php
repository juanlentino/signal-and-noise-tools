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

	// Pass through verbatim; sn_cf_purge_urls owns de-dupe + chunk-at-30.
	return (bool) sn_cf_purge_urls( $urls );
}
