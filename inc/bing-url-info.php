<?php
/**
 * Signal & Noise Tools: Bing's view of the URLs Google has not indexed.
 *
 * A URL Google has not indexed is either a Google-only gap (Bing crawled it,
 * so the page is reachable and readable) or a gap on both engines (Bing does
 * not know it either, which points at discovery: links, sitemap). One
 * GetUrlInfo per URL in the weekly coverage map's not-indexed set tells the two
 * apart. Pages excluded by noindex on purpose are not asked about.
 *
 * Bounded: at most SN_BING_URL_INFO_MAX calls per run, an answer younger than
 * the coverage freshness window is kept and not re-asked, and the call is
 * made only at the end of the weekly coverage run. GetUrlInfo is a read, so
 * the submission quota (GetUrlSubmissionQuota) does not apply and is not read.
 *
 * GetUrlInfo reports discovery and crawl dates, not an index verdict, so the
 * words are "Bing crawled it", never "Bing indexed it".
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_BING_URL_INFO_OPTION = 'sn_bing_url_info';
const SN_BING_URL_INFO_MAX    = 25;
const SN_BING_URL_INFO_FRESH  = 6 * DAY_IN_SECONDS; // the weekly run re-asks; a same-week re-run does not.

/**
 * The not-indexed set to ask about: join path => URL. PURE.
 *
 * @param array|null $coverage snt_gsc_coverage_data().
 * @return array<string,string>
 */
function sn_bing_url_info_targets( $coverage ) {
	$out = array();
	foreach ( (array) ( is_array( $coverage ) ? ( $coverage['entries'] ?? array() ) : array() ) as $path => $e ) {
		if ( ! is_array( $e ) || false !== ( $e['indexed'] ?? null ) || '' === (string) ( $e['url'] ?? '' ) ) {
			continue;
		}
		if ( 0 === stripos( (string) ( $e['coverage_state'] ?? '' ), 'excluded by' ) ) {
			continue; // noindex on purpose is not a gap.
		}
		$out[ (string) $path ] = (string) $e['url'];
		if ( count( $out ) >= SN_BING_URL_INFO_MAX ) {
			break;
		}
	}
	return $out;
}

/** A Bing date, or '' for none and for Bing's year-1 "no date" sentinel. PURE. */
function sn_bing_url_info_date( $raw ) {
	$d = sn_bing_parse_date( $raw );
	return ( '' === $d || $d < '2000-01-01' ) ? '' : $d;
}

/**
 * One sn_bing_request() result to a stored answer. PURE.
 *
 * @param array $res {ok, code, body, error}.
 * @param int   $now
 * @return array{state:string,crawled:string,discovered:string,error:string,checked_at:int}
 */
function sn_bing_url_info_shape( $res, $now ) {
	$out = array( 'state' => 'error', 'crawled' => '', 'discovered' => '', 'error' => '', 'checked_at' => (int) $now );
	if ( empty( $res['ok'] ) || ! is_array( $res['body'] ?? null ) ) {
		$out['error'] = '' !== (string) ( $res['error'] ?? '' ) ? (string) $res['error'] : 'no answer';
		return $out;
	}
	$out['crawled']    = sn_bing_url_info_date( $res['body']['LastCrawledDate'] ?? '' );
	$out['discovered'] = sn_bing_url_info_date( $res['body']['DiscoveryDate'] ?? '' );
	$out['state']      = '' !== $out['crawled'] ? 'crawled' : ( '' !== $out['discovered'] ? 'discovered' : 'unknown' );
	return $out;
}

/** Plain words for one answer; null when Bing was never asked. PURE. */
function sn_bing_url_info_label( $a ) {
	if ( ! is_array( $a ) ) {
		return null;
	}
	switch ( (string) ( $a['state'] ?? '' ) ) {
		case 'crawled':
			/* translators: %s: date. */
			return sprintf( __( 'Bing crawled %s', 'signal-and-noise-tools' ), $a['crawled'] );
		case 'discovered':
			/* translators: %s: date. */
			return sprintf( __( 'Bing found it %s, not crawled yet', 'signal-and-noise-tools' ), $a['discovered'] );
		case 'unknown':
			return __( 'not known to Bing', 'signal-and-noise-tools' );
	}
	/* translators: %s: Bing's error. */
	return sprintf( __( 'Bing not read (%s)', 'signal-and-noise-tools' ), (string) ( $a['error'] ?? '' ) );
}

/** The stored answers by join path ([] before the first run). */
function sn_bing_url_info_data() {
	$d = function_exists( 'get_option' ) ? get_option( SN_BING_URL_INFO_OPTION, array() ) : array();
	return is_array( $d ) ? $d : array();
}

/**
 * Ask Bing about the not-indexed set and store the answers. Paths that left
 * the set are dropped; a fresh non-error answer is kept without a call.
 *
 * @param array|null $coverage snt_gsc_coverage_data().
 * @param int|null   $now
 * @return int Calls made.
 */
function sn_bing_url_info_sync( $coverage, $now = null ) {
	if ( ! function_exists( 'sn_bing_is_ready' ) || ! sn_bing_is_ready() ) {
		return 0;
	}
	$now   = null === $now ? time() : (int) $now;
	$prev  = sn_bing_url_info_data();
	$next  = array();
	$calls = 0;
	foreach ( sn_bing_url_info_targets( $coverage ) as $path => $url ) {
		$p = $prev[ $path ] ?? null;
		if ( is_array( $p ) && 'error' !== ( $p['state'] ?? '' ) && ( $now - (int) ( $p['checked_at'] ?? 0 ) ) < SN_BING_URL_INFO_FRESH ) {
			$next[ $path ] = $p;
			continue;
		}
		$next[ $path ] = sn_bing_url_info_shape( sn_bing_request( 'GetUrlInfo', array( 'siteUrl' => sn_bing_site_url(), 'url' => $url ) ), $now );
		$calls++;
	}
	update_option( SN_BING_URL_INFO_OPTION, $next, false );
	return $calls;
}
