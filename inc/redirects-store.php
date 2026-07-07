<?php
/**
 * Signal & Noise Tools — general URL redirect map (B1, v8.10.0).
 *
 * A capped option (`sn_redirects`) of owner-authored source-path -> target
 * redirects, generalizing the tag-only map in inc/tag-consolidation-redirects.php
 * to arbitrary paths and both internal + external targets. Pure data layer:
 * normalize / upsert / delete / resolve, all exit-free and unit-testable. The
 * request-time handler that actually 30x-es lives in inc/redirects-handler.php.
 *
 * Storage shape — a source-keyed map so lookup is O(1) and a source can't be
 * duplicated:
 *   '/old-path' => array( 'to' => '/new-path'|'https://…', 'status' => 301|302, 'created_at' => ts )
 *
 * @package SignalNoiseTools
 * @since 8.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_REDIRECTS_OPT' ) ) {
	define( 'SN_REDIRECTS_OPT', 'sn_redirects' );
}
if ( ! defined( 'SN_REDIRECTS_MAX' ) ) {
	define( 'SN_REDIRECTS_MAX', 500 );
}

/**
 * Normalize a request path for storage + matching: strip the query string and
 * fragment, force a single leading slash, and drop a trailing slash (except for
 * the root). So `/foo/bar/`, `/foo/bar`, and `foo/bar?x=1` all key to `/foo/bar`
 * — trailing-slash and query differences never cause a redirect to miss.
 *
 * @param string $uri Request URI or path (may carry a query/fragment).
 * @return string Normalized path, always starting with '/'.
 */
function sn_redirects_normalize_path( $uri ) {
	$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
	if ( '' === $path ) {
		// wp_parse_url returns null/'' for a bare 'foo/bar' or ''; fall back to the
		// pre-'?' chunk so relative inputs still normalize.
		$path = strtok( (string) $uri, '?#' );
		$path = is_string( $path ) ? $path : '';
	}
	$path = '/' . ltrim( $path, '/' );
	if ( '/' !== $path ) {
		$path = rtrim( $path, '/' );
	}
	return '' === $path ? '/' : $path;
}

/**
 * The full source-keyed redirect map.
 *
 * @return array<string,array{to:string,status:int,created_at:int}>
 */
function sn_redirects_all() {
	$map = get_option( SN_REDIRECTS_OPT, array() );
	return is_array( $map ) ? $map : array();
}

/**
 * Upsert a redirect. Normalizes the source, coerces the status to 301|302,
 * preserves created_at on an existing source, and enforces the FIFO cap. Rejects
 * empty source/target and a source that would redirect to itself.
 *
 * @param string $source Source path (any form; normalized here).
 * @param string $target Destination — an internal path or an absolute http(s) URL.
 * @param int    $status HTTP redirect status (301 or 302; anything else → 301).
 * @return bool True on success, false when the input is invalid.
 */
function sn_redirect_save( $source, $target, $status = 301 ) {
	$source = sn_redirects_normalize_path( $source );
	$target = trim( (string) $target );
	if ( '/' === $source || '' === $target ) {
		return false; // no source, or redirecting the site root at large
	}
	// Self-loop guard: an internal target that normalizes back to the source. An
	// absolute http(s) target can never equal a normalized path, so it needs no
	// check here (the browser-level loop guard is the 30x itself).
	$is_external = (bool) preg_match( '#^https?://#i', $target );
	if ( ! $is_external && sn_redirects_normalize_path( $target ) === $source ) {
		return false;
	}
	$status = ( 302 === (int) $status ) ? 302 : 301;

	$map     = sn_redirects_all();
	$created = isset( $map[ $source ]['created_at'] ) ? (int) $map[ $source ]['created_at'] : time();
	$map[ $source ] = array(
		'to'         => $target,
		'status'     => $status,
		'created_at' => $created,
	);
	if ( count( $map ) > SN_REDIRECTS_MAX ) {
		$map = array_slice( $map, -SN_REDIRECTS_MAX, null, true );
	}
	update_option( SN_REDIRECTS_OPT, $map );
	return true;
}

/**
 * Delete a redirect by source path.
 *
 * @param string $source Source path (any form; normalized here).
 * @return bool True if a matching source existed and was removed.
 */
function sn_redirect_delete( $source ) {
	$source = sn_redirects_normalize_path( $source );
	$map    = sn_redirects_all();
	if ( ! isset( $map[ $source ] ) ) {
		return false;
	}
	unset( $map[ $source ] );
	update_option( SN_REDIRECTS_OPT, $map );
	return true;
}

/**
 * Exit-free resolver: given a request URI, return the destination + status to
 * send, or an empty array for no match. Internal targets are expanded to an
 * absolute URL via home_url(); external (http[s]) targets pass through verbatim.
 *
 * @param string $uri Request URI (may include a query string).
 * @return array{to:string,status:int}|array<empty,empty>
 */
function sn_redirect_target( $uri ) {
	$path = sn_redirects_normalize_path( $uri );
	$map  = sn_redirects_all();
	if ( ! isset( $map[ $path ] ) ) {
		return array();
	}
	$to  = (string) $map[ $path ]['to'];
	$abs = preg_match( '#^https?://#i', $to ) ? $to : home_url( '/' . ltrim( $to, '/' ) );
	return array(
		'to'     => $abs,
		'status' => ( 302 === (int) $map[ $path ]['status'] ) ? 302 : 301,
	);
}
