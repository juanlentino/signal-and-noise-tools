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
 * via CURLOPT_RESOLVE, which is NOT done here.
 *
 * VERIFIED 2026-08-31, because the obvious reading is wrong twice. The
 * `http_api_curl` action IS reachable on the live path — but NOT from
 * WP_Http_Curl, which developer.wordpress.org names as its source and which was
 * DEPRECATED in 6.4.0 and is no longer on the request path (WP_Http::request()
 * calls WpOrg\Requests\Requests::request() and fires only http_api_debug).
 * What keeps it alive is WP_HTTP_Requests_Hooks::dispatch(), which bridges
 * Requests' own `curl.before_send` — passing the live handle by reference —
 * straight to do_action_ref_array('http_api_curl', ...). Reasoning from the
 * documented source gives the wrong answer in BOTH directions.
 *
 * The caveat that makes pinning its own arc rather than a follow-up line: that
 * bridge fires only when Requests selects the cURL transport. On a host without
 * the cURL extension it falls back to fsockopen, the hook never fires, and the
 * request goes out UNPINNED with nothing saying so — a silent fail-open, which
 * is the shape this guard exists to avoid. Pinning therefore needs a
 * transport-detection decision (refuse, or accept and record) before it ships.
 *
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

/* ════════════════════════════════════════════════════════════════════════
 * STEP 2 — connect-time pinning (v13.53.0).
 *
 * THE DECISION THIS ENCODES, taken 2026-09-01. The backlog recorded the first
 * step as a choice, not code: when the request cannot be pinned, do we REFUSE
 * it or PROCEED and record the gap?
 *
 * PROCEED AND RECORD. The asymmetry against the read door's fail-closed
 * ceiling (v13.50.0) is deliberate, not an inconsistency:
 *   - That ceiling guards an INBOUND CREDENTIALED path. Refusing costs one
 *     caller a retry.
 *   - This guards OUTBOUND integrations. 16 files call this guard and 34 make
 *     requests; refusing when unpinnable would darken deploy probes, uptime,
 *     health checks, webhooks and citation verification on any host without
 *     the cURL extension — a self-inflicted outage, to prevent an attack that
 *     needs an attacker-controlled hostname against inputs that are largely
 *     owner-configured.
 * So this code is STRICTLY ADDITIVE: it pins where it can and makes the gap
 * VISIBLE where it cannot. It can never refuse a request that works today.
 *
 * WHY THE HOOK IS REACHABLE AT ALL — the obvious reading is wrong twice; see
 * this file's Step 1 docblock. `http_api_curl` is live via
 * WP_HTTP_Requests_Hooks::dispatch() bridging Requests' `curl.before_send`,
 * NOT via the deprecated WP_Http_Curl the docs name. But the bridge fires only
 * when Requests picks the cURL transport; on fsockopen it never fires, and
 * THAT is the gap this records rather than hides.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Can an outbound request be pinned on this host?
 *
 * Pinning needs BOTH: the cURL extension (so Requests selects the transport
 * whose hook we bridge) and CURLOPT_RESOLVE (so we can bind host->IP).
 *
 * @since 13.53.0
 * @return bool
 */
function sn_ssrf_pinning_available() {
	return function_exists( 'curl_init' ) && defined( 'CURLOPT_RESOLVE' );
}

/**
 * Build the CURLOPT_RESOLVE entries that bind a host to already-validated
 * addresses. PURE — no cURL handle, no network — so the mapping is testable
 * without a transport.
 *
 * Returns array() when there is nothing safe to pin, and array() must be read
 * as "do not pin", never as "pinned to nothing".
 *
 * @since 13.53.0
 * @param string   $host Host component.
 * @param int      $port Destination port.
 * @param string[] $ips  Addresses ALREADY validated by sn_ssrf_ip_blocked().
 * @return string[] CURLOPT_RESOLVE entries, e.g. "example.com:443:93.184.216.34".
 */
