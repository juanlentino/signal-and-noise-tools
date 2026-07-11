<?php
/**
 * Signal & Noise — MCP server: tool projection + call dispatch. Projects an
 * allowlisted WP_Ability into an MCP Tool and executes tools/call with the
 * allowlist gate + per-ability permission check. Sub-project B.
 *
 * @package SignalNoiseTools
 * @since 9.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability slug → MCP tool name. MCP tool names must match ^[a-zA-Z0-9_-]{1,64}$;
 * slugs contain '/'. Map '/' → '__' (reversible, collision-free — no slug
 * contains '__').
 *
 * @param string $slug
 * @return string
 */
function sn_mcp_tool_name_from_slug( $slug ) {
	return str_replace( '/', '__', (string) $slug );
}

/**
 * MCP tool name → ability slug (inverse of sn_mcp_tool_name_from_slug).
 *
 * @param string $name
 * @return string
 */
function sn_mcp_slug_from_tool_name( $name ) {
	return str_replace( '__', '/', (string) $name );
}

/**
 * An input/output schema for a Tool must be a JSON Schema object. An ability
 * with no inputs has an empty array (encodes to []); normalize to {type:object}.
 *
 * @param mixed $schema
 * @return array<string,mixed>
 */
function sn_mcp_normalize_schema( $schema ) {
	if ( ! is_array( $schema ) || empty( $schema ) ) {
		return array( 'type' => 'object' );
	}
	return $schema;
}

/**
 * Project a WP_Ability into an MCP Tool. inputSchema/outputSchema pass through
 * the ability's own JSON Schema; outputSchema is included only when declared.
 *
 * @param object $ability A WP_Ability (or test stand-in) exposing the accessors.
 * @return array<string,mixed>
 */
function sn_mcp_project_tool( $ability ) {
	$label = (string) $ability->get_label();
	$desc  = (string) $ability->get_description();
	$tool  = array(
		'name'        => sn_mcp_tool_name_from_slug( $ability->get_name() ),
		'description' => trim( '' === $desc ? $label : $label . ' — ' . $desc ),
		'inputSchema' => sn_mcp_normalize_schema( $ability->get_input_schema() ),
	);
	$out = $ability->get_output_schema();
	if ( is_array( $out ) && ! empty( $out ) ) {
		$tool['outputSchema'] = sn_mcp_normalize_schema( $out );
	}
	return $tool;
}

/**
 * Build the tools/list result: project every allowlisted ability that resolves.
 *
 * @return array{tools:array<int,array<string,mixed>>}
 */
function sn_mcp_list_tools() {
	$tools = array();
	foreach ( sn_mcp_allowlist() as $slug ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
		if ( $ability ) {
			$tools[] = sn_mcp_project_tool( $ability );
		}
	}
	return array( 'tools' => $tools );
}

/**
 * A successful MCP tool result: both a text block (human) and structuredContent
 * (agent). isError:false.
 *
 * @param mixed $data
 * @return array<string,mixed>
 */
function sn_mcp_success_result( $data ) {
	return array(
		'content'           => array(
			array( 'type' => 'text', 'text' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ),
		),
		'structuredContent' => $data,
		'isError'           => false,
	);
}

/**
 * A tool-level error result (MCP convention: tool failures are results with
 * isError:true, not JSON-RPC errors).
 *
 * @param string $message
 * @return array<string,mixed>
 */
function sn_mcp_error_result( $message ) {
	return array(
		'content' => array( array( 'type' => 'text', 'text' => (string) $message ) ),
		'isError' => true,
	);
}

/**
 * Execute a tools/call. Returns:
 *   array{ error: array{code:int,message:string} } for a protocol error
 *     (unknown / not-allowlisted tool → the caller maps it to a JSON-RPC error);
 *   array{ result: array } for a tool result (success or isError).
 *
 * The allowlist gates the CALL here, so an un-advertised ability can never be
 * reached by naming it directly.
 *
 * @param string $tool_name
 * @param mixed  $arguments
 * @return array<string,mixed>
 */
function sn_mcp_call_tool( $tool_name, $arguments ) {
	$slug = sn_mcp_slug_from_tool_name( (string) $tool_name );

	if ( ! sn_mcp_is_allowed( $slug ) ) {
		return array( 'error' => array( 'code' => -32602, 'message' => 'Unknown tool: ' . (string) $tool_name ) );
	}
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
	if ( ! $ability ) {
		return array( 'error' => array( 'code' => -32602, 'message' => 'Tool not available: ' . (string) $tool_name ) );
	}

	$args = is_array( $arguments ) ? $arguments : array();

	$perm = $ability->check_permissions( $args );
	if ( is_wp_error( $perm ) || false === $perm ) {
		return array( 'result' => sn_mcp_error_result( 'Permission denied for ' . $slug ) );
	}

	$out = $ability->execute( $args );
	if ( is_wp_error( $out ) ) {
		return array( 'result' => sn_mcp_error_result( $out->get_error_message() ) );
	}

	return array( 'result' => sn_mcp_success_result( $out ) );
}
