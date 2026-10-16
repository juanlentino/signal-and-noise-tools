<?php
/**
 * Signal & Noise Tools: mcp-tools.php, tool-name mapping, schema normalization
 * and projection, and the input and output schema checks.
 *
 * Split from inc/mcp/mcp-tools.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
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

	// v10.38.0 (WP 7.1 prep): delegate the CLIENT-PREP pass to core when it
	// exists — wp_prepare_json_schema_for_client() strips server-only keywords
	// (sanitize_callback / validate_callback / arg_options) and hoists
	// property-level required=>true into Draft-4 required arrays, neither of
	// which this function ever did. It runs FIRST; the fixes below stay applied
	// on top. Pre-7.1 this branch never runs: byte-identical behavior.
	//
	// The PROFILE is pinned explicitly rather than taken by default. Core's
	// signature is
	// `wp_prepare_json_schema_for_client( array $schema, string $schema_profile = 'draft-04' )`
	// and ships two profiles: 'draft-04' keeps the broader JSON Schema
	// vocabulary, 'rest-api' narrows to the REST keyword set. MCP hosts consume
	// standard JSON Schema, so the broader profile is the one we want — and
	// naming it means a future change to core's DEFAULT cannot silently reshape
	// every tool schema this server advertises. Pinning a default you depend on
	// costs one argument; discovering it moved costs a debugging session.
	//
	// Overlap correction (2026-08-11): the v10.38.0 comment claimed all three
	// fixes below were ours alone and that "core's pass does not cover them".
	// The 7.1 dev note says core's prep also arranges for an empty properties
	// array to serialize as `{}`, so the third fix OVERLAPS rather than
	// extending. Ours stays: it is the only thing doing it pre-7.1, and post-7.1
	// it is a no-op by construction (once core has made properties an object,
	// `array() === $schema['properties']` is false). The scalar-type and
	// top-level-combinator fixes remain genuinely ours — they are a contract
	// with strict MCP hosts that core has no reason to know about.
	if ( function_exists( 'wp_prepare_json_schema_for_client' ) ) {
		$schema = wp_prepare_json_schema_for_client( $schema, 'draft-04' );
	}

	// MCP requires the top-level tool schema type to be the literal "object".
	// The abilities declare a ['object','null'] union (their GET/null run-path),
	// which strict MCP hosts (e.g. the Anthropic tool-schema validator that a
	// client forwards to) reject. Force the scalar "object".
	$schema['type'] = 'object';

	// v9.53.0: strip TOP-LEVEL oneOf / allOf / anyOf. The same strict validator
	// rejects them outright —
	//   "input_schema does not support oneOf, allOf, or anyOf at the top level"
	// — and one malformed tool fails the ENTIRE request, not just its own tool.
	// The theme's signal-and-noise/get-active-template-structure has exactly
	// this: a top-level anyOf meaning "supply post_id OR slug".
	//
	// Nothing is weakened by dropping it HERE. This function projects an ability
	// into a TOOL schema; it does not touch the ability. The ability's own
	// execute-time validation still enforces the constraint server-side, and its
	// description already states it — so the model is still told, in prose
	// instead of schema, and an invalid call is still rejected.
	//
	// Only the top level: a combinator nested inside a property is a real
	// constraint the provider accepts, and rewriting those would silently narrow
	// the ability's contract.
	unset( $schema['oneOf'], $schema['allOf'], $schema['anyOf'] );

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
 * Whether an output schema's root can be a JSON list. PURE.
 *
 * @param mixed $schema The ability's declared output_schema.
 * @return bool
 */
