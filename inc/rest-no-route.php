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
 * oracle on non-English sites. The MCP bridge keeps its own copy of the same
 * literal (sn_bridge_absent_route_error() in inc/mcp/mcp-bridge-route.php).
 *
 * @package SignalNoiseTools
 * @since Unreleased
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
		__( 'No route was found matching the URL and request method.', 'default' ),
		array( 'status' => 404 )
	);
}
