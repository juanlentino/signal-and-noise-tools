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
 * sn_mcp_rw_allowlist()), sharing this same plumbing — same no-store header,
 * same JSON-RPC dispatch. The door is resolved from WHICH ROUTE MATCHED (two
 * thin REST callbacks) and passed down as an explicit parameter, never
 * stashed in a global: sn_mcp_dispatch_body() forwards it toward
 * sn_mcp_handle_request() for the method router to use once it is door-aware.
 * The rw door is deliberately NOT added to the sn_agents_surfaces manifest
 * (see sn_mcp_advertise_surface) — an unattended-discovery surface should
 * only name the unattended-safe door.
 *
 * v9.51.0 (lane SEC-A) hardens the rw door's permission floor: it no longer
 * shares sn_mcp_permission() with the read door (see the finding in
 * ~/.claude/session-data/mcp-rw-hardening-research-2026-07-16.md — before
 * this, a leaked read credential was exactly as dangerous as a write one).
 * The rw route now uses sn_mcp_rw_permission(), which layers the kill switch
 * (R2, checked first — before even the manage_options floor) and the
 * credential split (R1, checked after) on top of the UNCHANGED
 * sn_mcp_permission() floor. inc/mcp/mcp-rw-guard.php owns the pure
 * predicates; this function only sequences them. The read door's
 * sn_mcp_permission() and its route registration are BYTE-FROZEN — neither
 * this function nor mcp-rw-guard.php is ever called from the read path.
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
 * Rw-door permission floor (v9.51.0, lane SEC-A). Sequenced exactly per the
 * spec's R1/R2:
 *
 *   1. R2 kill switch — FIRST, before even manage_options. A 403 here means
 *      tools/list can never leak the rw tool set while the door is disabled.
 *   2. The existing manage_options floor (sn_mcp_permission(), unchanged) —
 *      a non-admin gets the same plain `false` denial as always; no new
 *      information is disclosed to a caller that was never an admin.
 *   3. R1 credential split — only reached once the admin floor is cleared.
 *      Deny-with-guidance (a WP_Error naming the fix) on any of: no bound
 *      credential yet (deny-closed, see mcp-rw-guard.php's DECISION docblock
 *      on sn_mcp_rw_credential_decision), no app-password auth on this
 *      request, or an app-password auth that doesn't match the bound UUID.
 *
 * Returns true (allow), false (the pre-existing non-admin denial shape), or a
 * WP_Error (a guard denial — WP_REST_Server uses its 'status' data as the
 * HTTP status and its message as the body).
 *
 * @return bool|WP_Error
 */
