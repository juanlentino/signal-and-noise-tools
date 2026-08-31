<?php
/**
 * Signal & Noise — MCP Layer B telemetry: Desktop Mode AI-agent bridge.
 *
 * Desktop Mode 0.9.8's agent runner never goes through the MCP door at all —
 * includes/agents/runner.php:1190 calls `$ability->execute( $args )`
 * DIRECTLY, so every agent-driven tool call was previously invisible to
 * inc/mcp/mcp-telemetry.php's sn_tool_call table (that table only sees calls
 * through sn_mcp_call_tool()). This file closes that blind spot WITHOUT
 * touching the MCP door, using TWO seams — because one alone is
 * structurally success-only and would silently under-report the failure
 * rate to ~0% (this repo's standing "success-only readout" trap: a healthy
 * number can measure the wrong thing).
 *
 * SEAM 1 — the per-tool filter, runner.php:575:
 *
 *     $output = apply_filters( 'desktop_mode_agent_tool_result', $output, $slug, $args, $agent_user_id );
 *
 * Fires ONLY on the success path — `! is_wp_error( $output )` gates the
 * apply_filters() call itself at runner.php:561-576, so a failed/refused
 * tool call never reaches this filter on the real call site. Every row this
 * seam records is therefore outcome='ok' (or, defensively, whatever a
 * hostile/buggy earlier-priority filter callback handed us — a filter chain
 * has no exclusivity guarantee, so the callback still classifies a WP_Error
 * correctly instead of coercing it into a false "ok").
 *
 * SEAM 2 — the run-completed action, runner.php:239, added per adversarial
 * review (the HIGH finding: seam 1 alone is structurally success-only):
 *
 *     do_action( 'desktop_mode_agent_completed', (int) $user->ID, $message, $result, (array) $context );
 *
 * `$result['toolCalls']` (built at runner.php:578-593) carries one entry per
 * tool call for the ENTIRE run, success and failure alike:
 * `{callId, name, args, output, error}` — `output` is null and `error` is a
 * non-null message string exactly when that call failed. This seam iterates
 * that trace and inserts a row ONLY for entries with a non-null `error`
 * (success entries are skipped here — seam 1 already recorded them; a
 * double-count guard test pins this). `name` is already the resolved
 * ability slug (`'' !== $slug ? $slug : $name` at runner.php:580), so the
 * same namespace gate and tool_name projection apply unchanged.
 *
 * Coarse-by-design outcome for seam-2 rows: `server_error`, always. The
 * WP_Error's status/code — the thing sn_mcp_telemetry_classify_wp_error()
 * actually keys on — does not survive into `toolCalls`; only the rendered
 * message string does. Re-deriving a code from that message via
 * substring/regex matching is the exact classifier this program already
 * killed once (session 2's regex-classifier regression, documented in
 * inc/mcp/mcp-telemetry.php). A flat, documented `server_error` default is
 * the honest choice over a guessed one.
 *
 * RESIDUAL BLIND SPOT, stated plainly: `desktop_mode_agent_completed` only
 * fires when `desktop_mode_agent_runner_loop()` returns a non-WP_Error
 * `$result` (runner.php:216-224 returns early, before the action, on a
 * fatal run error — AI unavailable, rate limit, max-turns-without-answer).
 * A run that dies with one of those DOES lose its tail of already-executed
 * tool calls: their `$tool_trace` entries were built in-loop but never
 * reach either seam, because $result itself never reaches the action. This
 * is a smaller, honestly-documented gap, not a silent one — the alternative
 * (guessing at partial state from inside a fatal-error branch this file
 * does not own) was rejected as more likely to be wrong than absent.
 *
 * Contract with the runner: seam 1 is a FILTER — $output MUST come back
 * byte-identical, corrupting it breaks every agent's tool result, not just
 * telemetry. Seam 2 is an ACTION — no return value to protect, but the same
 * fail-open discipline (try/catch, never let telemetry break the run).
 *
 * Reuses inc/mcp/mcp-telemetry.php's helpers (row builder, insert, WP_Error
 * classifier, args_shape/args_hash, result_count, kill switch) rather than
 * duplicating them — this file only adds the pieces the MCP door's telemetry
 * has no equivalent for: namespace filtering (agents can call ANY registered
 * ability, not just allowlisted MCP tools) and agent-actor resolution.
 *
 * Namespace filter: only `signal-noise/*` (this plugin's own abilities) and
 * `signal-and-noise/*` (the companion theme's abilities, which this plugin's
 * own sn_site_facts consolidation already dispatches directly — see
 * CHANGELOG.md's v10.x sn_site_facts entry) are recorded. Everything else —
 * `desktop-mode/get-post` and any other plugin's abilities an agent is
 * allowed to call — is not ours to log and is silently ignored. Matching on
 * '/' position, not a shared prefix: 'signal-noise/' and 'signal-and-noise/'
 * diverge at character 7, so a naive single-prefix check misses the theme
 * half (a real bug this project has hit before, per CHANGELOG.md's
 * strpos('signal-noise/') note).
 *
 * Row shape: layer 'server' (inherited from build_row), door 'agent' (new
 * value; the `door VARCHAR(8)` column already fits it), actor
 * 'agent:<user_login>' (falls back to 'agent:#<id>' when the user can't be
 * resolved), latency_ms is always 0 — neither seam has a timing start point
 * (the runner passes no start time through either hook), and the
 * `latency_ms INT NOT NULL DEFAULT 0` column cannot hold NULL regardless;
 * storing a fabricated duration would be worse than an honest zero. See
 * docs/mcp-consolidation/FINDINGS.md for this deviation from the original
 * "leave it NULL" brief.
 *
 * Fail-open + kill switch: identical to the MCP layer, for BOTH seams. The
 * same `sn_mcp_telemetry_enabled` filter gates this file too — one switch
 * for every door's telemetry. Desktop Mode absent means neither hook ever
 * fires, which means zero cost; the require below is guarded so plugin load
 * order can never fatal if this file somehow loads before mcp-telemetry.php.
 *
 * v10.43.0 — OpenStation rename compat (WordPress/openstation PR #475, not
 * yet in any release). Both seams' hooks rename to `openstation_agent_tool_result`
 * (includes/agents/runner.php:579) and `openstation_agent_completed`
 * (includes/agents/runner.php:243) with byte-identical signatures. Both
 * callbacks are now dual-registered under old + new names via
 * inc/openstation-compat.php's snt_os_compat_add_filter()/
 * snt_os_compat_add_action(), and both write DB rows, so each guards itself
 * against a hypothetical future double-fire with snt_os_compat_seen_once() —
 * see the guard blocks inside sn_mcp_telemetry_agent_record() and
 * sn_mcp_telemetry_agent_record_completed() below.
 *
 * @package SignalNoiseTools
 * @since 10.31.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability namespaces this project vouches for. Matches the '/' boundary, not
 * a shared string prefix — 'signal-noise/' and 'signal-and-noise/' diverge at
 * character 7, so testing one does not imply the other.
 *
 * @return string[]
 */
