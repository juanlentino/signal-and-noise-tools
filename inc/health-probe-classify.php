<?php
/**
 * Signal & Noise Tools — shared Health-probe response classifier.
 *
 * Both Health checks that HEAD/GET-probe URLs — the internal broken-links check
 * (inc/health-checks.php) and the external link-rot check
 * (inc/health-external-links.php) — must tell a genuinely dead/forbidden
 * resource apart from a LIVE page sitting behind a bot challenge (Cloudflare
 * Managed Challenge / Turnstile), which answers an automated probe with a
 * 4xx/5xx interstitial. Treating that as broken/rot is a false positive. This
 * single classifier is the shared seam so the two checks agree and neither
 * grows its own drifting copy. It depends on nothing else and is loaded before
 * inc/health-checks.php so both probes can call it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revision of the classification RULES below. Both probes cache their verdict
 * per URL for 24h, so widening a skip bucket does NOT retroactively clear the
 * verdicts it would now classify differently — a URL judged "rot" under the old
 * rules stays flagged for the rest of its TTL, and "Re-run scan" returns the
 * stale verdict instead of re-probing. Namespacing the cache key with this
 * revision makes a rules change self-invalidating: bump it in the same commit
 * that changes a classifier and every prior verdict is bypassed immediately.
 * Orphaned entries under the old revision expire on their own within the TTL.
 *
 * 1 — original (Cloudflare challenge / CF edge-gate / non-standard status).
 * 2 — challenge detection generalized to any vendor `*-mitigated: challenge`
 *     header (adds Vercel) and the status allowlist widened to 403/429/503.
 */
if ( ! defined( 'SN_HEALTH_PROBE_CLASSIFY_REV' ) ) {
	define( 'SN_HEALTH_PROBE_CLASSIFY_REV', 2 );
}

/**
 * Probe-cache transient key for a URL, namespaced by classifier revision.
 *
 * @param string $prefix Per-probe key prefix ('sn_extlink_' | 'sn_health_link_').
 * @param string $url    URL being probed.
 * @return string Transient key (well under WP's 172-char limit).
 */
function sn_health_probe_cache_key( $prefix, $url ) {
	return $prefix . md5( (string) $url ) . '_c' . SN_HEALTH_PROBE_CLASSIFY_REV;
}

/**
 * Response headers by which a CDN declares "I served a bot challenge here."
 *
 * Both Cloudflare and Vercel converged on the same convention — a purpose-built
 * `*-mitigated` header carrying the action the edge took — so one lookup table
 * covers both, and a third vendor adopting it is a one-line addition:
 *
 *   - `cf-mitigated: challenge`         Cloudflare Managed Challenge / Turnstile.
 *   - `x-vercel-mitigated: challenge`   Vercel Security Checkpoint (Attack Mode /
 *                                       a WAF `challenge` rule).
 *
 * What makes these headers safe to trust is that the edge emits them ONLY on
 * responses IT generated — never on an origin 4xx merely passed through — and it
 * names the action, so a `deny` is distinguishable from a `challenge`.
 *
 * @return string[] Lowercase header names, in match order.
 */
function sn_health_challenge_headers() {
	return array( 'cf-mitigated', 'x-vercel-mitigated' );
}

/**
 * Is a probe response a bot-challenge interstitial rather than a dead link?
 *
 * A live page gated behind a challenge answers an automated HEAD/GET with a
 * 4xx/5xx + a JS interstitial — the resource is NOT gone, the edge is gating
 * non-browser clients. Flagging it as broken/rot is a false positive: a human in
 * a browser solves the challenge and reaches it.
 *
 * Detection keys on the vendor's purpose-built mitigation header (see
 * sn_health_challenge_headers()) carrying the value `challenge`. That
 * disambiguates an edge-ISSUED challenge from an origin 4xx merely passed THROUGH
 * the CDN — so this never masks a genuinely forbidden or removed origin resource
 * (those carry no mitigation header), and never swallows a `deny`.
 *
 * The status allowlist exists for exactly one reason: keep a genuinely GONE
 * resource rotting even if a stray/spoofed header ever appeared. "Gone" in HTTP
 * is 404/410 and nothing else (RFC 9110 §15.5), so the allowlist is the set of
 * access-restricted / try-again codes these vendors actually serve challenges
 * with — 403 (CF managed/Turnstile), 429 (Vercel checkpoint), 503 (CF legacy
 * IUAM). None of the three can mean "removed", so admitting all three for either
 * vendor costs nothing in safety.
 *
 * @param int   $code    HTTP status code from the probe.
 * @param mixed $headers Response header bag (array or WP CaseInsensitiveDictionary).
 * @return bool True when the response is a bot challenge (treat as unverifiable).
 */
