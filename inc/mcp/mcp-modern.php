<?php
/**
 * Signal & Noise — MCP server: the 2026-07-28 revision, dual-era.
 *
 * A dual-era server "selects its behavior from how the client opens": a
 * request carrying `_meta["io.modelcontextprotocol/protocolVersion"]` (or
 * naming a modern version in its MCP-Protocol-Version header) is served here,
 * statelessly; an `initialize` selects the legacy handshake in mcp-server.php,
 * byte-for-byte as before. Both doors share this layer; the door context
 * threads through exactly as it does for the legacy router.
 *
 * What this layer owes the spec, in the order it checks:
 *   1. `_meta.protocolVersion` present             else -32602 / 400
 *   2. header present and equal to it               else -32020 / 400
 *   3. version one of ours                          else -32022 / 400 (+supported)
 *   4. `Mcp-Method` present and equal to `method`   else -32020 / 400
 *   5. `Mcp-Name` on call/read/get, equal to body   else -32020 / 400
 *   6. `_meta.clientCapabilities` present           else -32602 / 400
 *   7. method known                                 else -32601 / 404
 * Every result carries resultType:"complete" and serverInfo in _meta; list,
 * read and discover results also carry ttlMs and cacheScope.
 *
 * The Worker (sn-remote-mcp-worker, src/modern.mjs) is the same layer in
 * JavaScript; the two are kept check-for-check identical on purpose.
 *
 * @package SignalNoiseTools
 * @since 14.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Modern versions this server answers for. Newest first. */
const SN_MCP_MODERN_VERSIONS = array( '2026-07-28' );

const SN_MCP_META_PREFIX = 'io.modelcontextprotocol/';

const SN_MCP_ERR_HEADER_MISMATCH     = -32020;
const SN_MCP_ERR_UNSUPPORTED_VERSION = -32022;

/**
 * Cache hints for the results that must carry them. The doors are
 * authenticated and per-site; "public" would let a shared cache serve one
 * credential's answer to another, so every hint here is "private".
 *
 * @return array<string,mixed>
 */
function sn_mcp_modern_cache_hints() {
	return array( 'ttlMs' => 3600000, 'cacheScope' => 'private' );
}

/**
 * Which era a request belongs to. Header or body is enough; either names it.
 *
 * @param array<string,string> $headers Lowercase header name => value.
 * @param mixed                $decoded The decoded JSON-RPC message.
 * @return bool
 */
function sn_mcp_is_modern_request( $headers, $decoded ) {
	$header = isset( $headers['mcp-protocol-version'] ) ? (string) $headers['mcp-protocol-version'] : null;
	if ( null !== $header && in_array( $header, SN_MCP_MODERN_VERSIONS, true ) ) {
		return true;
	}
	$meta = is_array( $decoded ) && isset( $decoded['params']['_meta'] ) && is_array( $decoded['params']['_meta'] ) ? $decoded['params']['_meta'] : null;
	return null !== $meta && isset( $meta[ SN_MCP_META_PREFIX . 'protocolVersion' ] ) && is_string( $meta[ SN_MCP_META_PREFIX . 'protocolVersion' ] );
}

/**
 * `Mcp-Name` (and Mcp-Param-*) may arrive as `=?base64?...?=` when the value is
 * not header-safe. Decode before comparing; a malformed sentinel is a mismatch.
 *
 * @param string|null $raw
 * @return string|null|false null when absent, false when malformed.
 */
function sn_mcp_decode_header_value( $raw ) {
	if ( null === $raw ) {
		return null;
	}
	if ( 0 === strpos( $raw, '=?base64?' ) && '?=' === substr( $raw, -2 ) ) {
		$decoded = base64_decode( substr( $raw, 9, -2 ), true );
		return false === $decoded ? false : $decoded;
	}
	return $raw;
}

/**
 * An HTTP-status-bearing JSON-RPC error for the modern path.
 *
 * @param mixed      $id
 * @param int        $code
 * @param string     $message
 * @param int        $status
 * @param array|null $data
 * @return array{status:int,payload:array<string,mixed>}
 */
function sn_mcp_modern_error( $id, $code, $message, $status, $data = null ) {
	$payload = sn_mcp_error_response( $id, $code, $message );
	if ( null !== $data ) {
		$payload['error']['data'] = $data;
	}
	return array( 'status' => $status, 'payload' => $payload );
}