function sn_ssrf_resolve_entries( $host, $port, $ips ) {
	$host = (string) $host;
	$port = (int) $port;
	if ( '' === $host || $port <= 0 || ! is_array( $ips ) ) {
		return array();
	}
	$out = array();
	foreach ( $ips as $ip ) {
		$ip = (string) $ip;
		// Re-check at the pin site. The caller validated these, but a pin is a
		// LAST gate: binding a blocked address here would hand cURL the exact
		// destination the guard exists to refuse.
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) || sn_ssrf_ip_blocked( $ip ) ) {
			continue;
		}
		$out[] = $host . ':' . $port . ':' . $ip;
	}
	return array_values( array_unique( $out ) );
}

/**
 * The port a URL will actually connect on, scheme-derived when implicit.
 *
 * @since 13.53.0
 * @param string $url
 * @return int 0 when undeterminable.
 */
function sn_ssrf_url_port( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return 0;
	}
	if ( ! empty( $parts['port'] ) ) {
		return (int) $parts['port'];
	}
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	if ( 'https' === $scheme ) {
		return 443;
	}
	return 'http' === $scheme ? 80 : 0;
}

/**
 * Record that a request went out UNPINNED. Deliberately an option, not a log
 * line: a silent gap is the failure mode this whole guard exists to prevent,
 * and Site Health reads this to say so out loud.
 *
 * Stores the last occurrence only — this is a posture flag, not an audit trail,
 * and an unbounded list would be a write amplifier on every outbound call.
 *
 * @since 13.53.0
 * @param string $host
 * @return void
 */
function sn_ssrf_record_unpinned( $host ) {
	if ( ! function_exists( 'update_option' ) ) {
		return;
	}
	update_option(
		'sn_ssrf_unpinned_last',
		array( 'host' => (string) $host, 'at' => time() ),
		false
	);
}

/**
 * THE PIN ITSELF. Binds the host to the addresses the guard already validated,
 * so cURL connects to one of those and a rebinding answer arriving between
 * validation and connect cannot be followed.
 *
 * Fires on `http_api_curl` — reachable via WP_HTTP_Requests_Hooks::dispatch()
 * bridging Requests' `curl.before_send`, which passes the handle BY REFERENCE.
 * When Requests uses fsockopen this never runs, which is exactly the case
 * sn_ssrf_record_unpinned() exists to surface.
 *
 * Never refuses. A host we cannot pin is left to the pre-existing guard, which
 * has already blocked it if any resolved address was internal.
 *
 * @since 13.53.0
 * @param resource|\CurlHandle $handle cURL handle, by reference.
 * @param array                $args   Request args.
 * @param string               $url    Request URL.
 * @return void
 */
function sn_ssrf_pin_curl_handle( &$handle, $args = array(), $url = '' ) {
	if ( ! sn_ssrf_pinning_available() || ! $handle ) {
		return;
	}
	$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
	$port = sn_ssrf_url_port( $url );
	if ( '' === $host || 0 === $port ) {
		return;
	}
	// A literal-IP URL is already its own pin; nothing to bind.
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return;
	}
	$entries = sn_ssrf_resolve_entries( $host, $port, sn_ssrf_resolve_host_all( $host ) );
	if ( array() === $entries ) {
		// Resolution changed under us, or every address is now blocked. Do not
		// pin to nothing — record and let the request take its chances with the
		// guard that already ran. Refusing here would be a NEW failure mode on
		// a path that has none today.
		sn_ssrf_record_unpinned( $host );
		return;
	}
	curl_setopt( $handle, CURLOPT_RESOLVE, $entries );
}

/**
 * Register the pin, and record the gap when the transport cannot carry it.
 *
 * The `http_api_debug` companion is what makes the fsockopen case VISIBLE: it
 * fires on every transport, so a request that completed without the cURL hook
 * having run is exactly a request that went out unpinned.
 *
 * @since 13.53.0
 * @return void
 */
