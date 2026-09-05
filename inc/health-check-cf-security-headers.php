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
 * is dropped or misconfigured, the site silently loses its security
 * posture with no signal anywhere in wp-admin. This check fires ONE
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
	$fix_hint = 'These 5 headers are delivered at the Cloudflare edge (Transform Rule / Managed Headers), not by WordPress. A missing header means the edge rule was dropped or misconfigured: verify it in the Cloudflare dashboard.';

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

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
