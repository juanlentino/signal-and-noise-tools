<?php
/**
 * Signal & Noise Tools: the refusal a machine route gives an unauthenticated
 * caller, byte-identical to the one WordPress returns for a route that was
 * never registered.
 *
 * A route that answers 503 when its token is unset and 403 when it is wrong
 * tells an anonymous prober whether the site has the secret configured. Both
 * cases answer this instead, so the route reads as absent either way. The
 * literal is WP_REST_Server::dispatch()'s, in core's `default` text domain on
 * purpose: a different domain would translate differently and rebuild the
 * oracle on non-English sites. The MCP bridge answers with this same error
 * (sn_bridge_absent_route_error() in inc/mcp/mcp-bridge-route.php).
 *
 * @package SignalNoiseTools
 * @since 2026.10.26
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return WP_Error rest_no_route, 404.
 */
function sn_rest_no_route_error() {
	return new WP_Error(
		'rest_no_route',
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Deliberate: byte parity with core's own rest_no_route body.
		// WHAT IS VERIFIED ABOUT THIS IGNORE, and what is not. Removing it leaves
		// `composer lint` green (that sniff is not enabled in the local WPCS
		// ruleset), so it is NOT load-bearing there. It is kept for Plugin Check,
		// which compares the domain against the plugin slug and would read
		// 'default' as a mismatch. That half is REASONED, not measured: CI has
		// only run this WITH the ignore present, and a pass in that state cannot
		// tell "needed" from "harmless". To know, drop it on a throwaway branch
		// and read Plugin Check.
		__( 'No route was found matching the URL and request method.', 'default' ),
		array( 'status' => 404 )
	);
}

/**
 * Keep every rest_no_route answer out of shared caches.
 *
 * 2026-10-17 audit: Cloudflare cached the 404 (no Cache-Control on core's
 * error response) and served it to the next request whatever its token, so
 * the analytics Worker's authorized GET /owner/revoked could read a primed
 * 404 and keep a stale revocation list. Applied to every rest_no_route, core's
 * own included, so the refusal stays byte-identical to an absent route.
 *
 * @param WP_HTTP_Response|mixed $response The dispatched response.
 * @return WP_HTTP_Response|mixed
 */
function sn_rest_no_route_no_store( $response ) {
	if ( is_object( $response ) && method_exists( $response, 'get_data' ) && method_exists( $response, 'header' ) ) {
		$data = $response->get_data();
		if ( is_array( $data ) && 'rest_no_route' === ( $data['code'] ?? '' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
			$response->header( 'Cloudflare-CDN-Cache-Control', 'no-store' );
		}
	}
	return $response;
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'rest_post_dispatch', 'sn_rest_no_route_no_store' );
}
