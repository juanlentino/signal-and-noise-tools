<?php
/**
 * Signal & Noise Tools — external link-rot health check (D1).
 *
 * A 7th check for the Content Health scan (inc/health-checks.php). The internal
 * broken-links check (sn_health_check_broken_links) deliberately DROPS off-host
 * links — so cited external sources rot unwatched. This check extracts the
 * off-host links and HEAD-probes them, flagging 4xx/5xx/network failures. A
 * bot-challenge interstitial (a 403/503 carrying `cf-mitigated: challenge`, e.g.
 * Cloudflare-gated academic hosts like SSRN) is treated as unverifiable rather
 * than rot — the page is live, the edge is gating automated clients. See
 * sn_health_is_bot_challenge().
 *
 * SSRF HARDENING. Unlike the internal probe (which trusts same-host links and
 * skips validation), every off-host URL is validated BEFORE the request:
 *   - wp_http_validate_url() — blocks loopback + RFC-1918 + bad schemes;
 *   - sn_ssrf_host_blocked() (inc/ssrf-guard.php) — resolves the host first, so
 *     it catches link-local / cloud-metadata (169.254/16, which
 *     wp_http_validate_url() omits) INCLUDING its encoded-IP forms, plus CGNAT +
 *     IPv6, failing closed on unresolvable. This is the shared guard webhooks
 *     uses too (extracted from this module in v6.13.1);
 *   - wp_safe_remote_* + redirection=0 — the host filter only validates the
 *     first hop, so following a redirect could reach a blocked host.
 * Probes are bounded (SN_HEALTH_EXTLINK_MAX_PROBES network calls per run) and
 * cached per-URL under a SEPARATE key prefix so an unguarded internal probe and
 * a guarded external probe can never collide on the same URL.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Cap on NETWORK probes per scan (cached URLs are free), AND a cumulative
// wall-clock budget — external hosts are slow + rate-sensitive, and a blocking
// DNS lookup + a 5s HEAD + a 5s GET fallback can stack. Both bounds protect the
// synchronous Content-Health scan from exceeding max_execution_time.
if ( ! defined( 'SN_HEALTH_EXTLINK_MAX_PROBES' ) ) {
	define( 'SN_HEALTH_EXTLINK_MAX_PROBES', 15 );
}
if ( ! defined( 'SN_HEALTH_EXTLINK_TIME_BUDGET' ) ) {
	define( 'SN_HEALTH_EXTLINK_TIME_BUDGET', 20 ); // seconds of cumulative probing
}

// The SSRF host-guard (resolve-then-range-check, blocking link-local +
// loopback + RFC-1918 + reserved + CGNAT + IPv6 + encoded-IP bypasses, failing
// closed on unresolvable) lives in inc/ssrf-guard.php as sn_ssrf_host_blocked()
// since v6.13.1 — extracted from this D1 implementation so webhooks + link-rot +
// any future outbound module share ONE audited guard. This module just calls it.

/**
 * Pull <a href> URLs that point OFF this host (the inverse of
 * sn_health_extract_internal_links). Keeps absolute http(s) links whose host
 * differs from $site_host; drops same-host, root-relative, mailto/tel/js/data,
 * and anchors. Returns a deduped array.
 *
 * @param string $content   Post content HTML.
 * @param string $site_host This site's host.
 * @return string[]
 */
function sn_health_extract_external_links( $content, $site_host ) {
	if ( '' === trim( (string) $content ) ) {
		return array();
	}
	$out = array();
	if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $content, $m ) ) {
		foreach ( $m[1] as $href ) {
			$href = trim( $href );
			if ( '' === $href || '#' === $href[0] || '/' === $href[0] ) {
				continue; // empty, anchor, or root-relative (internal by definition)
			}
			if ( preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $href ) ) {
				continue;
			}
			$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				continue; // only http(s) links are probeable
			}
			$h = wp_parse_url( $href, PHP_URL_HOST );
			if ( $h && strtolower( $h ) !== strtolower( $site_host ) ) {
				$out[ $href ] = true; // off-host
			}
		}
	}
	return array_keys( $out );
}

// The bot-challenge classifier (sn_health_is_bot_challenge) lives in the shared
// inc/health-probe-classify.php so the internal broken-links probe and this
// external link-rot probe agree on what a Cloudflare challenge looks like.

/**
 * SSRF-guarded, cached HEAD probe of an EXTERNAL URL.
 *
 * @param string $url
 * @return array{ok:bool,code:int,skipped?:bool,reason?:string,cached?:bool,probed?:bool}
 */
