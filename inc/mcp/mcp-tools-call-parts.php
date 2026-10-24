<?php
/**
 * Signal & Noise Tools: mcp-tools.php, sn_mcp_call_tool().
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
	// v10.25.0 (MCP Layer B telemetry): measured around the whole function body,
	// per sn-telemetry-spec.md's "latency_ms measured around sn_mcp_call_tool".
	// Every return point below calls sn_mcp_telemetry_record() (guarded by
	// function_exists so a missing/disabled telemetry module never affects
	// this function's behavior) — unlike the rw-only audit calls further down,
	// telemetry fires on BOTH doors, because it is a separate, broader layer.
	$sn_mcp_telemetry_t0 = microtime( true );

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
			if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
				sn_mcp_telemetry_record( $tool_name, $arguments, $door, 'refused', 'rate_limit', sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ) );
			}
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
		if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
			sn_mcp_telemetry_record( '', $arguments, $door, 'schema_error', null, sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ) );
		}
		return array( 'error' => array( 'code' => -32602, 'message' => 'Invalid tool name' ) );
	}
	$slug = sn_mcp_slug_from_tool_name( $tool_name );

	if ( ! sn_mcp_is_allowed( $slug, $door ) ) {
		if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
			sn_mcp_telemetry_record( $tool_name, $arguments, $door, 'not_found', null, sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ) );
		}
		return array( 'error' => array( 'code' => -32602, 'message' => 'Unknown tool: ' . (string) $tool_name ) );
	}
	$ability = sn_mcp_get_ability( $slug );
	if ( ! $ability ) {
		if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
			sn_mcp_telemetry_record( $tool_name, $arguments, $door, 'not_found', null, sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ) );
		}
		return array( 'error' => array( 'code' => -32602, 'message' => 'Tool not available: ' . (string) $tool_name ) );
	}

	$args = is_array( $arguments ) ? $arguments : array();

	// v9.88.0 (hardening gate): clear-template-overrides is deliberately held off
	// BOTH doors, but purge-all-caches advertises include_template_overrides,
	// which reaches the same irreversible wp_delete_post sweep of every
	// wp_template / wp_template_part row. Scrub the flag at the
	// rw door so the curation contract actually holds; cache purge stays usable.
	if ( defined( 'SN_MCP_DOOR_RW' ) && SN_MCP_DOOR_RW === $door && 'signal-noise/purge-all-caches' === $slug ) {
		unset( $args['include_template_overrides'] );
	}

	$perm = $ability->check_permissions( $args );
	if ( is_wp_error( $perm ) || false === $perm ) {
		// v9.51.0 (lane SEC-B, R4): rw-gated audit write, AFTER the permission
		// check resolves, BEFORE returning. Read door ($door === SN_MCP_DOOR_READ)
		// never reaches this — the read door's audit-log behavior is byte-frozen.
		if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_audit_record' ) ) {
			sn_mcp_rw_audit_record( $slug, $args, 'denied', $perm );
		}
		if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
			sn_mcp_telemetry_record( $tool_name, $args, $door, 'refused', 'permission', sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ) );
		}
		return array( 'result' => sn_mcp_error_result( 'Permission denied for ' . $slug ) );
	}

	// v10.38.0 (WP 7.1 prep): bracket execute() with the MCP-dispatch depth
	// flag so the wp_ability_* lifecycle observers in
	// inc/abilities-lifecycle-guard.php stand down — this wrapper already
	// records telemetry + rw audit for the call; without the flag every MCP
	// call would be double-logged once core starts firing those hooks.
	// SEC: reject arguments that violate the advertised input schema BEFORE
	// execute(), so a caller who believes they constrained a call is told they
	// did not. Ordered ahead of the depth flag and the try/finally deliberately:
	// nothing has been mutated or logged as an attempt yet, and a rejected call
	// must not consume the lifecycle bracket.
	//
	// v13.96.1 (#986): this ran on the WRITE door only, which left the estate
	// enforcing one declaration three different ways:
	//
	//   REST /wp-abilities/v1/.../run  WP_Ability::validate_input()  -> enforced
	//   MCP write door                 this check                    -> enforced
	//   MCP read door                  nothing                       -> NOT enforced
	//
	// Upstream is explicit that execute() does not validate ("input is not
	// automatically validated against the input schema" - class-wp-ability.php),
	// so the read door's dispatch had no validation at any layer. Every read
	// ability's docblock says "Validated against input_schema above"; on this
	// door that was false.
	//
	// Enforcing it here is symmetry, not a new rule, and it uses the SAME engine
	// as both other doors (rest_validate_value_from_schema), so the three cannot
	// disagree about what a schema means.
	if ( function_exists( 'sn_mcp_input_schema_violation' ) ) {
		$sn_in_bad = sn_mcp_input_schema_violation(
			$args,
			sn_mcp_normalize_schema( $ability->get_input_schema() )
		);
		if ( null !== $sn_in_bad ) {
			// #1212: the rw audit log claims every call reaching the rw door,
			// win or lose; a schema refusal is a loss, as a permission denial is.
			if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_audit_record' ) ) {
				sn_mcp_rw_audit_record( $slug, $args, 'denied', 'invalid_input' );
			}
			if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
				sn_mcp_telemetry_record( $tool_name, $arguments, $door, 'invalid_input', null, sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ) );
			}
			return array( 'error' => array( 'code' => -32602, 'message' => 'Invalid arguments: ' . $sn_in_bad ) );
		}
	}

	if ( function_exists( 'sn_ability_guard_mcp_depth' ) ) {
		sn_ability_guard_mcp_depth( 1 );
	}
	try {
		$out = $ability->execute( $args );
	} finally {
		if ( function_exists( 'sn_ability_guard_mcp_depth' ) ) {
			sn_ability_guard_mcp_depth( -1 );
		}
	}
	if ( is_wp_error( $out ) ) {
		if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_audit_record' ) ) {
			sn_mcp_rw_audit_record( $slug, $args, 'error', $out );
		}
		if ( function_exists( 'sn_mcp_telemetry_record' ) && function_exists( 'sn_mcp_telemetry_classify_wp_error' ) ) {
			$sn_mcp_telemetry_class = sn_mcp_telemetry_classify_wp_error( $out );
			sn_mcp_telemetry_record( $tool_name, $args, $door, $sn_mcp_telemetry_class['outcome'], $sn_mcp_telemetry_class['refusal_gate'], sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ), null, $sn_mcp_telemetry_class['error_code'], $sn_mcp_telemetry_class['error_detail'] ?? null, $sn_mcp_telemetry_class['error_status'] ?? null );
		}
		return array( 'result' => sn_mcp_error_result( $out->get_error_message() ) );
	}

	// Telemetry's result_count is measured against the ability's RAW output —
	// before the {result: ...} wrap below can turn a list-shaped root into an
	// assoc array keyed "result", which would make every wrapped tool read as
	// "not a list" regardless of what it actually returned.
	$sn_mcp_telemetry_result_count = function_exists( 'sn_mcp_telemetry_result_count' ) ? sn_mcp_telemetry_result_count( $out ) : null;

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
		// 21.1.0: unless the schema root is a LIST. get-cron-history returns []
		// for a hook that never fired; cast to {} it failed its own "array"
		// schema and the read errored exactly when the answer was "none yet".
		$out = array( 'result' => ( is_array( $out ) && array() === $out && ! sn_mcp_schema_root_is_list( $ability->get_output_schema() ) ) ? (object) array() : $out );
	}

	// v13.51.0: the advertised-schema re-check. Runs on the WRAPPED value
	// against the WRAPPED projection — the exact pair the client validates —
	// and refuses on mismatch rather than letting the client SDK discover it
	// after our telemetry has already said ok. This is not a new failure mode:
	// a payload that fails here was ALREADY failing on the client; it failed
	// invisibly. Kill switch: `sn_mcp_validate_output` filter, default true.
	if ( apply_filters( 'sn_mcp_validate_output', true ) ) {
		$sn_mcp_violation = sn_mcp_output_schema_violation( $out, sn_mcp_project_output_schema( $ability->get_output_schema() ) );
		if ( null !== $sn_mcp_violation ) {
			if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_audit_record' ) ) {
				sn_mcp_rw_audit_record( $slug, $args, 'error', new WP_Error( 'sn_mcp_output_schema_mismatch', $sn_mcp_violation ) );
			}
			if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
				sn_mcp_telemetry_record( $tool_name, $args, $door, 'server_error', null, sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ), null, 'sn_mcp_output_schema_mismatch', $sn_mcp_violation, 500 );
			}
			return array( 'result' => sn_mcp_error_result( 'Output schema mismatch for ' . $tool_name . ': ' . $sn_mcp_violation ) );
		}
	}

	// v9.51.0 (lane SEC-B, R4): the success-path rw audit write, at the true
	// tail of the function — every other change in this file lands ABOVE this
	// comment; the projection/wrap logic above is untouched by lane SEC-B.
	if ( SN_MCP_DOOR_RW === $door && function_exists( 'sn_mcp_rw_audit_record' ) ) {
		sn_mcp_rw_audit_record( $slug, $args, 'ok' );
	}
	if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
		sn_mcp_telemetry_record( $tool_name, $args, $door, 'ok', null, sn_mcp_telemetry_elapsed_ms( $sn_mcp_telemetry_t0 ), $sn_mcp_telemetry_result_count );
	}

	return array( 'result' => sn_mcp_success_result( $out ) );
}
