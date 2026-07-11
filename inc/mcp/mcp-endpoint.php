<?php
/**
 * Signal & Noise — MCP server: the HTTP endpoint. Registers POST
 * /wp-json/signal-noise/v1/mcp with a manage_options floor, decodes the body,
 * dispatches to the server layer, and serializes the response. The serialize
 * step is the seam for a future SSE branch (see the design spec §3). Also wires
 * the endpoint into sub-project A's sn_agents_surfaces discovery manifest.
 *
 * @package SignalNoiseTools
 * @since 9.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The endpoint's REST namespace (reuses the shared plugin namespace).
 *
 * @return string
 */
function sn_mcp_namespace() {
	return defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';
}

/**
 * Auth floor: only an administrator (authenticated via application password) may
 * reach any MCP method. This sits ABOVE each ability's own check_permissions().
 *
 * @return bool
 */
function sn_mcp_permission() {
	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

/**
 * Pure dispatch: decode a raw request body, route it, and return the HTTP status
 * + payload. Split from the REST callback so it is testable without WP_REST_*.
 * Returns array{ status:int, payload:array|null }.
 *
 * @param string $body Raw request body.
 * @return array{status:int,payload:array<string,mixed>|null}
 */
function sn_mcp_dispatch_body( $body ) {
	$decoded = json_decode( (string) $body, true );
	if ( null === $decoded && 'null' !== trim( (string) $body ) ) {
		return array( 'status' => 200, 'payload' => sn_mcp_error_response( null, -32700, 'Parse error' ) );
	}
	$response = sn_mcp_handle_request( $decoded );
	if ( null === $response ) {
		return array( 'status' => 202, 'payload' => null ); // notification: accepted, no body.
	}
	return array( 'status' => 200, 'payload' => $response );
}

/**
 * REST callback. Serializes the dispatch result to a JSON WP_REST_Response with
 * Cache-Control: no-store (authenticated, per-user). This IS the C-seam: today
 * always application/json; an SSE branch would fork here.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sn_mcp_rest_callback( $request ) {
	$out  = sn_mcp_dispatch_body( $request->get_body() );
	$resp = new WP_REST_Response( $out['payload'], $out['status'] );
	$resp->header( 'Cache-Control', 'no-store' );
	return $resp;
}

/**
 * Register the MCP route on the shared plugin namespace.
 */
function sn_mcp_register_route() {
	register_rest_route(
		sn_mcp_namespace(),
		'/mcp',
		array(
			'methods'             => 'POST',
			'callback'            => 'sn_mcp_rest_callback',
			'permission_callback' => 'sn_mcp_permission',
		)
	);
}

/**
 * Advertise the MCP endpoint in sub-project A's discovery manifest
 * (/.well-known/agents.json). The theme owns the filter; the plugin appends its
 * entry — the cross-repo payoff of A's seam.
 *
 * @param array<int,array<string,string>> $surfaces
 * @return array<int,array<string,string>>
 */
function sn_mcp_advertise_surface( $surfaces ) {
	// rest_url() honors a customized rest_url_prefix (unlike a hand-built /wp-json/).
	$url        = function_exists( 'rest_url' ) ? rest_url( sn_mcp_namespace() . '/mcp' ) : '';
	$surfaces[] = array(
		'type'        => 'mcp',
		'url'         => $url,
		'title'       => 'MCP server',
		'description' => "Model Context Protocol endpoint. Read-only tools over the site's abilities. Requires a WordPress application password.",
		'format'      => 'application/json',
		'auth'        => 'application-password',
	);
	return $surfaces;
}

if ( ! defined( 'SN_MCP_TEST' ) || ! SN_MCP_TEST ) {
	add_action( 'rest_api_init', 'sn_mcp_register_route' );
	add_filter( 'sn_agents_surfaces', 'sn_mcp_advertise_surface' );
}
