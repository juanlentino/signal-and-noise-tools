<?php
/**
 * Signal & Noise Tools: coded résumé links (owner request, 2026-10-31).
 *
 * Each person the owner contacts gets their own link, /r/CODE (2 to 40
 * characters: lowercase letters, digits, hyphens; no registry, any code
 * works). It serves the résumé page itself, 200 with no redirect, so the code
 * survives email apps and link wrappers that drop the referrer. The page's own
 * canonical (/resume/) stands; the coded copy is noindex in the meta and in an
 * X-Robots-Tag header (the edge's Markdown twin copies only the header).
 * Postless, so it never reaches the sitemap or the llms files.
 *
 * Nothing new is stored. The beacon already records location.pathname, so
 * the code is read back out of the stored path (sn_link_code_from_path()),
 * and the rest of that visitor-day comes from the same visitor-day hash the
 * analytics already keep. No cookie, no browser storage, no identifier.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LINK_CODE_PATTERN = '~^/r/([a-z0-9-]{2,40})/?$~';

/**
 * The code in a site path, or ''. PURE. The stored path is the raw
 * location.pathname, so both /r/hera and /r/hera/ are one code.
 *
 * @param mixed $path A path, no query string.
 * @return string
 */
function sn_link_code_from_path( $path ) {
	return preg_match( SN_LINK_CODE_PATTERN, (string) $path, $m ) ? $m[1] : '';
}

/**
 * The code this request asks for, or ''.
 *
 * @param string|null $uri A request URI; null reads the current request's.
 * @return string
 */
function sn_link_code_requested( $uri = null ) {
	$uri = null === $uri ? ( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ) : (string) $uri; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against a strict pattern, never echoed.
	return sn_link_code_from_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
}

/**
 * request: a code asks WordPress for the résumé page itself. A bad code, or
 * no published résumé, leaves the request alone: the normal 404.
 *
 * @param array       $vars Query vars.
 * @param string|null $uri  Request URI (tests).
 * @return array
 */
function sn_link_code_request( $vars, $uri = null ) {
	if ( '' === sn_link_code_requested( $uri ) ) {
		return $vars;
	}
	$page = function_exists( 'sn_jsonresume_page' ) ? sn_jsonresume_page( 'resume' ) : null;
	return $page ? array( 'page_id' => (int) $page->ID ) : $vars;
}
add_filter( 'request', 'sn_link_code_request' );

/**
 * redirect_canonical: core would send /r/hera to /resume/ and lose the code.
 *
 * @param string|false $redirect The redirect core computed.
 * @param string|null  $uri      Request URI (tests).
 * @return string|false
 */
function sn_link_code_no_redirect( $redirect, $uri = null ) {
	return '' === sn_link_code_requested( $uri ) ? $redirect : false;
}
add_filter( 'redirect_canonical', 'sn_link_code_no_redirect' );

/**
 * sn_seo_robots_directives: the coded copy is noindex; the résumé is not.
 *
 * @param array       $directives Robots directives.
 * @param string|null $uri        Request URI (tests).
 * @return array
 */
function sn_link_code_robots( $directives, $uri = null ) {
	return '' === sn_link_code_requested( $uri ) ? $directives : array_merge( (array) $directives, array( 'noindex' ) );
}
add_filter( 'sn_seo_robots_directives', 'sn_link_code_robots' );

/**
 * wp_headers: the same noindex as a header, which the edge's Markdown twin
 * of the page keeps (it copies x-robots-tag, never the meta tag).
 *
 * @param array       $headers Response headers.
 * @param string|null $uri     Request URI (tests).
 * @return array
 */
function sn_link_code_headers( $headers, $uri = null ) {
	if ( '' !== sn_link_code_requested( is_string( $uri ) ? $uri : null ) ) {
		$headers['X-Robots-Tag'] = 'noindex';
	}
	return $headers;
}
add_filter( 'wp_headers', 'sn_link_code_headers' );

/**
 * The admin Live list's coded rows, from the same rows as "being read now":
 * one per code, both spellings summed, most readers first. Admin only; the
 * public list never names a code. PURE given the résumé page.
 *
 * @param array $rows AE rows { path, readers }.
 * @return array<int,array{label:string,url:string,readers:int}>
 */
function sn_link_codes_live_from_rows( array $rows ) {
	$by = array();
	foreach ( $rows as $row ) {
		$code = is_array( $row ) ? sn_link_code_from_path( $row['path'] ?? '' ) : '';
		if ( '' !== $code ) {
			$by[ $code ] = ( $by[ $code ] ?? 0 ) + max( 0, (int) ( $row['readers'] ?? 0 ) );
		}
	}
	$page  = function_exists( 'sn_jsonresume_page' ) ? sn_jsonresume_page( 'resume' ) : null;
	$title = $page ? html_entity_decode( (string) get_the_title( $page->ID ), ENT_QUOTES, 'UTF-8' ) : '';
	$title = '' === $title ? __( 'Resume', 'signal-and-noise-tools' ) : $title;
	$url   = $page ? (string) get_permalink( $page->ID ) : home_url( '/resume/' );
	$out   = array();
	foreach ( array_filter( $by ) as $code => $readers ) {
		/* translators: 1: the résumé page title, 2: a link code. */
		$out[] = array( 'label' => sprintf( __( '%1$s via %2$s', 'signal-and-noise-tools' ), $title, $code ), 'url' => $url, 'readers' => (int) $readers );
	}
	usort( $out, static fn( $a, $b ) => $b['readers'] <=> $a['readers'] ?: strcmp( $a['label'], $b['label'] ) );
	return $out;
}
