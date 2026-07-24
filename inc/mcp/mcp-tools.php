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
 * v9.51.0 (lane SEC-B, R4): sn_mcp_call_tool()'s tail now calls
 * sn_mcp_rw_audit_record() (inc/mcp/mcp-rw-audit.php) at its three outcome
 * points (permission denied, execute() WP_Error, success) — but ONLY when
 * $door === SN_MCP_DOOR_RW. The read door's dispatch is otherwise
 * byte-identical to pre-v9.51.0; nothing above the tail (projection, schema
 * wrapping) changed.
 *
 * v9.51.0 (lane SEC-C, R6+R7): two more rw-only additions, both gated on
 * $door === SN_MCP_DOOR_RW exactly like SEC-B's audit call:
 *   - R6: the rw door's projected tools now carry a fully-populated,
 *     curated `annotations` object (readOnlyHint/destructiveHint/
 *     idempotentHint) instead of v9.50.0's "no annotations key at all" —
 *     see sn_mcp_ability_annotations() below. The read door's single
 *     `{readOnlyHint:true}` shape is untouched.
 *   - R7: sn_mcp_call_tool()'s very first act, before even validating the
 *     tool name, is a rate-limit check (inc/mcp/mcp-rw-guard.php's
 *     sn_mcp_rw_rate_limit_gate()) — ONLY on the rw door. A denial is a
 *     JSON-RPC protocol error (never reaches an ability, never audited —
 *     same "protocol rejection, not audited" precedent SEC-B's own tests
 *     already pin for an unknown-tool call), carrying a retry hint in the
 *     error's `data` (JSON-RPC has no HTTP header seam mid-batch to carry a
 *     Retry-After in). JUDGMENT CALL, flagged: the spec placed R7 alongside
 *     R1/R2 in mcp-rw-guard.php (a file this lane does not own the call site
 *     of — inc/mcp/mcp-endpoint.php's permission_callback layer belongs to
 *     lane SEC-A), but R7's own text describes a JSON-RPC-error-shaped
 *     denial, which is THIS file's layer, not a REST WP_Error. This file
 *     hosts the gate; inc/mcp/mcp-rw-guard.php hosts the predicate.
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
 * R6 (lane SEC-C) known-wrong override map: destructiveHint values for
 * rw-door abilities whose OWN meta.annotations declares no 'destructive' key
 * at all. Without an entry here, sn_mcp_ability_annotations() would inherit
 * MCP's own maximally-cautious absent-key default (destructiveHint:true —
 * the research's Finding B) for every one of these — wrongly flagging a
 * read-then-suggest verdict call, a cache-refreshing scan, or a routine file
 * regen as destructive. Every slug below was cross-checked directly against
 * ~/.claude/session-data/abilities-audit-2026-07-15.md's per-ability
 * blast-radius classification (never guessed): none is flagged in that
 * audit's "Notable risks" section, and each one's actual execute callback
 * (read at the same time) does not delete/wipe/irreversibly mutate anything.
 * A slug that DOES declare 'destructive' (e.g. prune-unused-tags) never
 * consults this map at all — its own declared value always wins outright.
 *
 * @return array<string,bool>
 */
function sn_mcp_rw_annotation_destructive_overrides() {
	return array(
		// AI-BILLED verdict/suggestion generators (inc/abilities-ai-health.php):
		// each returns a suggestion only — no wp_update_post/postmeta/file write
		// of any kind (that's the PAIRED *-apply ability, which already
		// declares destructive:true on its own and needs no override). Audit
		// classification: AI-BILLED, not ACTION/WRITE-STATE.
		'signal-noise/ai-alt-suggest'        => false,
		'signal-noise/ai-drift-suggest'      => false,
		'signal-noise/ai-alt-inline-suggest' => false,
		'signal-noise/ai-orphan-suggest'     => false,
		'signal-noise/ai-link-suggest'       => false,
		'signal-noise/ai-pair-suggest'       => false,

		// ACTION + AI-BILLED (inc/abilities-insights.php, inc/abilities-narration.php):
		// each only refreshes + overwrites its OWN cached scan/digest result — not
		// a delete or a mutation of any user content. Audit's "Notable risks"
		// section does not flag either.
		'signal-noise/run-insights-scan' => false,
		'signal-noise/run-narration'      => false,

		// ACTION (file write, idempotent) per the audit — regenerates the
		// social-share PNG in place. Declares only idempotent:true; a routine,
		// repeatable file rebuild is not destructive.
		'signal-noise/regenerate-og-card' => false,

		// The 5 theme AI-generation abilities (return-only — never persist to
		// the DB; ../signal-and-noise/inc/abilities-ai-generation.php). Each
		// declares idempotent:false + readonly:false (trusted as declared) but
		// NO destructive key. Audit note: "return-only" — no
		// wp_update_post/postmeta/file write in any of the 5 callbacks.
		'signal-and-noise/ai-generate-page-note-summary' => false,
		'signal-and-noise/ai-suggest-block-pattern'       => false,
		'signal-and-noise/ai-validate-brand-alignment'    => false,
		'signal-and-noise/ai-generate-pattern-content'    => false,
		'signal-and-noise/ai-rewrite-in-brand-voice'      => false,
	);
}