/**
 * The seven checks above, in order. Returns the first refusal, or null when
 * the request is well-formed for this era.
 *
 * @param array<string,string> $headers
 * @param array<string,mixed>  $decoded
 * @return array{status:int,payload:array<string,mixed>}|null
 */
function sn_mcp_modern_validate( $headers, $decoded ) {
	$id     = array_key_exists( 'id', $decoded ) ? $decoded['id'] : null;
	$method = isset( $decoded['method'] ) ? (string) $decoded['method'] : '';
	$params = isset( $decoded['params'] ) && is_array( $decoded['params'] ) ? $decoded['params'] : array();
	$meta   = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
	$body_v = isset( $meta[ SN_MCP_META_PREFIX . 'protocolVersion' ] ) ? $meta[ SN_MCP_META_PREFIX . 'protocolVersion' ] : null;
	$head_v = isset( $headers['mcp-protocol-version'] ) ? (string) $headers['mcp-protocol-version'] : null;

	if ( ! is_string( $body_v ) ) {
		return sn_mcp_modern_error( $id, -32602, 'Invalid params: _meta["' . SN_MCP_META_PREFIX . 'protocolVersion"] is required on every request.', 400 );
	}
	if ( null === $head_v || $head_v !== $body_v ) {
		$why = null === $head_v ? 'is missing' : "'{$head_v}' does not match body value '{$body_v}'";
		return sn_mcp_modern_error( $id, SN_MCP_ERR_HEADER_MISMATCH, "Header mismatch: MCP-Protocol-Version header {$why}.", 400 );
	}
	if ( ! in_array( $body_v, SN_MCP_MODERN_VERSIONS, true ) ) {
		return sn_mcp_modern_error(
			$id,
			SN_MCP_ERR_UNSUPPORTED_VERSION,
			'Unsupported protocol version',
			400,
			array( 'supported' => sn_mcp_all_protocol_versions(), 'requested' => $body_v )
		);
	}
	$head_m = isset( $headers['mcp-method'] ) ? (string) $headers['mcp-method'] : null;
	if ( null === $head_m || $head_m !== $method ) {
		$why = null === $head_m ? 'is missing' : "'{$head_m}' does not match body value '{$method}'";
		return sn_mcp_modern_error( $id, SN_MCP_ERR_HEADER_MISMATCH, "Header mismatch: Mcp-Method header {$why}.", 400 );
	}
	$named = array( 'tools/call' => 'name', 'prompts/get' => 'name', 'resources/read' => 'uri' );
	if ( isset( $named[ $method ] ) ) {
		$head_n = sn_mcp_decode_header_value( isset( $headers['mcp-name'] ) ? (string) $headers['mcp-name'] : null );
		$body_n = isset( $params[ $named[ $method ] ] ) ? $params[ $named[ $method ] ] : null;
		if ( null === $head_n || false === $head_n || $head_n !== $body_n ) {
			$why = null === $head_n ? 'is missing' : 'does not match body value';
			return sn_mcp_modern_error( $id, SN_MCP_ERR_HEADER_MISMATCH, "Header mismatch: Mcp-Name header {$why} for {$method}.", 400 );
		}
	}
	$caps = isset( $meta[ SN_MCP_META_PREFIX . 'clientCapabilities' ] ) ? $meta[ SN_MCP_META_PREFIX . 'clientCapabilities' ] : null;
	if ( ! is_array( $caps ) && ! is_object( $caps ) ) {
		return sn_mcp_modern_error( $id, -32602, 'Invalid params: _meta["' . SN_MCP_META_PREFIX . 'clientCapabilities"] is required on every request.', 400 );
	}
	return null;
}

/**
 * Every modern result: resultType, and the server naming itself per request.
 *
 * @param mixed               $id
 * @param array<string,mixed> $result
 * @param string              $door
 * @param bool                $cacheable Whether the method must carry cache hints.
 * @return array{status:int,payload:array<string,mixed>}
 */
