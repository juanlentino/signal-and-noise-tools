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
 * Resolve a host to EVERY address it publishes.
 *
 * v13.51.0 — WHY THIS EXISTS ALONGSIDE sn_ssrf_resolve_host().
 *
 * gethostbyname() returns ONE address from a multi-address rrset, and which one
 * is not stable. Measured 2026-08-31: gethostbyname('dns.google') = 8.8.4.4
 * while gethostbynamel('dns.google') = 8.8.4.4, 8.8.8.8. The guard therefore
 * validated a single address out of a set it never enumerated, while cURL
 * resolved independently when the request went out — so a host publishing
 * [public, 169.254.169.254] could be validated against the public record and
 * fetched from the internal one. No attacker timing required; ordinary rrset
 * rotation suffices.
 *
 * THE SINGLE-ADDRESS SEAM IS DELIBERATELY PRESERVED, AND A FALLBACK IS NOT
 * ENOUGH. Eight test suites (webhooks, provenance-webhook, ssrf-url-validation,
 * health-external-links, analytics-salt-window, worker-version,
 * provenance-admin, provenance-genesis) define sn_ssrf_resolve_host() before
 * requiring this file and rely on its function_exists guard.
 *
 * The first cut of this function fell back to that seam only when the plural
 * lookup returned nothing — which fails for any stub whose host is REALLY
 * resolvable. provenance-genesis.php stubs raw.githubusercontent.com to
 * 10.0.0.9; gethostbynamel() resolves that host for real, so the stub was never
 * consulted, the suite went red, and it silently hit the network. Caught by the
 * sweep, 2026-08-31. sn_ssrf_host_blocked() therefore takes the UNION of both
 * lookups rather than preferring either — see there.
 *
 * STILL IPv4-ONLY. gethostbynamel() reads A records, not AAAA, so an IPv6-only
 * host resolves to nothing and fails closed. That is the safe direction but it
 * is a false negative, not a policy: add dns_get_record($host, DNS_AAAA) here if
 * a caller ever needs one.
 *
 * @since 13.51.0
 * @param string $host Host component of a URL.
 * @return string[] Every resolved address; empty array when unresolvable.
 */
if ( ! function_exists( 'sn_ssrf_resolve_host_all' ) ) {
	function sn_ssrf_resolve_host_all( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host ); // a literal address has no rrset to enumerate
		}
		$ips = gethostbynamel( (string) $host );
		if ( is_array( $ips ) && array() !== $ips ) {
			return array_values( $ips );
		}
		// Plural lookup found nothing — fall back to the single-address seam so
		// an overriding stub still governs. Real failure yields '' either way.
		$one = sn_ssrf_resolve_host( $host );
		return '' === $one ? array() : array( $one );
	}
}

/**
 * Is this single resolved IP in a range we must never reach?
 *
 * Extracted from sn_ssrf_host_blocked() in v13.51.0 so the range check can be
 * applied to every address in an rrset, and pinned directly by tests.
 *
 * @since 13.51.0
 * @param string $ip A resolved IP address.
 * @return bool True if the address must be blocked. Fails CLOSED on ''.
 */
if ( ! function_exists( 'sn_ssrf_ip_blocked' ) ) {
	function sn_ssrf_ip_blocked( $ip ) {
		if ( '' === (string) $ip ) {
			return true;
		}
		// filter_var returns the IP when it is PUBLIC, false otherwise. Blocks
		// 169.254/16 + 127/8 + 0/8 + 240/4 (reserved) and 10/8 + 172.16/12 +
		// 192.168/16 + fc00::/7 + fe80::/10 + ::1 (private).
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		// CGNAT 100.64.0.0/10 — NOT covered by PHP's reserved-range filter, so
		// this line is load-bearing rather than belt-and-braces. Verified
		// 2026-08-31: filter_var() passes 100.64.0.1 as public.
		if ( 1 === preg_match( '#^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.#', $ip ) ) {
			return true;
		}
		return false;
	}
}

/**
 * Is this host in a range we must never reach (SSRF)?
 *
 * Resolves the host to EVERY address it publishes (catching both the encoded-IP
 * bypasses above and the multi-address rrset gap), then blocks if ANY of them is
 * internal. FAILS CLOSED: an empty, unresolvable, or partially-internal host is
 * blocked.
 *
 * RESIDUAL RISK — DNS REBINDING. This is a check-then-fetch design: the guard
 * resolves here, and cURL resolves again when the request is issued. A
 * short-TTL record answering public-then-internal defeats any such design.
 * Enumerating the whole rrset closes the multi-address case completely; it does
 * NOT close rebinding. The complete answer is pinning host->IP at connect time
 * (CURLOPT_RESOLVE via the http_api_curl action), which is not done here.
 * Accepted for now because every caller takes its host from owner-controlled
 * config or options, not anonymous input.
 *
 * This also does NOT replace `redirection => 0` on the actual request, which is
 * what stops a validated host redirecting to an internal one — the host filter
 * only ever sees the first hop.
 *
 * @since 6.13.1
 * @param string $host Host component of a URL.
 * @return bool True if the host must be blocked.
 */
function sn_ssrf_host_blocked( $host ) {
	if ( '' === (string) $host ) {
		return true;
	}
	// UNION of both lookups, never one or the other. The plural lookup is the
	// production answer (every A record); the singular seam is what eight test
	// suites override, and some of their hosts resolve for real — so preferring
	// the plural silently bypasses those stubs. Union is also the strictly safer
	// composition: more addresses checked can only ever block more, never less.
	$ips = sn_ssrf_resolve_host_all( $host );
	$one = sn_ssrf_resolve_host( $host );
	if ( '' !== $one && ! in_array( $one, $ips, true ) ) {
		$ips[] = $one;
	}
	if ( array() === $ips ) {
		return true; // unresolvable -> fail closed
	}
	foreach ( $ips as $ip ) {
		if ( sn_ssrf_ip_blocked( $ip ) ) {
			return true; // ANY internal address in the rrset blocks the host
		}
	}
	return false;
}
