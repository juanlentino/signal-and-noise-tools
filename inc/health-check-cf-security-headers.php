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
 * The WAF custom rule "Block Basic-auth on abilities API" rides along, read
 * from a Better Stack witness monitor rather than probed from here — see
 * sn_health_cf_waf_abilities_probe() for why the origin cannot judge it. No
 * witness, or a witness that has not measured, makes the check report
 * itself as SKIPPED (a gap in evidence), never as a pass.
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
	$fix_hint = 'These 5 headers are delivered at the Cloudflare edge (Transform Rule / Managed Headers), not by WordPress, and the abilities Basic-auth block is a WAF custom rule. A missing header means that Transform Rule is not in force. The WAF rule is read from a Better Stack witness monitor that sends an Authorization header from outside the origin and expects 403; a witness reporting down means the rule is not in force. Verify either in the Cloudflare dashboard.';

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

	// The WAF witness rides the same check: the same question ("is the edge
	// rule in force?") about a rule the enforcement audit (2026-09-11)
	// recorded as the ONLY thing in front of the abilities run route for an
	// external caller. Own cache key, same TTL; an indeterminate read is
	// never cached, and it is surfaced as a SKIP (below), never as a pass.
	$waf_key = 'sn_health_cf_waf_abilities_probe';
	$waf     = get_transient( $waf_key );
	$waf_why = '';
	if ( ! is_string( $waf ) || '' === $waf ) {
		$probe   = sn_health_cf_waf_abilities_probe();
		$waf     = $probe['verdict'];
		$waf_why = $probe['why'];
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
			'note'          => 'The Better Stack witness monitor, probing from OUTSIDE the origin, reports that an Authorization-bearing request to /wp-abilities/ was not answered 403 at the edge: the WAF custom rule "Block Basic-auth on abilities API" is not in force. Check Security → WAF → Custom rules in the Cloudflare dashboard. The in-plugin guard (sn_mcp_rw_guard_run_route) holds this route either way.',
		);
	}

	// An unmeasured WAF rule is a gap in evidence, not a pass. The packer's
	// own ordering rule applies: header findings above outrank this skip.
	$skipped = null;
	if ( 'unknown' === $waf ) {
		$skipped = 'Edge headers measured. The WAF rule "Block Basic-auth on abilities API" was NOT measured this scan: ' . $waf_why;
	}

	return sn_health_pack_check( $label, $findings, $fix_hint, $skipped );
}


/**
 * Read the witness of the WAF custom rule "Block Basic-auth on abilities
 * API": a Better Stack HTTP monitor on the abilities URL that sends an
 * `Authorization` header from OUTSIDE the origin and expects the edge's 403.
 *
 * WHY NOT A REQUEST FROM HERE. Until 2026-09-12 this function sent its own
 * GET to home_url() with a throwaway Basic credential. That request leaves
 * the ORIGIN SERVER, and the zone's custom rules do not refuse it — measured
 * that day: the same request from an external host is answered 403 at the
 * edge (cf-ray, no server-timing) on BOTH the /wp-json/ and the ?rest_route=
 * spelling, while the origin's own reads "open" against the same rule. The
 * mechanism (an IP Access Rule or WAF exception covering the origin's
 * address is the leading candidate) is a dashboard fact, not a plugin one;
 * what matters here is that NO request this plugin sends can judge the rule.
 * A monitor that probes from Better Stack's network can.
 *
 * WHAT COUNTS AS A WITNESS. A monitor on this site's host whose URL contains
 * `wp-abilities` (the rule's own `http.request.uri contains` criterion), of
 * type `expected_status_code` expecting 403, carrying a request header named
 * `authorization`. The configuration is checked, not just the name: a plain
 * status monitor without the header reads `up` whether or not the rule
 * exists (WordPress answers 401 and the rule never engages), and a green
 * that cannot go red is not evidence. Two witnesses — one per URL spelling —
 * are read together; a rule narrowed to `http.request.uri.path` would leave
 * the `?rest_route=` witness down, which is the coverage this probe could
 * not see before.
 *
 * VERDICTS. Every witness `up` → 'blocked'. Any witness `down` → 'open',
 * unless the site's own monitor is also down (a timeout reads `down` too,
 * so a witness cannot speak for the rule while the site is unreachable).
 * Anything else — no token, no witness, a misconfigured monitor, a witness
 * that is pending/paused/validating, Better Stack unreachable — is
 * 'unknown' with a `why` the caller surfaces as a skip; never cached.
 *
 * One authenticated GET to Better Stack per read, cached 6h by the caller —
 * the same budget as the GET it replaces.
 *
 * @since 13.110.0
 * @since 14.0.4 Reads the Better Stack witness instead of probing from the origin.
 * @return array{verdict:string,why:string} verdict 'blocked'|'open'|'unknown'.
 */
