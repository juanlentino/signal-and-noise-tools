<?php
/**
 * Signal & Noise Tools — shared outbound-request SSRF host-guard.
 *
 * Outbound modules (webhooks, the external link-rot health check, and any
 * future probe) accept a host from config / option / post content and must
 * NEVER let a request reach an internal address. The naive guard — a literal
 * `^169\.254\.` string match — is BYPASSABLE by alternate IPv4 encodings of the
 * cloud-metadata IP 169.254.169.254:
 *   - decimal  http://2852039166/
 *   - hex      http://0xA9.0xFE.0xA9.0xFE/   (and the flat http://0xA9FEA9FE/)
 *   - octal    http://0251.0376.0251.0376/   (and the flat http://025177524776/)
 * Each fails the literal string match — the parsed host is not "169.254.…" —
 * yet gethostbyname() resolves every one to 169.254.169.254, and
 * wp_http_validate_url() does NOT cover the 169.254.0.0/16 link-local range.
 *
 * The fix (lifted out of inc/health-external-links.php's D1 implementation,
 * v6.13.0, so webhooks + link-rot + future callers share ONE audited guard):
 * RESOLVE the host first — gethostbyname() collapses every encoded form to a
 * dotted-quad — THEN range-check the resolved IP with PHP's reserved/private
 * filter flags plus an explicit CGNAT (100.64.0.0/10) check the flags omit, and
 * FAIL CLOSED on an unresolvable host.
 *
 * Pure functions, no hooks, the only I/O is the resolver — safe to load anywhere
 * in the require chain and trivial to unit-test (tests/ssrf-guard.php). This is
 * a configuration/probe-time guard; it does NOT replace `redirection => 0` on
 * the actual request, which is what stops a validated host from redirecting to
 * an internal one (the host filter only ever sees the first hop).
 *
 * @package SignalNoiseTools
 * @since 6.13.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a host to an IP address for range-checking.
 *
 * gethostbyname() normalises NUMERIC-encoded hosts (decimal / hex / octal) —
 * the bypass a literal "169.254." string match misses — and resolves real
 * hostnames; a literal IP passes straight through. Returns '' when the host
 * cannot be resolved to a valid IP, so the caller fails closed.
 *
 * Guarded with function_exists() so tests can inject a deterministic resolver
 * seam (the hostname-lookup path needs DNS; the seam keeps integration tests
 * offline). sn_ssrf_host_blocked() is intentionally NOT guarded — it is the
 * logic under test and must not be stubbable.
 *
 * @since 6.13.1
 * @param string $host Host component of a URL.
 * @return string Dotted-quad / IPv6 address, or '' if unresolvable.
 */
if ( ! function_exists( 'sn_ssrf_resolve_host' ) ) {
	function sn_ssrf_resolve_host( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		$ip = gethostbyname( (string) $host );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}

/**
 * Is this host in a range we must never reach (SSRF)?
 *
 * Resolves the host first (catching the encoded-IP bypasses above), then
 * rejects link-local (169.254/16), loopback, RFC-1918, reserved (0/8, 240/4,
 * …), IPv6 private/reserved (via PHP's filter flags), and CGNAT (100.64/10,
 * which the filter flags omit). FAILS CLOSED: an empty or unresolvable host is
 * blocked.
 *
 * @since 6.13.1
 * @param string $host Host component of a URL.
 * @return bool True if the host must be blocked.
 */
function sn_ssrf_host_blocked( $host ) {
	if ( '' === (string) $host ) {
		return true;
	}
	$ip = sn_ssrf_resolve_host( $host );
	if ( '' === $ip ) {
		return true; // unresolvable → fail closed
	}
	// filter_var returns the IP when it is PUBLIC, false otherwise. Blocks
	// 169.254/16 + 127/8 + 0/8 + 240/4 (reserved) and 10/8 + 172.16/12 +
	// 192.168/16 + fc00::/7 + fe80::/10 + ::1 (private).
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return true;
	}
	// CGNAT 100.64.0.0/10 — not covered by PHP's reserved-range filter.
	if ( 1 === preg_match( '#^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.#', $ip ) ) {
		return true;
	}
	return false;
}
