<?php
/**
 * Signal & Noise — MCP server: JSON-RPC 2.0 envelope + method router. Transport-
 * agnostic: takes a decoded request array, returns a response array (or null for
 * a notification). The endpoint layer owns HTTP/serialization. Sub-project B.
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
 * @param mixed $request Decoded JSON-RPC request.
 * @return array<string,mixed>|null
 */
function sn_mcp_handle_request( $request ) {
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
					'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
					'serverInfo'      => sn_mcp_server_info(),
				)
			);

		case 'ping':
			return sn_mcp_result_response( $id, (object) array() );

		case 'tools/list':
			return sn_mcp_result_response( $id, sn_mcp_list_tools() );

		case 'tools/call':
			$call = sn_mcp_call_tool(
				isset( $params['name'] ) ? $params['name'] : '',
				isset( $params['arguments'] ) ? $params['arguments'] : array()
			);
			if ( isset( $call['error'] ) ) {
				return sn_mcp_error_response( $id, $call['error']['code'], $call['error']['message'] );
			}
			return sn_mcp_result_response( $id, $call['result'] );

		default:
			return sn_mcp_error_response( $id, -32601, 'Method not found: ' . $method );
	}
}