function sn_health_cf_waf_abilities_probe() {
	$unknown = static function ( $why ) {
		return array( 'verdict' => 'unknown', 'why' => $why );
	};
	$witness_url = home_url( '/wp-json/wp-abilities/v1/abilities' );
	$how_to      = 'add a Better Stack HTTP monitor on ' . $witness_url . ' (and one on the ?rest_route=/wp-abilities/v1/abilities spelling) with a request header "Authorization: Basic x" and expected status code 403.';

	if ( ! function_exists( 'sn_uptime_status_configured' ) || ! function_exists( 'sn_uptime_status_api_get' ) ) {
		return $unknown( 'the Better Stack module is not loaded.' );
	}
	if ( ! sn_uptime_status_configured() ) {
		return $unknown( 'no Better Stack API token is configured (Uptime settings); ' . $how_to );
	}
	$monitors = sn_uptime_status_api_get( 'v2/monitors' );
	if ( is_wp_error( $monitors ) ) {
		return $unknown( 'Better Stack could not be read (' . $monitors->get_error_message() . ').' );
	}

	$home_host     = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$witnesses     = array(); // name => status
	$misconfigured = array();
	$site_down     = false;
	foreach ( (array) $monitors['data'] as $item ) {
		$attrs = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
		$url   = (string) ( $attrs['url'] ?? '' );
		if ( '' === $home_host || (string) wp_parse_url( $url, PHP_URL_HOST ) !== $home_host ) {
			continue;
		}
		$status = (string) ( $attrs['status'] ?? '' );
		$name   = (string) ( $attrs['pronounceable_name'] ?? $item['id'] ?? '' );
		if ( false === stripos( $url, 'wp-abilities' ) ) {
			if ( 'down' === $status ) {
				$site_down = true;
			}
			continue;
		}
		$has_auth = false;
		foreach ( (array) ( $attrs['request_headers'] ?? array() ) as $h ) {
			if ( is_array( $h ) && 'authorization' === strtolower( (string) ( $h['name'] ?? '' ) ) ) {
				$has_auth = true;
			}
		}
		$expects_403 = 'expected_status_code' === ( $attrs['monitor_type'] ?? '' )
			&& in_array( 403, array_map( 'intval', (array) ( $attrs['expected_status_codes'] ?? array() ) ), true );
		if ( ! $has_auth || ! $expects_403 ) {
			$misconfigured[] = $name;
			continue;
		}
		$witnesses[ $name ] = $status;
	}

	if ( empty( $witnesses ) ) {
		$why = 'no witness monitor exists; ' . $how_to;
		if ( $misconfigured ) {
			$why .= ' (' . implode( ', ', $misconfigured ) . ': on that URL but not configured as a witness — needs the Authorization header and expected status 403.)';
		}
		return $unknown( $why );
	}
	if ( $site_down ) {
		return $unknown( 'the site itself is down on Better Stack, so the witness reading is uninformative.' );
	}
	$pending = array();
	foreach ( $witnesses as $name => $status ) {
		if ( 'down' === $status ) {
			return array( 'verdict' => 'open', 'why' => '' );
		}
		if ( 'up' !== $status ) {
			$pending[] = $name . ' is ' . $status;
		}
	}
	if ( $pending ) {
		return $unknown( 'witness not reporting yet: ' . implode( '; ', $pending ) . '.' );
	}
	return array( 'verdict' => 'blocked', 'why' => '' );
}
