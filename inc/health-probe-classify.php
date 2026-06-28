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