function sn_mcp_rw_permission() {
	if ( sn_mcp_rw_kill_switch_engaged() ) {
		return sn_mcp_rw_error( 'rw_disabled' );
	}
	if ( ! sn_mcp_permission() ) {
		return false;
	}
	$decision = sn_mcp_rw_credential_authorize();
	if ( ! $decision['allow'] ) {
		return sn_mcp_rw_error( $decision['code'] );
	}
	return true;
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
function sn_mcp_dispatch_body( $body, $door = SN_MCP_DOOR_READ, $headers = array() ) {
	$decoded = json_decode( (string) $body, true );
	if ( null === $decoded && 'null' !== trim( (string) $body ) ) {
		return array( 'status' => 200, 'payload' => sn_mcp_error_response( null, -32700, 'Parse error' ) );
	}
	// Dual-era (v14.2.0): a request that carries the modern per-request
	// metadata, or names a modern version in its header, is served
	// statelessly by mcp-modern.php. Everything else is the handshake path
	// below, unchanged. See sn_mcp_is_modern_request().
	if ( function_exists( 'sn_mcp_is_modern_request' ) && sn_mcp_is_modern_request( $headers, $decoded ) ) {
		return sn_mcp_modern_handle( $headers, $decoded, $door );
	}
	$response = sn_mcp_handle_request( $decoded, $door );
	if ( null === $response ) {
		return array( 'status' => 202, 'payload' => null ); // notification: accepted, no body.
	}
	return array( 'status' => 200, 'payload' => $response );
}

/**
 * The three mirrored headers the modern era reads, lowercase-keyed, absent
 * keys omitted. WP_REST_Request::get_header() already canonicalises the
 * name; nothing else from the request reaches the router.
 *
 * @param WP_REST_Request $request
 * @return array<string,string>
 */
function sn_mcp_request_headers( $request ) {
	$out = array();
	foreach ( array( 'mcp-protocol-version', 'mcp-method', 'mcp-name' ) as $name ) {
		$value = $request->get_header( $name );
		if ( null !== $value && '' !== $value ) {
			$out[ $name ] = (string) $value;
		}
	}
	return $out;
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
	$out  = sn_mcp_dispatch_body( $request->get_body(), $door, sn_mcp_request_headers( $request ) );
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
	// v10.9.0: the callback is now the layered read guard (kill switch →
	// the byte-identical sn_mcp_permission() floor). The v9.51.0 freeze's
	// real invariant — the read path never calls mcp-rw-guard.php — holds;
	// see inc/mcp/mcp-read-guard.php and the amended pin in
	// tests/mcp-endpoint.php.
	register_rest_route(
		sn_mcp_namespace(),
		'/mcp',
		array(
			'methods'             => 'POST',
			'callback'            => 'sn_mcp_rest_callback',
			'permission_callback' => 'sn_mcp_read_permission',
		)
	);
}

/**
 * Register the rw-door MCP route, alongside /mcp: same namespace, its own
 * REST callback so the door context resolves from which route matched, and
 * (since v9.51.0, lane SEC-A) its own hardened permission_callback —
 * sn_mcp_rw_permission(), which layers the kill switch + credential split on
 * top of the same manage_options floor the read door uses. See
 * sn_mcp_rw_permission()'s docblock for the exact check order.
 */
function sn_mcp_register_rw_route() {
	register_rest_route(
		sn_mcp_namespace(),
		'/mcp-rw',
		array(
			'methods'             => 'POST',
			'callback'            => 'sn_mcp_rw_rest_callback',
			'permission_callback' => 'sn_mcp_rw_permission',
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

/**
 * Answer 405 to GET and DELETE on both MCP doors.
 *
 * Streamable HTTP clients open a GET on the endpoint to ask for a
 * server-push stream. The spec's answer from a server that offers none is
 * 405 Method Not Allowed; a client reads that as "no stream, carry on".
 * With only POST registered, core answered 404 rest_no_route instead, and
 * mcp-remote treats anything but 405 as a transport error: two logged
 * failures and a backoff retry on every bridge start. That retry is what
 * pushed the bridge's initialize past Claude Desktop's 10 s connect budget
 * on 2026-09-12, so the desktop published its tool set without sn/sn-write.
 * DELETE is the session-termination request; 405 there means "no session
 * to end", which is also what the spec allows.
 *
 * Registered on the same paths as the POST handlers: core keeps one route
 * with one handler per method, so the read/rw registrations and their
 * pins in tests/mcp-endpoint.php are untouched. Gated by the door's own
 * permission callback — see sn_mcp_register_method_not_allowed_routes().
 *
 * @return WP_REST_Response
 */
function sn_mcp_method_not_allowed() {
	return new WP_REST_Response(
		array(
			'code'    => 'sn_mcp_method_not_allowed',
			'message' => 'This MCP endpoint speaks Streamable HTTP over POST only; it offers no server-push stream.',
			'data'    => array( 'status' => 405 ),
		),
		405,
		array( 'Allow' => 'POST' )
	);
}

/**
 * Register the 405 handlers for GET/DELETE on /mcp and /mcp-rw.
 *
 * Each handler is gated by the SAME permission callback as its door's POST
 * twin. mcp-remote sends the Authorization header on the GET probe too, so an
 * authenticated client gets the 405 it needs; an unauthenticated probe gets
 * the door's ordinary refusal and learns nothing. The plugin's public REST
 * surface (tests/rest-routes.php) therefore stays at exactly three routes.
 *
 * Two literal calls rather than a loop: the census parser reads the route
 * argument as written in source.
 *
 * @return void
 */
function sn_mcp_register_method_not_allowed_routes() {
	register_rest_route(
		sn_mcp_namespace(),
		'/mcp',
		array(
			'methods'             => 'GET, DELETE',
			'callback'            => 'sn_mcp_method_not_allowed',
			'permission_callback' => 'sn_mcp_read_permission',
			'show_in_index'       => false,
		)
	);
	register_rest_route(
		sn_mcp_namespace(),
		'/mcp-rw',
		array(
			'methods'             => 'GET, DELETE',
			'callback'            => 'sn_mcp_method_not_allowed',
			'permission_callback' => 'sn_mcp_rw_permission',
			'show_in_index'       => false,
		)
	);
}

if ( ! defined( 'SN_MCP_TEST' ) || ! SN_MCP_TEST ) {
	add_action( 'rest_api_init', 'sn_mcp_register_route' );
	add_action( 'rest_api_init', 'sn_mcp_register_rw_route' );
	add_action( 'rest_api_init', 'sn_mcp_register_method_not_allowed_routes' );
	add_filter( 'sn_agents_surfaces', 'sn_mcp_advertise_surface' );
}