function sn_health_is_bot_challenge( $code, $headers ) {
	$code = (int) $code;
	if ( 403 !== $code && 429 !== $code && 503 !== $code ) {
		return false;
	}
	foreach ( sn_health_challenge_headers() as $header ) {
		// sn_health_probe_header() resolves either bag shape (plain array or WP's
		// ArrayAccess CaseInsensitiveDictionary) case-insensitively.
		$mitigated = sn_health_probe_header( $headers, $header );
		if ( false !== strpos( strtolower( trim( $mitigated ) ), 'challenge' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Read a single header value from either a plain array or WP's ArrayAccess
 * CaseInsensitiveDictionary (what wp_remote_retrieve_headers() returns live),
 * case-insensitively. Returns '' when the header is absent.
 *
 * @param mixed  $headers Response header bag (array or CaseInsensitiveDictionary).
 * @param string $name    Header name (any case).
 * @return string The value (comma-joined if multi-valued), or ''.
 */
function sn_health_probe_header( $headers, $name ) {
	$name = strtolower( (string) $name );
	if ( is_array( $headers ) ) {
		foreach ( $headers as $k => $v ) {
			if ( strtolower( (string) $k ) === $name ) {
				return is_array( $v ) ? implode( ',', $v ) : (string) $v;
			}
		}
		return '';
	}
	if ( $headers instanceof ArrayAccess && isset( $headers[ $name ] ) ) {
		$v = $headers[ $name ];
		return is_array( $v ) ? implode( ',', $v ) : (string) $v;
	}
	return '';
}

/**
 * Was a probe response served through the Cloudflare edge? CF stamps a `cf-ray`
 * on every response it fronts and a `server: cloudflare`, so either is a reliable
 * fingerprint (present on challenges, blocks, AND clean pass-throughs alike).
 *
 * @param mixed $headers Response header bag.
 * @return bool True when the response came through Cloudflare.
 */
function sn_health_probe_is_cloudflare( $headers ) {
	return '' !== trim( sn_health_probe_header( $headers, 'cf-ray' ) )
		|| false !== stripos( sn_health_probe_header( $headers, 'server' ), 'cloudflare' );
}

/**
 * Is a probe response a Cloudflare EDGE GATE (block / rate-limit) rather than a
 * dead link? This is DISTINCT from a challenge (sn_health_is_bot_challenge): a WAF
 * or Super-Bot-Fight-Mode "block" action, or a rate-limit, answers an automated
 * client with a 403/429 that carries NO `cf-mitigated` header — so the challenge
 * classifier misses it, yet the resource is LIVE and a human in a browser reaches
 * it. Flagging it as rot is a false positive.
 *
 * Grounding: Cloudflare's `cf-mitigated: challenge` header is emitted ONLY on
 * Challenge Pages (developers.cloudflare.com/cloudflare-challenges/.../detect-response);
 * a "block" is a separate enforcement with no such header, and every CF-served
 * response carries a `cf-ray`. HTTP semantics (RFC 9110): 403 = access forbidden
 * and 429 = rate-limited — NEITHER means "gone" (that is 404 / 410). So this is
 * constrained to 403/429 (a CF-fronted 404/410 still rots) AND requires a
 * Cloudflare fingerprint — a plain non-CF 403 is deliberately left as rot, keeping
 * the guard against blanket-ignoring every 403.
 *
 * @param int   $code    HTTP status code from the probe.
 * @param mixed $headers Response header bag.
 * @return bool True when a Cloudflare edge is gating an automated client.
 */
function sn_health_is_edge_gated( $code, $headers ) {
	$code = (int) $code;
	if ( 403 !== $code && 429 !== $code ) {
		return false;
	}
	return sn_health_probe_is_cloudflare( $headers );
}

/**
 * Is a probe response a NON-STANDARD status code — outside the valid HTTP range —
 * rather than a dead link? Standard HTTP status codes occupy 100–599 (RFC 9110
 * §15: the first digit is the class, 1–5). A code >= 600 is not a valid HTTP
 * status at all, so it cannot carry "the resource is gone" semantics — that is a
 * well-defined 404/410. In practice a >= 600 code is emitted by an anti-bot /
 * anti-scraping layer refusing an automated client. The canonical case is
 * LinkedIn's HTTP `999 Request denied`, returned to any non-browser probe of a
 * profile that is fully LIVE for a human — so flagging it as rot is a false
 * positive, exactly like a Cloudflare challenge or block.
 *
 * This classifier is DISTINCT from the two above: it is host-agnostic (LinkedIn is
 * NOT behind Cloudflare, so the cf-mitigated / cf-ray fingerprints never fire) and
 * keys purely on the code being non-standard, needing no header at all. The two
 * Health probes encode a network error as code 0, which stays BELOW 600, so a
 * genuine request failure is never swept into this skip bucket. A real dead link
 * always answers with a standard 404/410/5xx (<= 599), so this never masks rot.
 *
 * @param int $code HTTP status code from the probe.
 * @return bool True when the code is outside the valid HTTP range (treat as unverifiable).
 */
function sn_health_is_nonstandard_status( $code ) {
	return (int) $code >= 600;
}
