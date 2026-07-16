<?php
/**
 * Signal & Noise — MCP server: tool projection + call dispatch. Projects an
 * allowlisted WP_Ability into an MCP Tool and executes tools/call with the
 * allowlist gate + per-ability permission check. Sub-project B. Door-aware
 * since v9.50.0: every entry point takes a $door (SN_MCP_DOOR_READ by
 * default) and resolves its allowlist through sn_mcp_allowlist_for_door — the
 * two-doors security property (the allowlist gates the CALL per door, not
 * just the advertised list) lives here.
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
	// MCP requires the top-level tool schema type to be the literal "object".
	// The abilities declare a ['object','null'] union (their GET/null run-path),
	// which strict MCP hosts (e.g. the Anthropic tool-schema validator that a
	// client forwards to) reject. Force the scalar "object".
	$schema['type'] = 'object';
	// An empty PHP array encodes to JSON as [] — an object schema needs {}.
	if ( isset( $schema['properties'] ) && array() === $schema['properties'] ) {
		$schema['properties'] = (object) array();
	}
	return $schema;
}

/**
 * Whether an ability's raw output must be wrapped as {result: <output>} before
 * it can serve as MCP structuredContent. MCP requires structuredContent to be a
 * JSON object; only a schema root of exactly "object" (the literal string, or a
 * single-element ["object"] union) guarantees the ability's raw output is
 * always one. Array roots, nullable unions (the abilities' GET/null run-path),
 * scalars, and missing/undeclared schemas can all produce a non-object
 * structuredContent at runtime — wrap all of those.
 *
 * @param mixed $output_schema The ability's raw (un-normalized) output_schema.
 * @return bool
 */
function sn_mcp_schema_needs_wrap( $output_schema ) {
	if ( ! is_array( $output_schema ) || empty( $output_schema ) ) {
		return true;
	}
	$type = $output_schema['type'] ?? null;
	if ( 'object' === $type ) {
		return false;
	}
	if ( is_array( $type ) && array( 'object' ) === array_values( $type ) ) {
		return false;
	}
	return true;
}

/**
 * Project an ability's output_schema into the advertised MCP outputSchema. When
 * the root already guarantees an object (sn_mcp_schema_needs_wrap is false),
 * normalize as before. Otherwise wrap it: {type:object, properties:{result:
 * <the original schema, untouched — unions/null stay legal inside>},
 * required:[result]}. The "result" key on $out is never empty, so it always
 * encodes as a JSON object (no [] vs {} ambiguity to belt here).
 *
 * @param array<string,mixed> $out The ability's declared output_schema.
 * @return array<string,mixed>
 */
function sn_mcp_project_output_schema( $out ) {
	if ( ! sn_mcp_schema_needs_wrap( $out ) ) {
		return sn_mcp_normalize_schema( $out );
	}
	return array(
		'type'       => 'object',
		'properties' => array( 'result' => $out ),
		'required'   => array( 'result' ),
	);
}

/**
 * Project a WP_Ability into an MCP Tool. inputSchema passes through the
 * ability's own JSON Schema; outputSchema is included only when declared, and
 * is wrapped (sn_mcp_project_output_schema) when its root isn't guaranteed to
 * already be a JSON object. The read door advertises annotations.readOnlyHint
 * (truthful — every read-door ability is read-only by curation); the rw door
 * advertises no annotations in v1 (several registered abilities' own
 * annotations are known-wrong — don't launder them onto the projection).
 *
 * @param object $ability A WP_Ability (or test stand-in) exposing the accessors.
 * @param string $door    SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return array<string,mixed>
 */
function sn_mcp_project_tool( $ability, $door = SN_MCP_DOOR_READ ) {
	$label = (string) $ability->get_label();
	$desc  = (string) $ability->get_description();
	$tool  = array(
		'name'        => sn_mcp_tool_name_from_slug( $ability->get_name() ),
		'description' => trim( '' === $desc ? $label : $label . ': ' . $desc ),
		'inputSchema' => sn_mcp_normalize_schema( $ability->get_input_schema() ),
	);
	$out = $ability->get_output_schema();
	if ( is_array( $out ) && ! empty( $out ) ) {
		$tool['outputSchema'] = sn_mcp_project_output_schema( $out );
	}
	if ( SN_MCP_DOOR_READ === $door ) {
		$tool['annotations'] = array( 'readOnlyHint' => true );
	}
	return $tool;
}

/**
 * Build the tools/list result: project every allowlisted ability (for the
 * given door) that resolves. The rw door's tools/list is the rw allowlist
 * ONLY — the read-door 23 are never duplicated into it; a client wanting
 * reads uses the read door.
 *
 * @param string $door SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return array{tools:array<int,array<string,mixed>>}
 */
function sn_mcp_list_tools( $door = SN_MCP_DOOR_READ ) {
	$tools = array();
	foreach ( sn_mcp_allowlist_for_door( $door ) as $slug ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
		if ( $ability ) {
			$tools[] = sn_mcp_project_tool( $ability, $door );
		}
	}
	return array( 'tools' => $tools );
}

/**
 * A successful MCP tool result: both a text block (human) and structuredContent
 * (agent). isError:false.
 *
 * MCP requires structuredContent to be a JSON object: a PHP empty array encodes
 * to [] via wp_json_encode, so it must be cast to an object here to encode as
 * {}. The text block is unaffected — it stays the plain JSON-encoded $data.
 *
 * @param mixed $data
 * @return array<string,mixed>
 */
function sn_mcp_success_result( $data ) {
	$structured = ( is_array( $data ) && empty( $data ) ) ? (object) array() : $data;
	return array(
		'content'           => array(
			array( 'type' => 'text', 'text' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ),
		),
		'structuredContent' => $structured,
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
 * reached by naming it directly — and it does so PER DOOR: an rw-only slug
 * named on the read door is unknown, and the held-back/excluded slugs are
 * unknown on both doors regardless of $door.
 *
 * @param string $tool_name
 * @param mixed  $arguments
 * @param string $door      SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return array<string,mixed>
 */
function sn_mcp_call_tool( $tool_name, $arguments, $door = SN_MCP_DOOR_READ ) {
	if ( ! is_string( $tool_name ) ) {
		return array( 'error' => array( 'code' => -32602, 'message' => 'Invalid tool name' ) );
	}
	$slug = sn_mcp_slug_from_tool_name( $tool_name );

	if ( ! sn_mcp_is_allowed( $slug, $door ) ) {
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

	// Same rule as the advertised schema (sn_mcp_project_output_schema): wrap the
	// raw output in {result: ...} when its schema root doesn't guarantee an
	// object, so the two representations (advertised schema vs. actual call
	// result) never disagree on shape. Wrapping BEFORE sn_mcp_success_result()
	// keeps the text content block and structuredContent consistent — both are
	// built from the same (possibly wrapped) $out.
	if ( sn_mcp_schema_needs_wrap( $ability->get_output_schema() ) ) {
		// The inner value gets the same empty-array→{} discipline as the top
		// level: an object|null-union ability returning an EMPTY object would
		// otherwise wrap as {"result":[]} and fail its own advertised schema.
		$out = array( 'result' => ( is_array( $out ) && array() === $out ) ? (object) array() : $out );
	}

	return array( 'result' => sn_mcp_success_result( $out ) );
}
