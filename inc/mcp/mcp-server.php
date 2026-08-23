<?php
/**
 * Signal & Noise — MCP server: JSON-RPC 2.0 envelope + method router. Transport-
 * agnostic: takes a decoded request array, returns a response array (or null for
 * a notification). The endpoint layer owns HTTP/serialization. Sub-project B.
 *
 * v9.50.0 (lane PROTO): the router takes $door and forwards it wherever door
 * context matters — sn_mcp_server_info() (initialize's serverInfo), and
 * sn_mcp_list_tools()/sn_mcp_call_tool() (already door-aware since lane
 * DOORS; this file was the one piece of the request path still passing them
 * no door at all). Resources and prompts are NOT door-gated — R1: both doors
 * serve the same read-only set — so their handlers never see $door. Also
 * adds resources/list, resources/read, prompts/list, prompts/get, and
 * advertises both capabilities from initialize.
 *
 * @package SignalNoiseTools
 * @since 9.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a JSON-RPC 2.0 success response.
 *
 * @param mixed $id
 * @param mixed $result
 * @return array<string,mixed>
 */
function sn_mcp_result_response( $id, $result ) {
	return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
}

/**
 * Build a JSON-RPC 2.0 error response.
 *
 * @param mixed  $id
 * @param int    $code
 * @param string $message
 * @return array<string,mixed>
 */
function sn_mcp_error_response( $id, $code, $message ) {
	return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => (int) $code, 'message' => (string) $message ) );
}

/**
 * Route a decoded JSON-RPC request to an MCP method. Returns the response array,
 * or null for a notification (a request with no `id`, which gets no reply).
 *
 * @param mixed  $request Decoded JSON-RPC request.
 * @param string $door    SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW — the
 *                        route context, resolved by the endpoint layer from
 *                        which REST route matched. Only tools/list, tools/call,
 *                        and initialize's serverInfo are door-aware; see the
 *                        file docblock.
 * @return array<string,mixed>|null
 */
function sn_mcp_handle_request( $request, $door = SN_MCP_DOOR_READ ) {
	if ( ! is_array( $request ) || ! isset( $request['jsonrpc'] ) || '2.0' !== $request['jsonrpc'] ) {
		$id = is_array( $request ) && array_key_exists( 'id', $request ) ? $request['id'] : null;
		return sn_mcp_error_response( $id, -32600, 'Invalid Request' );
	}

	if ( ! array_key_exists( 'id', $request ) ) {
		return null; // JSON-RPC: a notification (no id) never receives a response.
	}
	$id     = $request['id'];
	$method = isset( $request['method'] ) ? (string) $request['method'] : '';
	$params = isset( $request['params'] ) && is_array( $request['params'] ) ? $request['params'] : array();

	switch ( $method ) {
		case 'initialize':
			return sn_mcp_result_response(
				$id,
				array(
					'protocolVersion' => sn_mcp_negotiate_version( isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : '' ),
					'capabilities'    => sn_mcp_capabilities_map(),
					'serverInfo'      => sn_mcp_server_info( $door ),
				)
			);

		case 'ping':
			return sn_mcp_result_response( $id, (object) array() );

		case 'tools/list':
			return sn_mcp_result_response( $id, sn_mcp_list_tools( $door ) );

		case 'tools/call':
			$call = sn_mcp_call_tool(
				isset( $params['name'] ) ? $params['name'] : '',
				isset( $params['arguments'] ) ? $params['arguments'] : array(),
				$door
			);
			if ( isset( $call['error'] ) ) {
				return sn_mcp_error_response( $id, $call['error']['code'], $call['error']['message'] );
			}
			return sn_mcp_result_response( $id, $call['result'] );

		case 'resources/list':
			return sn_mcp_result_response( $id, sn_mcp_resources_list() );

		case 'resources/read':
			$uri    = isset( $params['uri'] ) ? (string) $params['uri'] : '';
			$result = sn_mcp_resource_read( $uri );
			if ( null === $result ) {
				return sn_mcp_error_response( $id, -32602, 'Unknown resource: ' . $uri );
			}
			return sn_mcp_result_response( $id, $result );

		case 'prompts/list':
			return sn_mcp_result_response( $id, sn_mcp_prompts_list() );

		case 'prompts/get':
			$prompt_name = isset( $params['name'] ) ? (string) $params['name'] : '';
			$prompt_args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
			$result      = sn_mcp_prompt_get( $prompt_name, $prompt_args );
			if ( null === $result ) {
				return sn_mcp_error_response( $id, -32602, 'Unknown prompt: ' . $prompt_name );
			}
			return sn_mcp_result_response( $id, $result );

		default:
			return sn_mcp_error_response( $id, -32601, 'Method not found: ' . $method );
	}
}