function sn_mcp_telemetry_agent_own_namespaces() {
	return array( 'signal-noise/', 'signal-and-noise/' );
}

/**
 * True when $slug belongs to a namespace this file records telemetry for.
 *
 * @param mixed $slug
 * @return bool
 */
function sn_mcp_telemetry_agent_is_ours( $slug ) {
	if ( ! is_string( $slug ) || '' === $slug ) {
		return false;
	}
	foreach ( sn_mcp_telemetry_agent_own_namespaces() as $ns ) {
		if ( 0 === strpos( $slug, $ns ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Ability slug → tool_name column value. Reuses the MCP door's exact
 * projection (inc/mcp/mcp-tools.php's sn_mcp_tool_name_from_slug()) so the
 * same ability reads as the same tool_name whether it was called through the
 * MCP door or an agent — a fallback mirrors the same '/' → '__' mapping in
 * case load order ever puts this file ahead of mcp-tools.php.
 *
 * @param string $slug
 * @return string
 */
function sn_mcp_telemetry_agent_tool_name( $slug ) {
	if ( function_exists( 'sn_mcp_tool_name_from_slug' ) ) {
		return sn_mcp_tool_name_from_slug( $slug );
	}
	return str_replace( '/', '__', (string) $slug );
}

/**
 * Resolve the 'actor' column for an agent-attributed row: 'agent:' plus the
 * WordPress user_login of $agent_user_id, or 'agent:#<id>' when the id
 * doesn't resolve to a real user (deleted user, test fixture, bad id).
 *
 * @param mixed $agent_user_id
 * @return string
 */
function sn_mcp_telemetry_agent_actor( $agent_user_id ) {
	$id = (int) $agent_user_id;
	if ( function_exists( 'get_userdata' ) ) {
		$user = get_userdata( $id );
		if ( is_object( $user ) && isset( $user->user_login ) && is_string( $user->user_login ) && '' !== $user->user_login ) {
			return 'agent:' . $user->user_login;
		}
	}
	return 'agent:#' . $id;
}

/**
 * Classify the filter's incoming $output into {outcome, refusal_gate}. On
 * the real call site this value is never a WP_Error (runner.php only invokes
 * the filter after its own `! is_wp_error( $output )` check) — but a filter
 * chain has no exclusivity guarantee, so a WP_Error arriving here (from a
 * hostile/buggy earlier-priority callback) is still classified correctly via
 * the shared classifier instead of being coerced into a false "ok".
 *
 * @param mixed $output
 * @return array{outcome:string,refusal_gate:string|null}
 */
function sn_mcp_telemetry_agent_classify_output( $output ) {
	if ( function_exists( 'is_wp_error' ) && is_wp_error( $output ) && function_exists( 'sn_mcp_telemetry_classify_wp_error' ) ) {
		return sn_mcp_telemetry_classify_wp_error( $output );
	}
	return array(
		'outcome'      => 'ok',
		'refusal_gate' => null,
		'error_code'   => null,
	);
}

/**
 * Do the actual recording. Split from the filter callback so the callback's
 * only job is the try/catch + unconditional `return $output`; this function
 * is free to fail loudly into that boundary.
 *
 * @param mixed  $output
 * @param string $slug
 * @param mixed  $args
 * @param mixed  $agent_user_id
 * @return void
 */
function sn_mcp_telemetry_agent_record( $output, $slug, $args, $agent_user_id ) {
	if ( ! function_exists( 'sn_mcp_telemetry_enabled' ) || ! sn_mcp_telemetry_enabled() ) {
		return;
	}
	if ( ! sn_mcp_telemetry_agent_is_ours( $slug ) ) {
		return;
	}
	if ( ! function_exists( 'sn_mcp_telemetry_build_row' ) || ! function_exists( 'sn_mcp_telemetry_insert_row' ) ) {
		return;
	}

	// v10.43.0 — OpenStation double-fire guard, FAMILY-AWARE as of REJECT
	// #11 (the HIGH finding). This filter is dual-registered under both the
	// pre-rename and post-#475 hook names (see the bootstrap below +
	// inc/openstation-compat.php). The claim that used to live here — "only
	// matters if a future release ever ships both as a transition shim" —
	// was FALSE: openstation_agent_tool_result (runner.php:579) carries no
	// call_id, so two identical tool calls with byte-identical output in
	// ONE agent run are indistinguishable by payload, and the pre-#11 guard
	// silently dropped the second row TODAY, on v0.9.8, single-hook-family,
	// no shim involved. snt_os_compat_seen_once() is now family-aware: it
	// keys on the full call identity AND which literal hook name fired
	// (via current_filter()), so a same-family repeat (this call) always
	// proceeds, and only a genuine cross-family shadow of an already-
	// recorded event is suppressed.
	if ( function_exists( 'snt_os_compat_seen_once' )
		&& snt_os_compat_seen_once( 'agent_tool_result:' . md5( serialize( array( $slug, $args, $agent_user_id, $output ) ) ) ) ) {
		return;
	}

	$args_arr   = is_array( $args ) ? $args : array();
	$classified = sn_mcp_telemetry_agent_classify_output( $output );

	$result_count = null;
	if ( 'ok' === $classified['outcome'] && function_exists( 'sn_mcp_telemetry_result_count' ) ) {
		$result_count = sn_mcp_telemetry_result_count( $output );
	}

	$row = sn_mcp_telemetry_build_row(
		function_exists( 'sn_mcp_telemetry_now_ts' ) ? sn_mcp_telemetry_now_ts() : gmdate( 'Y-m-d H:i:s' ) . '.000',
		'agent',
		sn_mcp_telemetry_agent_actor( $agent_user_id ),
		sn_mcp_telemetry_agent_tool_name( $slug ),
		function_exists( 'sn_mcp_telemetry_args_shape' ) ? sn_mcp_telemetry_args_shape( $args_arr ) : '',
		function_exists( 'sn_mcp_telemetry_args_hash' ) ? sn_mcp_telemetry_args_hash( $args_arr ) : '',
		$classified['outcome'],
		$classified['refusal_gate'],
		0, // No timing seam at this call site — see file docblock.
		$result_count,
		// v13.44.0. Was a literal null since this door shipped. The agent door
		// is the DOMINANT caller of the consolidated tools, so a dimension-less
		// row here withheld most of the evidence the wave-4 retirement read
		// needs — a per-section zero meant "we never looked", not "nobody
		// called". The extractor keys BOTH name formats, and this door passes
		// the RAW slug, so no projection is needed here.
		function_exists( 'sn_mcp_telemetry_change_type' )
			? sn_mcp_telemetry_change_type( $args_arr, (string) $slug )
			: null,
		$classified['error_code'] ?? null
	);

	sn_mcp_telemetry_insert_row( $row );
	if ( function_exists( 'sn_mcp_telemetry_maybe_prune' ) ) {
		sn_mcp_telemetry_maybe_prune();
	}
}

/**
 * The filter callback. MUST return $output unchanged in every case — this is
 * a passthrough filter on a value every agent's final answer depends on.
 * Everything that can fail lives inside the try; the catch (and the
 * unconditional return after it) guarantee $output is never touched.
 *
 * @param mixed  $output        Raw ability output (or, defensively, a WP_Error).
 * @param string $slug          Ability slug, e.g. 'signal-noise/sn-scan'.
 * @param array  $args          Call arguments.
 * @param int    $agent_user_id Agent user id.
 * @return mixed $output, byte-identical to the input.
 */
function sn_mcp_telemetry_agent_tool_result( $output, $slug, $args, $agent_user_id ) {
	try {
		sn_mcp_telemetry_agent_record( $output, $slug, $args, $agent_user_id );
	} catch ( \Throwable $e ) {
		// Fail open — telemetry never breaks an agent's tool result.
	}
	return $output;
}

/* ════════════════════════════════════════════════════════════════════════
 * SEAM 2 — desktop_mode_agent_completed: the failure-visibility fix.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * True when a toolCalls entry represents a FAILED call: a non-empty string
 * 'error'. Success entries (error === null, per runner.php:583) and
 * malformed entries (missing/non-string 'error') both return false here —
 * this is the double-count guard AND the malformed-shape guard in one place.
 *
 * @param mixed $call
 * @return bool
 */
function sn_mcp_telemetry_agent_call_failed( $call ) {
	if ( ! is_array( $call ) ) {
		return false;
	}
	$error = isset( $call['error'] ) ? $call['error'] : null;
	return is_string( $error ) && '' !== $error;
}

/**
 * Record one failure row for one toolCalls entry. Split out so the caller's
 * foreach loop stays a flat guard-and-skip list.
 *
 * @param int   $agent_user_id
 * @param array $call {callId, name, args, output, error} — runner.php:578-584.
 * @return void
 */
function sn_mcp_telemetry_agent_record_completed_failure( $agent_user_id, array $call ) {
	$slug = isset( $call['name'] ) && is_string( $call['name'] ) ? $call['name'] : '';
	if ( ! sn_mcp_telemetry_agent_is_ours( $slug ) ) {
		return;
	}
	if ( ! function_exists( 'sn_mcp_telemetry_build_row' ) || ! function_exists( 'sn_mcp_telemetry_insert_row' ) ) {
		return;
	}
	$args_arr = isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array();

	$row = sn_mcp_telemetry_build_row(
		function_exists( 'sn_mcp_telemetry_now_ts' ) ? sn_mcp_telemetry_now_ts() : gmdate( 'Y-m-d H:i:s' ) . '.000',
		'agent',
		sn_mcp_telemetry_agent_actor( $agent_user_id ),
		sn_mcp_telemetry_agent_tool_name( $slug ),
		function_exists( 'sn_mcp_telemetry_args_shape' ) ? sn_mcp_telemetry_args_shape( $args_arr ) : '',
		function_exists( 'sn_mcp_telemetry_args_hash' ) ? sn_mcp_telemetry_args_hash( $args_arr ) : '',
		'server_error', // Coarse-by-design — the WP_Error status/code doesn't survive into toolCalls. See file docblock.
		null,
		0, // No timing seam here either — see file docblock.
		null, // result_count — unknowable on this path; NOT the dimension slot.
		// v13.44.0. The FAILURE path labels its dimension too: a door that only
		// labels its successes cannot answer "which section is failing?", which
		// is exactly the question a retirement read asks when a number looks bad.
		function_exists( 'sn_mcp_telemetry_change_type' )
			? sn_mcp_telemetry_change_type( $args_arr, (string) $slug )
			: null,
	);

	sn_mcp_telemetry_insert_row( $row );
	if ( function_exists( 'sn_mcp_telemetry_maybe_prune' ) ) {
		sn_mcp_telemetry_maybe_prune();
	}
}

/**
 * Walk $result['toolCalls'] and record a row for every FAILED, in-namespace
 * entry. Success entries are skipped (seam 1 already recorded them).
 * Malformed shapes — missing 'toolCalls', a non-array $result, non-array
 * entries — are silent no-ops, entry by entry where possible so one bad
 * entry doesn't drop the rest of a real trace.
 *
 * @param mixed $agent_user_id
 * @param mixed $result
 * @return void
 */
function sn_mcp_telemetry_agent_record_completed( $agent_user_id, $result ) {
	if ( ! function_exists( 'sn_mcp_telemetry_enabled' ) || ! sn_mcp_telemetry_enabled() ) {
		return;
	}
	if ( ! is_array( $result ) || ! isset( $result['toolCalls'] ) || ! is_array( $result['toolCalls'] ) ) {
		return;
	}

	// v10.43.0 — OpenStation double-fire guard, FAMILY-AWARE as of REJECT
	// #11. Keys the WHOLE toolCalls trace once per (agent, result, hook
	// family) so a genuine future double-fire of desktop_mode_agent_completed
	// / openstation_agent_completed for the SAME run does not replay every
	// failure row twice — while a same-family repeat of an identical
	// (agent, result) pair (a real, legitimate case: Copilot's $request_id
	// is per-RUN, so the SAME trace can legitimately recur) still records
	// every time. See inc/openstation-compat.php.
	if ( function_exists( 'snt_os_compat_seen_once' )
		&& snt_os_compat_seen_once( 'agent_completed:' . (int) $agent_user_id . ':' . md5( serialize( $result ) ) ) ) {
		return;
	}

	$id = (int) $agent_user_id;
	foreach ( $result['toolCalls'] as $call ) {
		if ( ! sn_mcp_telemetry_agent_call_failed( $call ) ) {
			continue;
		}
		sn_mcp_telemetry_agent_record_completed_failure( $id, $call );
	}
}

/**
 * The action callback. No return value to protect (unlike seam 1's filter),
 * but the same fail-open discipline: telemetry must never break — or even
 * be visible to — the agent run it's observing.
 *
 * @param mixed $agent_user_id
 * @param mixed $message       Unused — kept in the signature to match the hook.
 * @param mixed $result        `{ text, callToActions, toolCalls, turns }`.
 * @param mixed $context       Unused — kept in the signature to match the hook.
 * @return void
 */
function sn_mcp_telemetry_agent_completed( $agent_user_id, $message, $result, $context ) {
	try {
		sn_mcp_telemetry_agent_record_completed( $agent_user_id, $result );
	} catch ( \Throwable $e ) {
		// Fail open.
	}
}

/**
 * Wire both seams at a late priority (PHP_INT_MAX — same convention this
 * project uses for `desktop_mode_ai_tools` schema normalization) so this
 * runs after any other plugin's own callbacks on the same hooks. We only
 * ever read/log, never mutate, so ordering doesn't change what we record —
 * running last just keeps us out of the way of anything that DOES mutate
 * $output on seam 1.
 *
 * Desktop Mode absent means neither hook ever fires — zero cost, and the
 * guard means this file can safely load in any order relative to Desktop
 * Mode itself.
 *
 * @return void
 */
function sn_mcp_telemetry_agents_bootstrap() {
	if ( ! function_exists( 'add_filter' ) ) {
		return;
	}
	// v10.43.0: dual-registered under both the pre-#475 `desktop_mode_*` name
	// and the post-#475 `openstation_*` name via snt_os_compat_add_filter()/
	// snt_os_compat_add_action() (inc/openstation-compat.php) — see the
	// double-fire guards inside sn_mcp_telemetry_agent_record() and
	// sn_mcp_telemetry_agent_record_completed() above.
	if ( function_exists( 'snt_os_compat_add_filter' ) ) {
		snt_os_compat_add_filter( 'desktop_mode_agent_tool_result', 'openstation_agent_tool_result', 'sn_mcp_telemetry_agent_tool_result', PHP_INT_MAX, 4 );
	} else {
		add_filter( 'desktop_mode_agent_tool_result', 'sn_mcp_telemetry_agent_tool_result', PHP_INT_MAX, 4 );
	}
	if ( function_exists( 'add_action' ) ) {
		if ( function_exists( 'snt_os_compat_add_action' ) ) {
			snt_os_compat_add_action( 'desktop_mode_agent_completed', 'openstation_agent_completed', 'sn_mcp_telemetry_agent_completed', PHP_INT_MAX, 4 );
		} else {
			add_action( 'desktop_mode_agent_completed', 'sn_mcp_telemetry_agent_completed', PHP_INT_MAX, 4 );
		}
	}
}
sn_mcp_telemetry_agents_bootstrap();
