<?php
/**
 * Signal & Noise — the verified citation graph: the adjudicator.
 *
 * Fetches a claimed source and applies the ladder in citations-core.php. This is
 * the only module here that touches the network, and every request goes through
 * the shared SSRF host guard.
 *
 * REDIRECTS ARE FOLLOWED BY HAND. wp_safe_remote_get() validates redirect hops
 * with wp_http_validate_url(), which does NOT cover the link-local 169.254.0.0/16
 * range — the exact gap inc/ssrf-guard.php exists to close. So the transport is
 * pinned to `redirection => 0` and each hop is re-validated through the guard
 * before it is followed. A source that redirects more than SN_CIT_MAX_HOPS times
 * is left `unverified`: unread, not disproved.
 *
 * @package SignalNoiseTools
 * @since 11.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CIT_MAX_HOPS   = 3;
const SN_CIT_CRON_HOOK  = 'sn_citations_verify_batch';
const SN_CIT_MAX_BYTES  = 512000; // a citing page is prose; half a megabyte is generous.

/**
 * Fetch a URL with the guard applied on every hop.
 *
 * @param string $url
 * @return array{ok:bool,status:int,body:string,final_url:string}
 */
function sn_cit_fetch( $url ) {
	$out = array(
		'ok'        => false,
		'status'    => 0,
		'body'      => '',
		'final_url' => '',
	);
	$next = sn_cit_normalize_url( $url );

	for ( $hop = 0; $hop <= SN_CIT_MAX_HOPS; $hop++ ) {
		if ( '' === $next ) {
			return $out;
		}
		$host = wp_parse_url( $next, PHP_URL_HOST );
		if ( ! $host || sn_ssrf_host_blocked( $host ) ) {
			return $out; // fails closed: unresolvable or internal is not fetched.
		}
		$res = wp_safe_remote_get(
			$next,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'limit_response_size' => SN_CIT_MAX_BYTES,
				'user-agent'  => 'signal-and-noise-citation-verifier (+' . home_url( '/' ) . ')',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $out;
		}
		$code          = (int) wp_remote_retrieve_response_code( $res );
		$out['status'] = $code;

		if ( $code >= 300 && $code < 400 ) {
			$loc = wp_remote_retrieve_header( $res, 'location' );
			if ( '' === (string) $loc ) {
				return $out;
			}
			// Re-normalise, which also rejects a relative or non-http(s) Location.
			$next = sn_cit_normalize_url( $loc );
			continue;
		}
		if ( 200 !== $code ) {
			return $out; // a 404/500 is a failed read, not a removed link.
		}
		$out['ok']        = true;
		$out['body']      = (string) wp_remote_retrieve_body( $res );
		$out['final_url'] = $next;
		return $out;
	}
	return $out; // too many hops: unread.
}

/**
 * Best-effort <title>. Pure.
 *
 * @param string $html
 * @return string
 */
function sn_cit_extract_title( $html ) {
	if ( preg_match( '#<title\b[^>]*>(.*?)</title>#is', (string) $html, $m ) ) {
		$t = html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '#\s+#u', ' ', $t ) );
	}
	return '';
}

/**
 * Does the source ORIGIN publish a discoverable identity? The page's own markup
 * is checked first (free — it is already fetched); only then does this spend a
 * request on the origin's did.json. Deliberately the same mechanisms this site
 * publishes: the probe applies the standard the site itself meets.
 *
 * @param string $origin
 * @param string $html
 * @return bool
 */
function sn_cit_probe_identity( $origin, $html ) {
	if ( sn_cit_html_has_identity( $html ) ) {
		return true;
	}
	if ( '' === $origin ) {
		return false;
	}
	$did = sn_cit_fetch( $origin . '/.well-known/did.json' );
	if ( $did['ok'] && false !== stripos( $did['body'], 'did:web:' ) ) {
		return true;
	}
	return false;
}

/**
 * Adjudicate one row and persist the verdict.
 *
 * @param object $row
 * @return string The tier written.
 */
function sn_cit_verify_row( $row ) {
	$source = isset( $row->source_url ) ? (string) $row->source_url : '';
	$target = isset( $row->target_url ) ? (string) $row->target_url : '';
	$fetch  = sn_cit_fetch( $source );

	$link_present   = $fetch['ok'] && sn_cit_html_links_to( $fetch['body'], $target );
	$identity_found = false;
	// Only worth probing identity when the citation itself still stands; an
	// `asserted` row's identity does not change what the site may claim.
	if ( $link_present ) {
		$identity_found = sn_cit_probe_identity( sn_cit_origin( $source ), $fetch['body'] );
	}

	$tier = sn_cit_tier( $fetch['ok'], $link_present, $identity_found );
	sn_cit_update_verdict(
		isset( $row->id ) ? (int) $row->id : 0,
		$tier,
		(int) $fetch['status'],
		$fetch['ok'] ? sn_cit_extract_title( $fetch['body'] ) : ''
	);
	return $tier;
}

/**
 * Cron batch. Bounded so a burst of claims cannot turn one cron tick into a
 * crawl of the open web.
 *
 * @param int $limit
 * @return int Rows adjudicated.
 */
function sn_cit_verify_batch( $limit = 10 ) {
	$rows = sn_cit_due_for_check( (int) $limit );
	foreach ( $rows as $row ) {
		sn_cit_verify_row( $row );
	}
	return count( $rows );
}

function sn_cit_schedule_cron() {
	if ( ! wp_next_scheduled( SN_CIT_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', SN_CIT_CRON_HOOK );
	}
}

if ( ! defined( 'SN_CIT_TEST' ) || ! SN_CIT_TEST ) {
	add_action( 'init', 'sn_cit_maybe_install' );
	add_action( 'init', 'sn_cit_schedule_cron' );
	add_action( SN_CIT_CRON_HOOK, 'sn_cit_verify_batch' );
}