function sn_mcp_schema_root_is_list( $schema ) {
	return is_array( $schema ) && in_array( 'array', (array) ( $schema['type'] ?? array() ), true );
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
 * First input-schema violation for a WRITE-door call, or null.
 *
 * The schemas already DECLARE `additionalProperties: false`; nothing enforced
 * it. An undeclared key was neither honoured nor rejected — it was dropped in
 * silence. Measured 2026-09-02: a `dry_run` key invented by the caller and
 * passed to signal-noise/apply-tag-description was accepted, ignored, and the
 * write happened anyway. The caller believed the call was constrained. That is
 * the harm, and it is specific to a door that MUTATES: on the read door a
 * dropped key yields a visibly wrong read, which the caller can see.
 *
 * This adds NO rules. It makes each ability's own published declaration true;
 * a schema without `additionalProperties: false` stays permissive.
 *
 * Validated against the ADVERTISED projection, never the raw ability schema —
 * the same rule as the output side, and for the same reason: tools/list strips
 * top-level anyOf/oneOf/allOf, so the raw schema would reject calls the
 * published contract allows.
 *
 * FAIL-OPEN when the validator is unavailable (standalone harness, early
 * bootstrap), exactly like the output check: no validator means no verdict,
 * never a rejection.
 *
 * @since 13.73.0
 * @param mixed $args   The arguments object from tools/call.
 * @param array $schema The ADVERTISED input schema (post sn_mcp_normalize_schema).
 * @return string|null First violation message, or null (valid OR unverifiable).
 */
function sn_mcp_input_schema_violation( $args, $schema ) {
	if ( ! function_exists( 'rest_validate_value_from_schema' ) || ! is_array( $schema ) || array() === $schema ) {
		return null;
	}
	$value = json_decode( (string) wp_json_encode( $args ), true );
	if ( null === $value ) {
		$value = array();
	}
	$check = rest_validate_value_from_schema( $value, $schema, 'arguments' );
	if ( is_wp_error( $check ) ) {
		return (string) $check->get_error_message();
	}
	return null;
}

/**
 * Does this (already-wrapped) success payload violate the ADVERTISED schema?
 *
 * WHY THIS EXISTS (v13.51.0). tools/list advertises
 * sn_mcp_project_output_schema( $ability->get_output_schema() ), and the MCP
 * client SDK validates every structuredContent against that advertisement ON
 * THE CLIENT. On a mismatch the SDK raises its own error after our server has
 * already returned success — so our telemetry records `ok` for a call whose
 * caller saw a failure. Schema drift ships as a silent success in the one
 * instrument that exists to catch it, which is the exact wrong-call-readout
 * shape the telemetry programme keeps closing.
 *
 * The fix is symmetry: validate the SAME value against the SAME projection,
 * server-side, before returning success. Core's REST validator is the engine —
 * it speaks the schema dialect these abilities are written in (it is what
 * validates their inputs already).
 *
 * FAIL-OPEN when the validator is unavailable (standalone harness, early
 * bootstrap): no validator means no verdict, and inventing a hand-rolled
 * validator here would drift from the client's. A skipped check returns null
 * exactly like a passing one — callers must not read null as "validated".
 *
 * @since 13.51.0
 * @param mixed $out    The success payload AFTER the {result:...} wrap — the
 *                      same value structuredContent is built from.
 * @param array $schema The ADVERTISED projection (already wrapped), never the
 *                      raw ability schema — validating against anything other
 *                      than what tools/list said would re-open the gap.
 * @return string|null First violation message, or null (valid OR unverifiable).
 */
function sn_mcp_output_schema_violation( $out, $schema ) {
	if ( ! function_exists( 'rest_validate_value_from_schema' ) || ! is_array( $schema ) || array() === $schema ) {
		return null;
	}
	// structuredContent casts a top-level empty array to (object); the
	// validator wants the pre-cast value. Objects that ARE present (the inner
	// empty-object belt in the wrap) are flattened back to arrays for it.
	$value = json_decode( (string) wp_json_encode( $out ), true );
	$check = rest_validate_value_from_schema( $value, $schema, 'result' );
	if ( is_wp_error( $check ) ) {
		return (string) $check->get_error_message();
	}
	return null;
}