function sn_health_external_link_status( $url ) {
	// Full SSRF guard (see file docblock). URLs that fail it are unverifiable,
	// not "rotted" — return skipped so the check ignores them. Not cached
	// (cheap to recompute, and we never want a skip to mask a later fix).
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( '' === (string) $url
		|| ! wp_http_validate_url( $url )
		|| ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true )
		|| sn_ssrf_host_blocked( $host )
	) {
		return array( 'ok' => true, 'code' => 0, 'skipped' => true, 'cached' => false );
	}

	$cache_key = 'sn_extlink_' . md5( $url );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		$cached['cached'] = true;
		return $cached;
	}

	$args = array(
		'timeout'     => SN_HEALTH_LINK_TIMEOUT,
		'redirection' => 0, // first-hop only — a redirect could reach a blocked host.
		'sslverify'   => true,
		'headers'     => array( 'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' link-rot-check' ),
	);

	$resp = wp_safe_remote_head( $url, $args );
	if ( is_wp_error( $resp ) ) {
		$result = array( 'ok' => false, 'code' => 0 );
	} else {
		$final = $resp;
		$code  = (int) wp_remote_retrieve_response_code( $resp );
		// Some hosts reject HEAD (405/501) — retry with GET, still no redirects.
		if ( 405 === $code || 501 === $code ) {
			$resp2 = wp_safe_remote_get( $url, array( 'timeout' => SN_HEALTH_LINK_TIMEOUT, 'redirection' => 0, 'sslverify' => true ) );
			if ( is_wp_error( $resp2 ) ) {
				$final = null;
				$code  = 0;
			} else {
				$final = $resp2;
				$code  = (int) wp_remote_retrieve_response_code( $resp2 );
			}
		}
		$headers = $final ? wp_remote_retrieve_headers( $final ) : array();
		if ( sn_health_is_bot_challenge( $code, $headers ) ) {
			// A live page behind a Cloudflare (or equivalent) bot challenge: the
			// resource exists, the edge is gating automated clients. Treat as
			// unverifiable (like an SSRF skip) rather than rotted — flagging it
			// would be a false positive, since a human in a browser reaches it.
			$result = array( 'ok' => true, 'code' => $code, 'skipped' => true, 'reason' => 'bot_challenge' );
		} else {
			$result = array( 'ok' => ( $code >= 200 && $code < 400 ), 'code' => $code );
		}
	}

	// Cache the probe outcome (incl. a bot-challenge skip, so it costs one probe
	// per TTL, not one per scan). 'probed'/'cached' are per-call and set AFTER the
	// write so they never persist — a later cache hit must not look like a probe.
	set_transient( $cache_key, $result, SN_HEALTH_LINK_CACHE_TTL );
	$result['cached'] = false;
	$result['probed'] = true; // this call performed a live network request
	return $result;
}

/**
 * CHECK 7: rotted external (cited) links. Mirrors sn_health_check_broken_links
 * but for off-host URLs, with the SSRF guard + a per-run network-probe cap.
 *
 * @return array pack_check envelope.
 */
function sn_health_check_external_links() {
	global $wpdb;

	$label     = 'External link rot';
	$fix_hint  = 'Update or remove each rotted citation in the editor. Probe results cache for 24h; unverifiable (private/link-local) or bot-challenged (e.g. Cloudflare-gated) URLs are skipped, not flagged — a 403 challenge means the page is live but gating automated probes, not rotted.';
	$findings  = array();
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $site_host ) {
		return sn_health_pack_check( $label, $findings, $fix_hint );
	}

	$posts = $wpdb->get_results(
		"SELECT ID, post_title, post_content FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_content REGEXP '<a[[:space:]][^>]*href='
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $posts ) ) {
		return sn_health_pack_check( $label, $findings, $fix_hint );
	}

	// Dedupe external URL → posts-using-it.
	$url_to_posts = array();
	foreach ( $posts as $p ) {
		foreach ( sn_health_extract_external_links( (string) $p['post_content'], $site_host ) as $u ) {
			$url_to_posts[ $u ][] = array(
				'post_id'    => (int) $p['ID'],
				'post_title' => (string) $p['post_title'],
			);
		}
	}

	$network_probes = 0;
	$started        = microtime( true );
	foreach ( $url_to_posts as $url => $usages ) {
		// Bound the synchronous scan: skip NEW network probes once either the
		// per-run cap OR the cumulative wall-clock budget is hit. Already-cached
		// URLs stay free and remain reportable regardless.
		if ( false === get_transient( 'sn_extlink_' . md5( $url ) )
			&& ( $network_probes >= SN_HEALTH_EXTLINK_MAX_PROBES
				|| ( microtime( true ) - $started ) > SN_HEALTH_EXTLINK_TIME_BUDGET )
		) {
			continue;
		}
		$status = sn_health_external_link_status( $url );
		if ( ! empty( $status['probed'] ) ) {
			$network_probes++; // a live HEAD/GET ran (incl. a bot-challenge discovery)
		}
		if ( ! empty( $status['skipped'] ) || $status['ok'] ) {
			continue;
		}
		$findings[] = array(
			'subject_type'  => 'external_link',
			'subject_url'   => $url,
			'subject_label' => $url,
			'subject_id'    => 0,
			'edit_url'      => admin_url( 'post.php?post=' . $usages[0]['post_id'] . '&action=edit' ),
			'note'          => sprintf( 'HTTP %d on probe — cited in %d post(s). First use: %s', $status['code'], count( $usages ), $usages[0]['post_title'] ),
		);
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