/**
 * R6 (lane SEC-C): translate an ability's own meta.annotations (WP Abilities
 * vocabulary: 'readonly'/'destructive'/'idempotent', lowercase, no Hint
 * suffix) into MCP's tool-annotations vocabulary (readOnlyHint/
 * destructiveHint/idempotentHint). Every rw-door tool gets all THREE keys
 * explicitly, always — never omit one and let an MCP client apply its own
 * default, which is exactly the gap Finding B identified (an omitted
 * destructiveHint silently becomes `true` client-side).
 *
 * Precedence per key:
 *   - readOnlyHint: the ability's own 'readonly' if declared, else false
 *     (MCP's own default — nothing to override here across the 35 rw slugs).
 *   - idempotentHint: the ability's own 'idempotent' if declared, else false
 *     (MCP's own default — every rw-door ability in this codebase declares
 *     one, but the fallback exists for a future addition that doesn't).
 *   - destructiveHint: the ability's own 'destructive' if declared (always
 *     wins outright, no override consulted) — else false when the ability is
 *     already readOnlyHint:true (a read-only tool cannot be destructive by
 *     definition) — else the per-slug override map — else MCP's own
 *     maximally-cautious default (true), for any slug neither this ability's
 *     own registration nor the override map has ever addressed.
 *
 * @param object $ability A WP_Ability (or test stand-in) exposing get_meta().
 * @param string $slug    The ability's own slug (get_name()) — the override map's key.
 * @return array{readOnlyHint:bool,destructiveHint:bool,idempotentHint:bool}
 */
function sn_mcp_ability_annotations( $ability, $slug ) {
	$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
	$decl = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

	$read_only  = ! empty( $decl['readonly'] );
	$idempotent = ! empty( $decl['idempotent'] );

	if ( array_key_exists( 'destructive', $decl ) ) {
		$destructive = ! empty( $decl['destructive'] );
	} elseif ( $read_only ) {
		$destructive = false;
	} else {
		$overrides   = sn_mcp_rw_annotation_destructive_overrides();
		$destructive = array_key_exists( (string) $slug, $overrides ) ? $overrides[ (string) $slug ] : true;
	}

	return array(
		'readOnlyHint'    => $read_only,
		'destructiveHint' => $destructive,
		'idempotentHint'  => $idempotent,
	);
}

/**
 * Project a WP_Ability into an MCP Tool. inputSchema passes through the
 * ability's own JSON Schema; outputSchema is included only when declared, and
 * is wrapped (sn_mcp_project_output_schema) when its root isn't guaranteed to
 * already be a JSON object. The read door advertises annotations.readOnlyHint
 * ONLY (truthful — every read-door ability is read-only by curation; this
 * single-key shape is byte-frozen, unrelated to R6). The rw door (v9.51.0,
 * lane SEC-C, R6) advertises a fully-populated, curated annotations object
 * via sn_mcp_ability_annotations() — v9.50.0 through v9.50.x omitted rw
 * annotations entirely; R6 replaces that with a translated-and-corrected set.
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
	} elseif ( SN_MCP_DOOR_RW === $door ) {
		$tool['annotations'] = sn_mcp_ability_annotations( $ability, $ability->get_name() );
	}
	return $tool;
}

/**
 * Build the tools/list result: project every allowlisted ability (for the
 * given door) that resolves. The rw door's tools/list is the rw allowlist
 * ONLY — the read-door 25 are never duplicated into it; a client wanting
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
	// v9.51.0 (lane SEC-C, R7): the rate-limit gate is the very first thing
	// checked — before even validating $tool_name — and ONLY on the rw door.
	// The read door never calls sn_mcp_rw_rate_limit_gate() at all (byte-frozen:
	// no new function call, no new option/transient/cache read, on that path).
	// A denial is a JSON-RPC protocol error, same shape as the "unknown tool"
	// branch below, so it is NEVER audited (SEC-B's audit tail lives past this
	// point) and NEVER reaches an ability's permission_callback or execute().
	if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_rate_limit_gate' ) ) {
		$rate = sn_mcp_rw_rate_limit_gate();
		if ( ! $rate['allow'] ) {
			return array(
				'error' => array(
					'code'    => -32000,
					'message' => sprintf( 'Rate limit exceeded for the MCP write door. Retry after %d seconds.', (int) $rate['retry_after'] ),
					'data'    => array( 'retry_after' => (int) $rate['retry_after'] ),
				),
			);
		}
	}

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
		// v9.51.0 (lane SEC-B, R4): rw-gated audit write, AFTER the permission
		// check resolves, BEFORE returning. Read door ($door === SN_MCP_DOOR_READ)
		// never reaches this — the read door's audit-log behavior is byte-frozen.
		if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_audit_record' ) ) {
			sn_mcp_rw_audit_record( $slug, $args, 'denied', $perm );
		}
		return array( 'result' => sn_mcp_error_result( 'Permission denied for ' . $slug ) );
	}

	$out = $ability->execute( $args );
	if ( is_wp_error( $out ) ) {
		if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_audit_record' ) ) {
			sn_mcp_rw_audit_record( $slug, $args, 'error', $out );
		}
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

	// v9.51.0 (lane SEC-B, R4): the success-path rw audit write, at the true
	// tail of the function — every other change in this file lands ABOVE this
	// comment; the projection/wrap logic above is untouched by lane SEC-B.
	if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_audit_record' ) ) {
		sn_mcp_rw_audit_record( $slug, $args, 'ok' );
	}

	return array( 'result' => sn_mcp_success_result( $out ) );
}