function sn_ssrf_register_pinning() {
	if ( ! function_exists( 'add_action' ) ) {
		return;
	}
	add_action( 'http_api_curl', 'sn_ssrf_pin_curl_handle', 10, 3 );
	if ( ! sn_ssrf_pinning_available() ) {
		// No cURL at all: every outbound request on this host is unpinned, and
		// saying so once at load beats discovering it per-request.
		sn_ssrf_record_unpinned( '(no cURL transport)' );
	}
}
if ( function_exists( 'add_action' ) ) {
	sn_ssrf_register_pinning();
}

/**
 * Site Health: is outbound pinning actually in force?
 *
 * PURE, so both branches are testable without a WP bootstrap. The gap is
 * reported as `recommended`, never `critical`: an unpinned request is the
 * status quo this release improves on, not a regression it introduces.
 *
 * @since 13.53.0
 * @param bool       $available sn_ssrf_pinning_available().
 * @param array|null $last      The recorded unpinned marker, or null.
 * @return array{status:string,summary:string}
 */
function sn_ssrf_pinning_health( $available, $last = null ) {
	if ( ! $available ) {
		return array(
			'status'  => 'recommended',
			'summary' => 'Outbound requests are NOT pinned to their validated addresses: this host has no cURL extension, so WordPress falls back to fsockopen and the pinning hook never fires. DNS rebinding between validation and connect is possible. Install the cURL extension to close it.',
		);
	}
	if ( is_array( $last ) && ! empty( $last['at'] ) ) {
		return array(
			'status'  => 'recommended',
			'summary' => sprintf(
				'Pinning is available, but at least one outbound request went unpinned (last: %s). That happens when a host stops resolving to any allowed address between validation and connect.',
				(string) ( $last['host'] ?? 'unknown' )
			),
		);
	}
	return array(
		'status'  => 'good',
		'summary' => 'Outbound requests are pinned to the addresses the SSRF guard validated, so a rebinding answer between validation and connect cannot be followed.',
	);
}

/**
 * Core Site Health registration.
 *
 * DELIBERATELY NOT on the plugin's own `health` surface. That tab answers one
 * question — "what is broken that I should fix" — and admits a check only if
 * its finding is a defect, can reach zero, and is unowned. On a host with cURL
 * and no drift this reads `good` permanently, so it would be a row that never
 * fires: noise on a curated tab. Core Site Health is where an environment
 * posture belongs, beside the cron pipeline check.
 *
 * @since 13.53.0
 * @param array $tests
 * @return array
 */
function sn_ssrf_register_site_health_test( $tests ) {
	$tests['direct']['sn_ssrf_pinning'] = array(
		'label' => __( 'Signal & Noise outbound request pinning', 'signal-and-noise-tools' ),
		'test'  => 'sn_ssrf_site_health_result',
	);
	return $tests;
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'site_status_tests', 'sn_ssrf_register_site_health_test' );
}

/**
 * The Site Health row. A DIRECT test (no REST round trip) because the verdict
 * is a local capability check plus one option read — there is nothing to poll.
 *
 * @since 13.53.0
 * @return array
 */
function sn_ssrf_site_health_result() {
	$last    = function_exists( 'get_option' ) ? get_option( 'sn_ssrf_unpinned_last', null ) : null;
	$verdict = sn_ssrf_pinning_health( sn_ssrf_pinning_available(), is_array( $last ) ? $last : null );

	return array(
		'label'       => __( 'Signal & Noise outbound request pinning', 'signal-and-noise-tools' ),
		'status'      => 'good' === $verdict['status'] ? 'good' : 'recommended',
		'badge'       => array(
			'label' => __( 'Security', 'signal-and-noise-tools' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html( $verdict['summary'] ) . '</p>',
		'test'        => 'sn_ssrf_pinning',
	);
}
