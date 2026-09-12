<?php
/**
 * Signal & Noise Tools -- Content Health check: cf security headers.
 *
 * Cloudflare security-header drift probe (v4.9.0 T1) -- verifies the 5 edge-delegated security headers are still being delivered.
 *
 * Split VERBATIM out of inc/health-checks.php in v9.81.0 (mirroring the
 * analytics-render-*.php split); every function name is unchanged. Loaded
 * by the inc/health-checks.php orchestrator, which owns the shared
 * constants and sn_health_pack_check().
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ─────────────────────────────────────────────────────────────────────
 * CHECK 6: Cloudflare security-header drift (v4.9.0, T1)
 *
 * The 5 delegated headers (CSP / HSTS / X-Content-Type-Options /
 * X-Frame-Options / Referrer-Policy) are emitted at the Cloudflare edge
 * via a Transform Rule / Managed Headers — NOT by WordPress. If the rule
 * is absent, dropped or misconfigured, the site silently loses its
 * security posture with no signal anywhere in wp-admin. This check fires ONE
 * HEAD request at home_url and asserts each header is present, surfacing
 * any absence as a finding.
 *
 * Probe result (the array of MISSING header names) caches for 6h in the
 * `sn_health_cf_headers_probe` transient. On a WP_Error probe we return a
 * probe-failed note WITHOUT caching, so the next scan re-attempts (the
 * edge being unreachable is a transient state, not a finding).
 *
 * Detection-only — NOT in $suggest_supported_checks (no AI-fix column;
 * the fix is a CF dashboard change, not a post mutation).
 *
 * @since 4.9.0
 * @return array { count, findings, label, fix_hint }
 */
function sn_health_check_cf_security_headers() {
	$label    = 'Cloudflare security headers';
	$fix_hint = 'These 5 headers are delivered at the Cloudflare edge (Transform Rule / Managed Headers), not by WordPress, and the abilities Basic-auth block is a WAF custom rule. A missing header or an open abilities route means that edge rule is not in force — dropped, disabled, or never created: verify it in the Cloudflare dashboard.';

	// Allow the whole check to be filtered off (e.g., non-Cloudflare hosting).
	if ( ! apply_filters( 'sn_health_cf_header_check_enabled', true ) ) {
		// Filtered off (non-Cloudflare hosting) is NOT a pass — nothing was
		// measured, and five missing edge headers would read identically.
		return sn_health_pack_check( $label, array(), $fix_hint, 'edge header check disabled by filter' );
	}

	$expected = array(
		'content-security-policy',
		'strict-transport-security',
		'x-content-type-options',
		'x-frame-options',
		'referrer-policy',
	);

	$cache_key = 'sn_health_cf_headers_probe';
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		// Cached array IS the list of missing header names.
		$missing = $cached;
	} else {
		$home = home_url( '/' );
		$resp = wp_remote_head( $home, array(
			'timeout'     => 5,
			'redirection' => 2,
			'sslverify'   => true,
			'headers'     => array(
				'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' header-drift-check',
			),
		) );

		if ( is_wp_error( $resp ) ) {
			// Edge unreachable — do NOT cache; self-heal on the next scan.
			return sn_health_pack_check(
				$label,
				array(),
				$fix_hint,
				'Header probe failed (' . $resp->get_error_message() . '): the edge was unreachable. The check will retry on the next scan.'
			);
		}

		// wp_remote_retrieve_headers() returns a WpOrg\Requests
		// CaseInsensitiveDictionary on live WP (its $data is PROTECTED, so a
		// (array) cast mangles the key to "\0*\0data" and never unwraps) and a
		// plain array under test. Use the class's public getAll() — which
		// returns already-lower-cased keys — with a Traversable/array fallback.
		$raw     = wp_remote_retrieve_headers( $resp );
		$present = array();
		$server  = ''; // server: header value, used for edge detection below.
		$collect = static function ( $name, $value ) use ( &$present, &$server ) {
			$lower             = strtolower( (string) $name );
			$present[ $lower ] = true;
			if ( 'server' === $lower ) {
				$server = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
			}
		};
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			foreach ( (array) $raw->getAll() as $name => $value ) {
				$collect( $name, $value );
			}
		} elseif ( $raw instanceof \Traversable || is_array( $raw ) ) {
			foreach ( $raw as $name => $value ) {
				$collect( $name, $value );
			}
		}

		// Edge detection: a CF-served response carries a cf-ray header and a
		// server: cloudflare. If the probe hit the origin directly (split-horizon
		// DNS, hosts pin, grey-cloud on Cloudways), none of that is present.
		$is_edge = isset( $present['cf-ray'] ) || ( '' !== $server && false !== stripos( $server, 'cloudflare' ) );

		$missing = array();
		foreach ( $expected as $header ) {
			if ( ! isset( $present[ $header ] ) ) {
				$missing[] = $header;
			}
		}

		// Edge-bypass guard: if NONE of the 5 expected headers are present AND
		// we can't confirm we hit the Cloudflare edge (no cf-ray / no
		// server: cloudflare), the probe most likely reached the origin
		// directly — flagging all 5 would be a false positive. Emit a single
		// advisory note with ZERO findings and do NOT cache the degenerate
		// result, so a later scan re-attempts.
		if ( count( $missing ) === count( $expected ) && ! $is_edge ) {
			return sn_health_pack_check(
				$label,
				array(),
				$fix_hint,
				'Could not confirm the Cloudflare edge headers from this host: the probe may have hit the origin directly; verify the edge config manually.'
			);
		}

		set_transient( $cache_key, $missing, SN_HEALTH_CF_HEADERS_TTL );
	}

	// The WAF probe rides the same check. It was written as a drift probe
	// ("is the edge rule still there?") for a rule the enforcement audit
	// (2026-09-11) recorded as the ONLY thing in front of the abilities run
	// route for an external caller. Its FIRST run, 2026-09-12, measured the
	// route open, and the owner confirmed the rule was never created: the
	// audit's premise came from memory, not from a measurement, and the
	// in-plugin guard has always been the sole control. The probe therefore
	// answers "is the edge rule in force?", which is the question that was
	// wanted all along. Own cache key, same TTL; an indeterminate probe is
	// never cached.
	$waf_key = 'sn_health_cf_waf_abilities_probe';
	$waf     = get_transient( $waf_key );
	if ( ! is_string( $waf ) || '' === $waf ) {
		$waf = sn_health_cf_waf_abilities_probe();
		if ( 'unknown' !== $waf ) {
			set_transient( $waf_key, $waf, SN_HEALTH_CF_HEADERS_TTL );
		}
	}

	$findings = array();
	$home_url = home_url( '/' );
	foreach ( $missing as $header ) {
		$findings[] = array(
			'subject_type'  => 'security_header',
			'subject_id'    => 0,
			'subject_url'   => $home_url,
			'subject_label' => $header,
			'edit_url'      => '',
			'note'          => 'Expected at the Cloudflare edge but absent: verify the CF Transform Rule / Managed Headers.',
		);
	}

	if ( 'open' === $waf ) {
		$findings[] = array(
			'subject_type'  => 'security_header',
			'subject_id'    => 0,
			'subject_url'   => home_url( '/wp-json/wp-abilities/v1/abilities' ),
			'subject_label' => 'waf: Block Basic-auth on abilities API',
			'edit_url'      => '',
			'note'          => 'The edge did not refuse an Authorization-bearing request to /wp-abilities/. The WAF custom rule "Block Basic-auth on abilities API" is not in force — absent, disabled, or never created. The in-plugin guard (sn_mcp_rw_guard_run_route) still holds; there is no edge layer behind it.',
		);
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}

