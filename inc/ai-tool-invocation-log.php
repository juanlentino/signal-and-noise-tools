<?php
/**
 * Signal & Noise Tools — the Copilot tool-invocation log.
 *
 * desktop-mode dispatches Ask AI tools server-side, so the plugin's own AI usage
 * log (sn_ai_usage_log) never sees a Copilot turn — it records our first-party
 * generate_text_result() calls only, keyed by a feature label, not a tool. That
 * left the v9.59.0 prune with no invocation evidence: it had to argue from
 * redundancy + payload size. This closes that gap.
 *
 * desktop-mode fires `desktop_mode_ai_tool_called` (Stable @ 0.5.1, verified at
 * v0.9.5) on every tool call, in our request, before execute() — carrying the
 * STRIPPED tool name (export_audit_log), the same shape the prune matches. We
 * record which tool the model CHOSE, so the next prune reads "never invoked in
 * N days" instead of guessing.
 *
 * THE PRIVACY RULE: names + counts + timestamps ONLY. The action also carries
 * `args`, which can hold the user's query fragments or content — we take the tool
 * name and NOTHING ELSE from the context. A test walks the stored payload for a
 * canary and is mutation-checked.
 *
 * The log is NOT exposed as a Copilot tool: a read-only ability would re-add the
 * per-turn rent this whole initiative removed. It is read via the accessor below
 * and surfaced on the AI-spend dashboard.
 *
 * @package signal-and-noise-tools
 * @since   9.60.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_AI_TOOL_INVOCATIONS_OPT', 'sn_ai_tool_invocations' );

// Bounded by the real tool count (~40-50). The cap only refuses NEW keys past it
// — a guard against a misbehaving upstream churning tool names, never a loss of
// data for a tool we already track.
define( 'SN_AI_TOOL_INVOCATIONS_CAP', 200 );

/**
 * Record one Copilot tool invocation: increment its count, stamp first/last.
 *
 * Hooked on desktop-mode's `desktop_mode_ai_tool_called`. Takes the tool name and
 * NOTHING ELSE from the context — never `args` (potential PII).
 *
 * @since 9.60.0
 * @param mixed $ctx The action payload: { tool_name, args, user_id, request_id }.
 * @return void
 */
function snt_ai_record_tool_invocation( $ctx ) {
	$name = is_array( $ctx ) ? (string) ( $ctx['tool_name'] ?? '' ) : '';
	if ( '' === $name ) {
		return;
	}

	$log = get_option( SN_AI_TOOL_INVOCATIONS_OPT, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	if ( isset( $log[ $name ] ) && is_array( $log[ $name ] ) ) {
		$log[ $name ]['n']    = (int) ( $log[ $name ]['n'] ?? 0 ) + 1;
		$log[ $name ]['last'] = time();
	} else {
		if ( count( $log ) >= SN_AI_TOOL_INVOCATIONS_CAP ) {
			return; // Refuse to grow past the cap; known tools still increment above.
		}
		$now            = time();
		$log[ $name ]   = array(
			'n'     => 1,
			'first' => $now,
			'last'  => $now,
		);
	}

	update_option( SN_AI_TOOL_INVOCATIONS_OPT, $log, false );
}
add_action( 'desktop_mode_ai_tool_called', 'snt_ai_record_tool_invocation' );

/**
 * The tool-invocation map: `[ tool_name => [ n, first, last ] ]`.
 *
 * Returns array() when nothing has been recorded — an unmeasured log is an empty
 * answer, never null.
 *
 * @since 9.60.0
 * @return array<string,array{n:int,first:int,last:int}>
 */
function snt_ai_tool_invocations() {
	$log = get_option( SN_AI_TOOL_INVOCATIONS_OPT, array() );
	return is_array( $log ) ? $log : array();
}