function sn_mcp_modern_complete( $id, $result, $door, $cacheable = false ) {
	$meta = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
	$meta[ SN_MCP_META_PREFIX . 'serverInfo' ] = sn_mcp_server_info( $door );
	$out = array_merge( array( 'resultType' => 'complete' ), $result, array( '_meta' => $meta ) );
	if ( $cacheable ) {
		$out = array_merge( $out, sn_mcp_modern_cache_hints() );
	}
	return array( 'status' => 200, 'payload' => sn_mcp_result_response( $id, $out ) );
}

/**
 * server/discover: versions, capabilities, identity, caching hints.
 *
 * @return array<string,mixed>
 */
function sn_mcp_discover_result() {
	return array(
		'supportedVersions' => sn_mcp_all_protocol_versions(),
		'capabilities'      => sn_mcp_capabilities_map(),
	);
}

/**
 * Serve one modern-era message. Auth has already passed at the route.
 *
 * @param array<string,string> $headers Lowercase header name => value.
 * @param mixed                $decoded The decoded JSON-RPC message.
 * @param string               $door
 * @return array{status:int,payload:array<string,mixed>|null}
 */
function sn_mcp_modern_handle( $headers, $decoded, $door = SN_MCP_DOOR_READ ) {
	if ( ! is_array( $decoded ) || ! isset( $decoded['jsonrpc'] ) || '2.0' !== $decoded['jsonrpc'] ) {
		$id = is_array( $decoded ) && array_key_exists( 'id', $decoded ) ? $decoded['id'] : null;
		return sn_mcp_modern_error( $id, -32600, 'Invalid Request', 400 );
	}
	// A notification: the core protocol defines none over Streamable HTTP in
	// this revision, and one that arrives is accepted and ignored — 202.
	if ( ! array_key_exists( 'id', $decoded ) ) {
		return array( 'status' => 202, 'payload' => null );
	}
	$id     = $decoded['id'];
	$method = isset( $decoded['method'] ) ? $decoded['method'] : null;
	if ( ! is_string( $method ) ) {
		return sn_mcp_modern_error( $id, -32600, 'Invalid Request', 400 );
	}
	$refused = sn_mcp_modern_validate( $headers, $decoded );
	if ( null !== $refused ) {
		return $refused;
	}
	$params = isset( $decoded['params'] ) && is_array( $decoded['params'] ) ? $decoded['params'] : array();

	switch ( $method ) {
		case 'server/discover':
			return sn_mcp_modern_complete( $id, sn_mcp_discover_result(), $door, true );

		case 'tools/list':
			return sn_mcp_modern_complete( $id, sn_mcp_list_tools( $door ), $door, true );

		case 'tools/call':
			$call = sn_mcp_call_tool(
				isset( $params['name'] ) ? $params['name'] : '',
				isset( $params['arguments'] ) ? $params['arguments'] : array(),
				$door
			);
			if ( isset( $call['error'] ) ) {
				// The method exists; the tool named in its arguments does not.
				// An RPC error at 200, exactly as the legacy path answers it.
				return sn_mcp_modern_error( $id, $call['error']['code'], $call['error']['message'], 200 );
			}
			return sn_mcp_modern_complete( $id, $call['result'], $door );

		case 'resources/list':
			return sn_mcp_modern_complete( $id, sn_mcp_resources_list(), $door, true );

		case 'resources/read':
			$uri    = isset( $params['uri'] ) ? (string) $params['uri'] : '';
			$result = sn_mcp_resource_read( $uri );
			if ( null === $result ) {
				return sn_mcp_modern_error( $id, -32602, 'Unknown resource: ' . $uri, 200 );
			}
			return sn_mcp_modern_complete( $id, $result, $door, true );

		case 'prompts/list':
			return sn_mcp_modern_complete( $id, sn_mcp_prompts_list(), $door, true );

		case 'prompts/get':
			$name   = isset( $params['name'] ) ? (string) $params['name'] : '';
			$args   = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
			$result = sn_mcp_prompt_get( $name, $args );
			if ( null === $result ) {
				return sn_mcp_modern_error( $id, -32602, 'Unknown prompt: ' . $name, 200 );
			}
			return sn_mcp_modern_complete( $id, $result, $door );

		default:
			// initialize, ping, notifications/* and the rest are not methods of
			// this era. The spec pairs -32601 with 404 so a client can tell
			// "modern server, unknown method" from "no endpoint here".
			return sn_mcp_modern_error( $id, -32601, 'Method not found: ' . $method, 404 );
	}
}