/**
 * Probe the WAF custom rule "Block Basic-auth on abilities API": one GET to
 * the abilities catalogue carrying a throwaway Basic credential. The rule
 * answers 403 at the edge (cf-ray present) before the request ever reaches
 * WordPress. Anything else that is still an edge response — 401 from
 * WordPress rejecting the credential, 200, 404 — means the request went
 * THROUGH the edge unblocked: 'open'. A transport error, or a response with
 * no edge marker (the probe hit the origin directly, where the rule cannot
 * exist), is 'unknown' — nothing was measured, and the caller never caches
 * that.
 *
 * The credential is deliberately garbage: the point is that the header is
 * PRESENT, not that it authenticates. Mirrors the rule's own expression
 * (`any(http.request.headers.names[*] == "authorization")`).
 *
 * COVERAGE LIMIT: this probes the `/wp-json/` spelling only. WordPress also
 * serves the same API at `/?rest_route=/wp-abilities/v1/...`, which moves the
 * whole path into the QUERY STRING. A rule written on `http.request.uri.path`
 * blocks the form probed here and leaves that alias open — this probe would
 * read 'blocked' and be wrong. The rule must match on `http.request.uri`
 * (path + query) to cover both.
 *
 * @since 13.110.0
 * @return string 'blocked'|'open'|'unknown'
 */
function sn_health_cf_waf_abilities_probe() {
	$resp = wp_remote_get( home_url( '/wp-json/wp-abilities/v1/abilities' ), array(
		'timeout'     => 5,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array(
			'User-Agent'    => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' waf-drift-check',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a throwaway Basic credential; the header's presence is the probe.
			'Authorization' => 'Basic ' . base64_encode( 'sn-waf-probe:not-a-password' ),
		),
	) );
	if ( is_wp_error( $resp ) ) {
		return 'unknown';
	}

	$raw     = wp_remote_retrieve_headers( $resp );
	$is_edge = false;
	$collect = static function ( $name, $value ) use ( &$is_edge ) {
		$lower = strtolower( (string) $name );
		if ( 'cf-ray' === $lower ) {
			$is_edge = true;
		}
		if ( 'server' === $lower ) {
			$server = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
			if ( false !== stripos( $server, 'cloudflare' ) ) {
				$is_edge = true;
			}
		}
	};
	if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
		foreach ( (array) $raw->getAll() as $name => $value ) {
			$collect( $name, $value );
		}
	} elseif ( $raw instanceof \Traversable || is_array( $raw ) ) {
		foreach ( $raw as $name => $value ) {
			$collect( $name, $value );
		}
	}
	if ( ! $is_edge ) {
		return 'unknown';
	}

	$code = (int) wp_remote_retrieve_response_code( $resp );
	return 403 === $code ? 'blocked' : 'open';
}
