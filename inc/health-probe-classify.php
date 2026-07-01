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
 * Is a probe response a bot-challenge interstitial rather than a dead link?
 *
 * A live page gated behind a challenge (Cloudflare Managed Challenge / Turnstile)
 * answers an automated HEAD/GET with a 4xx/5xx + a JS interstitial — the resource
 * is NOT gone, the edge is gating non-browser clients. Flagging it as broken/rot
 * is a false positive: a human in a browser solves the challenge and reaches it.
 *
 * Detection keys on Cloudflare's purpose-built `cf-mitigated` header, which CF
 * emits ONLY on responses it generated itself, with the value `challenge` for an
 * interstitial. That disambiguates a CF-issued challenge from an origin 4xx merely
 * passed THROUGH Cloudflare — so this never masks a genuinely forbidden or removed
 * origin resource (those carry no cf-mitigated header). The status is constrained
 * to the challenge-bearing codes (403 managed/Turnstile, 503 legacy IUAM) so a real
 * 404/410 stays a dead link even if a stray header ever appeared.
 *
 * @param int   $code    HTTP status code from the probe.
 * @param mixed $headers Response header bag (array or WP CaseInsensitiveDictionary).
 * @return bool True when the response is a bot challenge (treat as unverifiable).
 */
function sn_health_is_bot_challenge( $code, $headers ) {
	if ( 403 !== (int) $code && 503 !== (int) $code ) {
		return false;
	}
	$mitigated = '';
	if ( is_array( $headers ) ) {
		foreach ( $headers as $name => $value ) {
			if ( 'cf-mitigated' === strtolower( (string) $name ) ) {
				$mitigated = is_array( $value ) ? implode( ',', $value ) : (string) $value;
				break;
			}
		}
	} elseif ( $headers instanceof ArrayAccess && isset( $headers['cf-mitigated'] ) ) {
		$value     = $headers['cf-mitigated']; // WP's CaseInsensitiveDictionary resolves the key case-insensitively.
		$mitigated = is_array( $value ) ? implode( ',', $value ) : (string) $value;
	}
	return false !== strpos( strtolower( trim( $mitigated ) ), 'challenge' );
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
