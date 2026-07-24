<?php
/**
 * Signal & Noise Tools -- Content Health check: broken links.
 *
 * Check 3: broken internal links -- internal links in post_content that 404 or return network errors (cached HEAD requests).
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
 * CHECK 3: broken internal links
 * Extract internal links (same-site origin OR root-relative) from
 * post_content of published posts. HEAD each (24h transient-cached).
 * Flag 4xx + 5xx + network failures.
 * ───────────────────────────────────────────────────────────────────── */
function sn_health_check_broken_links() {
	global $wpdb;

	$findings = array();
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $site_host ) { return sn_health_pack_check( 'Broken internal links', $findings ); }

	$posts = $wpdb->get_results(
		"SELECT ID, post_title, post_content FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_content REGEXP '<a[[:space:]][^>]*href='
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $posts ) ) { return sn_health_pack_check( 'Broken internal links', $findings ); }

	// Build a deduplicated URL → posts-using-it map first.
	$url_to_posts = array();
	foreach ( $posts as $p ) {
		$urls = sn_health_extract_internal_links( (string) $p['post_content'], $site_host );
		foreach ( $urls as $u ) {
			$url_to_posts[ $u ][] = array(
				'post_id'    => (int) $p['ID'],
				'post_title' => (string) $p['post_title'],
			);
		}
	}

	// Probe each unique URL.
	foreach ( $url_to_posts as $url => $usages ) {
		$status = sn_health_link_status( $url );
		if ( ! empty( $status['skipped'] ) || $status['ok'] ) {
			continue; // healthy, or a live page behind a bot challenge (not broken)
		}
		$findings[] = array(
			'subject_type'  => 'internal_link',
			'subject_url'   => $url,
			'subject_label' => $url,
			'subject_id'    => 0,
			'edit_url'      => $usages[0]['edit_url'] ?? admin_url( 'post.php?post=' . $usages[0]['post_id'] . '&action=edit' ),
			'note'          => sprintf( 'HTTP %d on probe — used in %d post(s). First use: %s', $status['code'], count( $usages ), $usages[0]['post_title'] ),
		);
	}

	return sn_health_pack_check( 'Broken internal links', $findings, 'Update or remove each link in the editor. Probe results cache for 24h.' );
}

/**
 * Pull <a href="..."> URLs out of $content that point at $site_host
 * or are root-relative. Anchors, mailto:, tel:, javascript: are
 * stripped. Returns a deduped array.
 */
function sn_health_extract_internal_links( $content, $site_host ) {
	if ( '' === trim( $content ) ) { return array(); }
	$out = array();
	if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $content, $m ) ) {
		foreach ( $m[1] as $href ) {
			$href = trim( $href );
			if ( '' === $href || '#' === $href[0] ) { continue; }
			if ( preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $href ) ) { continue; }

			if ( '/' === $href[0] && ( ! isset( $href[1] ) || '/' !== $href[1] ) ) {
				// Root-relative — internal by definition.
				$out[ home_url( $href ) ] = true;
				continue;
			}
			$h = wp_parse_url( $href, PHP_URL_HOST );
			if ( $h && strtolower( $h ) === strtolower( $site_host ) ) {
				$out[ $href ] = true;
			}
		}
	}
	return array_keys( $out );
}

/**
 * 24h-cached HEAD probe. Returns { ok: bool, code: int, skipped?: bool, reason?: string }.
 * Network errors are encoded as code = 0 + ok = false. A bot-challenge
 * interstitial (a 403/503 carrying `cf-mitigated: challenge`, e.g. a same-host
 * path behind Cloudflare) is a LIVE page gating bots, not a broken link, so it is
 * marked skipped — the same treatment the external link-rot probe gives a
 * challenged citation (see sn_health_is_bot_challenge() in health-probe-classify.php).
 */
function sn_health_link_status( $url ) {
	$cache_key = 'sn_health_link_' . md5( $url );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$resp = wp_remote_head( $url, array(
		'timeout'     => SN_HEALTH_LINK_TIMEOUT,
		// v4.14.2: do not follow redirects. The host filter validates only the
		// FIRST hop, so a same-host open redirect to 169.254.169.254 was followed
		// to the cloud-metadata service (LOW SSRF). 0 = the link's own status is
		// terminal — matches the v4.14.1 outbound-hardening peers.
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array( 'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' health-check' ),
	) );

	if ( is_wp_error( $resp ) ) {
		$result = array( 'ok' => false, 'code' => 0 );
	} else {
		$final  = $resp;
		$code   = (int) wp_remote_retrieve_response_code( $resp );
		// Some sites reject HEAD with 405; retry with GET in that case.
		if ( 405 === $code || 501 === $code ) {
			$resp2 = wp_remote_get( $url, array( 'timeout' => SN_HEALTH_LINK_TIMEOUT, 'redirection' => 0 ) );
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
			// A live page behind a Cloudflare bot challenge — gating automated
			// clients, not a dead link. Mark unverifiable (skipped) instead of
			// flagging it; mirrors the external link-rot probe.
			$result = array( 'ok' => true, 'code' => $code, 'skipped' => true, 'reason' => 'bot_challenge' );
		} elseif ( sn_health_is_edge_gated( $code, $headers ) ) {
			// A live internal page the Cloudflare edge is blocking/rate-limiting this
			// probe (403/429 + cf-ray, no cf-mitigated). juanlentino.com is fully
			// CF-fronted, so a bare-403 probe would false-flag live pages as broken.
			// Unverifiable, not broken; mirrors the external link-rot probe.
			$result = array( 'ok' => true, 'code' => $code, 'skipped' => true, 'reason' => 'edge_gated' );
		} elseif ( sn_health_is_nonstandard_status( $code ) ) {
			// A non-standard status (outside HTTP 100–599) — an anti-bot refusal like
			// LinkedIn's HTTP 999, not a real status and never "gone". Same shared
			// classifier the external link-rot probe uses; kept here so the two probes
			// agree. Unlikely on a same-host internal probe, but folded in for parity.
			$result = array( 'ok' => true, 'code' => $code, 'skipped' => true, 'reason' => 'nonstandard_status' );
		} else {
			$result = array( 'ok' => ( $code >= 200 && $code < 400 ), 'code' => $code );
		}
	}

	set_transient( $cache_key, $result, SN_HEALTH_LINK_CACHE_TTL );
	return $result;
}
