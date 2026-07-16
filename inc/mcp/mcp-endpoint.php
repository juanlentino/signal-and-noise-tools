<?php
/**
 * Signal & Noise — MCP server: the HTTP endpoint(s). Registers POST
 * /wp-json/signal-noise/v1/mcp (read door) with a manage_options floor,
 * decodes the body, dispatches to the server layer, and serializes the
 * response. The serialize step is the seam for a future SSE branch (see the
 * design spec §3). Also wires the read door into sub-project A's
 * sn_agents_surfaces discovery manifest.
 *
 * v9.50.0 adds a second door, POST /mcp-rw (inc/mcp/mcp-capabilities.php's
 * sn_mcp_rw_allowlist()), sharing this same plumbing — same permission floor,
 * same no-store header, same JSON-RPC dispatch. The door is resolved from
 * WHICH ROUTE MATCHED (two thin REST callbacks) and passed down as an
 * explicit parameter, never stashed in a global: sn_mcp_dispatch_body()
 * forwards it toward sn_mcp_handle_request() for the method router to use
 * once it is door-aware. The rw door is deliberately NOT added to the
 * sn_agents_surfaces manifest (see sn_mcp_advertise_surface) — an unattended-
 * discovery surface should only name the unattended-safe door.
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
 * Returns array{ status:int, payload:array|null }. $door is the resolved route
 * context (SN_MCP_DOOR_READ or SN_MCP_DOOR_RW), forwarded toward the method
 * router — a parameter, never a mutable global.
 *
 * @param string $body Raw request body.
 * @param string $door SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return array{status:int,payload:array<string,mixed>|null}
 */
function sn_mcp_dispatch_body( $body, $door = SN_MCP_DOOR_READ ) {
	$decoded = json_decode( (string) $body, true );
	if ( null === $decoded && 'null' !== trim( (string) $body ) ) {
		return array( 'status' => 200, 'payload' => sn_mcp_error_response( null, -32700, 'Parse error' ) );
	}
	$response = sn_mcp_handle_request( $decoded, $door );
	if ( null === $response ) {
		return array( 'status' => 202, 'payload' => null ); // notification: accepted, no body.
	}
	return array( 'status' => 200, 'payload' => $response );
}

/**
 * Shared REST response builder for both doors: dispatch, then serialize to a
 * JSON WP_REST_Response with Cache-Control: no-store (authenticated,
 * per-user). This IS the C-seam: today always application/json; an SSE
 * branch would fork here.
 *
 * @param WP_REST_Request $request
 * @param string           $door SN_MCP_DOOR_READ or SN_MCP_DOOR_RW.
 * @return WP_REST_Response
 */
function sn_mcp_build_rest_response( $request, $door ) {
	$out  = sn_mcp_dispatch_body( $request->get_body(), $door );
	$resp = new WP_REST_Response( $out['payload'], $out['status'] );
	$resp->header( 'Cache-Control', 'no-store' );
	return $resp;
}

/**
 * Read-door REST callback — unchanged behavior from pre-v9.50.0.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sn_mcp_rest_callback( $request ) {
	return sn_mcp_build_rest_response( $request, SN_MCP_DOOR_READ );
}

/**
 * Rw-door REST callback (v9.50.0) — identical plumbing, rw door context.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sn_mcp_rw_rest_callback( $request ) {
	return sn_mcp_build_rest_response( $request, SN_MCP_DOOR_RW );
}

/**
 * Register the read-door MCP route on the shared plugin namespace.
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
 * Register the rw-door MCP route (v9.50.0), alongside /mcp: same namespace,
 * same permission floor (sn_mcp_permission — manage_options + application
 * password), its own REST callback so the door context resolves from which
 * route matched.
 */
function sn_mcp_register_rw_route() {
	register_rest_route(
		sn_mcp_namespace(),
		'/mcp-rw',
		array(
			'methods'             => 'POST',
			'callback'            => 'sn_mcp_rw_rest_callback',
			'permission_callback' => 'sn_mcp_permission',
		)
	);
}

/**
 * Advertise the MCP endpoint in sub-project A's discovery manifest
 * (/.well-known/agents.json). The theme owns the filter; the plugin appends its
 * entry — the cross-repo payoff of A's seam.
 *
 * D5 (v9.50.0): the rw door is deliberately NOT added here, and never will be
 * by this function. agents.json is an UNATTENDED discovery surface — any
 * crawler or agent can read it without a session — so it should only name the
 * door that is safe to hand to an unattended reader. The rw door still
 * exists and is reachable by anyone who already has the credentials (same
 * manage_options + application-password floor as the read door); it's just
 * never volunteered here. Document it in the leaf lane instead.
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
	add_action( 'rest_api_init', 'sn_mcp_register_rw_route' );
	add_filter( 'sn_agents_surfaces', 'sn_mcp_advertise_surface' );
}
