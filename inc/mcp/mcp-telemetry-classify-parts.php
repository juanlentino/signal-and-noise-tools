<?php
/**
 * Signal & Noise: MCP Layer B telemetry, the WP_Error classifier (status-less schema codes, throttle codes) and the row builder.
 *
 * Split from inc/mcp/mcp-telemetry.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status-less WP_Error codes that a multi-line-aware sweep of every
 * `new WP_Error(` construction under inc/ (196 total, run via a
 * paren-balanced perl scan — a plain single-line grep silently drops any
 * construction whose args span multiple lines, which is most of them) proved
 * are (a) missing an array('status'=>N) data payload, (b) reachable as an
 * ability's execute() return value, AND (c) a CALLER-argument problem rather
 * than a server-side failure. Status-less constructions in inc/webhooks.php,
 * inc/muso-api.php, inc/uptime-status.php, and inc/wp-update-integration.php
 * are unreachable via any ability (no execute_callback calls into them and
 * propagates the raw WP_Error). The insights/narration AI-response family IS
 * reachable raw — signal-noise/run-insights-scan and run-narration propagate
 * snt_insights_encode_failed, snt_insights_invalid_json,
 * snt_narration_encode_failed, snt_narration_invalid_json,
 * snt_narration_no_headline, and snt_narration_no_body straight through
 * (inc/insights.php:758-765 and the narration equivalent) — but every one of
 * those is a genuine server/AI-side failure (JSON-encode failure, unparseable
 * or incomplete AI output), so the classifier's status-less default of
 * server_error is CORRECT for them and they are deliberately absent from
 * this list. The only status-less caller-argument code found:
 * 'sn_tag_not_unused' (inc/tag-consolidation.php:376, inside
 * sn_tag_delete_unused()), propagated raw by signal-noise/prune-unused-tags
 * (inc/abilities-content.php's snt_ability_prune_unused_tags(), the
 * `if ( is_wp_error( $res ) ) { return $res; }` tail) — a caller-supplied
 * tag id that turned out non-empty since the scan. If you add a status-less
 * WP_Error that an ability propagates and it is the caller's fault, it
 * belongs HERE; if it is the server's fault, the default already handles it.
 *
 * @return string[]
 */
function sn_mcp_telemetry_status_less_schema_codes() {
	return array( 'sn_tag_not_unused' );
}

/**
 * Status-429 codes that are NOT the rw door's own rate-limit gate (that
 * case is classified directly in sn_mcp_call_tool(), before execute() is
 * even reached) but still represent a throttle/cap an ability itself
 * enforces. The sweep found exactly one: 'snt_surfaces_throttled'
 * (inc/abilities-update-post-surfaces.php:162, a per-post write-cap, status
 * 429 — the ONLY status-429 construction under inc/ today).
 *
 * @return string[]
 */
function sn_mcp_telemetry_throttle_codes() {
	return array( 'snt_surfaces_throttled' );
}

/**
 * Classify an execute() WP_Error, status-first — NOT string/substring
 * matching on the error code (a prior version of this function used a
 * code-name regex grounded on a single-line `grep`, which silently missed
 * every multi-line `new WP_Error(` construction — e.g.
 * snt_cron_args_signature_requires_hook, snt_sn_hook_refused,
 * snt_surfaces_nothing_to_write, snt_surfaces_too_long,
 * snt_surfaces_throttled all span multiple lines and were invisible to it;
 * and 'missing' as a substring wrongly caught snt_impl_missing, a genuine
 * HTTP-500 server condition, as a schema error).
 *
 * Nearly every ability-reachable WP_Error in this codebase carries
 * array('status'=>N) as its error_data (paren-balanced sweep of all 196
 * `new WP_Error(` constructions under inc/: 168 carry a status, 28 don't;
 * of the status-less ones, the reachable set is sn_tag_not_unused — the one
 * caller-argument case, mapped explicitly below — plus the insights/
 * narration AI-response failures, which the server_error default classifies
 * correctly; see sn_mcp_telemetry_status_less_schema_codes()'s docblock for
 * the full accounting). get_error_data() with no argument returns the
 * FIRST registered code's data, which is exactly what WP_Error's own
 * add()/get_error_data() implementation does and matches how every ability
 * here constructs its error (one code per WP_Error).
 *
 *   - status 429                        -> refused (refusal_gate 'rate_limit',
 *                                          or 'write_throttle' for the codes
 *                                          in sn_mcp_telemetry_throttle_codes()).
 *   - status 400-428 or 431-499         -> schema_error (a 4xx band is, by
 *                                          construction, the ability
 *                                          rejecting what the CALLER supplied).
 *   - status >= 500                     -> server_error.
 *   - no status, code in the status-less
 *     schema list (see above)           -> schema_error.
 *   - no status, any other code         -> server_error (honest default: an
 *                                          unexplained failure is not proven
 *                                          to be the caller's fault).
 *
 * v13.48.0: the return also carries `error_detail` and `error_status`, the two
 * diagnostic-only fields. They ride along here rather than being fetched again
 * at each call site because this function is the one place that still holds the
 * real WP_Error object — downstream, the result has already been flattened to
 * message-only, which is the same reason error_code was captured here.
 * Neither field influences the classification.
 *
 * @param mixed $error A WP_Error (or test stand-in) exposing get_error_code()
 *                      and get_error_data().
 * @return array{outcome:string,refusal_gate:string|null,error_code:string|null,error_detail:string|null,error_status:int|null}
 */
function sn_mcp_telemetry_classify_wp_error( $error ) {
	$code   = sn_mcp_telemetry_error_code( $error );
	$detail = sn_mcp_telemetry_error_detail( $error );
	$data   = ( is_object( $error ) && method_exists( $error, 'get_error_data' ) )
		? $error->get_error_data()
		: null;
	$status = ( is_array( $data ) && isset( $data['status'] ) && is_numeric( $data['status'] ) )
		? (int) $data['status']
		: null;
	$error_status = sn_mcp_telemetry_error_status_allowed( $status );

	/**
	 * Every return below carries the same diagnostic pair, so a new outcome
	 * branch cannot silently drop them.
	 *
	 * @param string      $outcome
	 * @param string|null $gate
	 * @return array<string,mixed>
	 */
	$verdict = static function ( $outcome, $gate ) use ( $code, $detail, $error_status ) {
		return array(
			'outcome'      => $outcome,
			'refusal_gate' => $gate,
			'error_code'   => $code,
			'error_detail' => $detail,
			'error_status' => $error_status,
		);
	};

	if ( null !== $status ) {
		if ( 429 === $status ) {
			$gate = in_array( $code, sn_mcp_telemetry_throttle_codes(), true ) ? 'write_throttle' : 'rate_limit';
			return $verdict( 'refused', $gate );
		}
		// v11.8.0: 409 is OPTIMISTIC-CONCURRENCY contention, not malformed
		// input, and must be split out BEFORE the 4xx band that used to
		// swallow it. A paren-balanced (perl -0777) sweep of every
		// `new WP_Error(` under inc/ found 24 constructions carrying
		// array('status'=>409) — the whole apply family's stale-state surface
		// (fingerprint mismatch, anchor moved, phrase moved, revision belongs
		// to another post, idempotency key reused against another target) plus
		// ai-orphan-suggest's TOCTOU re-check. Filed as schema_error they were
		// indistinguishable from a caller typo, which made the contention rate
		// — the primary signal for whether a fingerprint's granularity is
		// right — unreadable. No refusal_gate: a conflict is not a gate
		// refusal, it is a lost race.
		if ( 409 === $status ) {
			return $verdict( 'conflict', null );
		}
		if ( ( $status >= 400 && $status <= 428 ) || ( $status >= 431 && $status <= 499 ) ) {
			return $verdict( 'schema_error', null );
		}
		if ( $status >= 500 ) {
			return $verdict( 'server_error', null );
		}
	}

	if ( in_array( $code, sn_mcp_telemetry_status_less_schema_codes(), true ) ) {
		return $verdict( 'schema_error', null );
	}

	return $verdict( 'server_error', null );
}

/**
 * PURE row builder — every piece of state is a parameter, mirroring
 * inc/mcp/mcp-rw-audit.php's sn_mcp_rw_audit_build_row() split so this is
 * directly unit-testable without a WP bootstrap.
 *
 * @param string      $ts           'Y-m-d H:i:s.vvv' UTC.
 * @param string      $door         'read' | 'rw'.
 * @param string      $actor        'human' | 'app-pw:<8 chars>' today. 'routine:<name>'
 *                                  is RESERVED for a later phase (no code path
 *                                  produces it yet — see sn_mcp_telemetry_actor()).
 * @param string      $tool_name
 * @param string      $args_shape
 * @param string      $args_hash
 * @param string      $outcome      'ok'|'schema_error'|'conflict'|'not_found'|'refused'|'server_error'.
 * @param string|null $refusal_gate
 * @param int         $latency_ms
 * @param int|null    $result_count
 * @param string|null $change_type  Allowlisted sn-apply change.type, or null.
 *                                  Appended last so existing positional callers
 *                                  keep working. @since v11.8.0.
 * @param string|null $error_code   Allowlisted WP_Error code captured by the
 *                                  classifier, or null.
 * @param string|null $dimensions   Sorted comma-joined section list, or null.
 * @param string|null $error_detail DIAGNOSTIC ONLY — the WP_Error's message,
 *                                  bounded to 255 bytes. Never aggregated.
 *                                  Appended last, so existing positional
 *                                  callers keep working. @since v13.48.0.
 * @param int|null    $error_status DIAGNOSTIC ONLY — the WP_Error's status.
 *                                  Never aggregated. @since v13.48.0.
 * @return array<string,mixed>
 */
function sn_mcp_telemetry_build_row( $ts, $door, $actor, $tool_name, $args_shape, $args_hash, $outcome, $refusal_gate, $latency_ms, $result_count, $change_type = null, $error_code = null, $dimensions = null, $error_detail = null, $error_status = null ) {
	return array(
		'ts'           => (string) $ts,
		'layer'        => 'server',
		'door'         => (string) $door,
		'actor'        => (string) $actor,
		'tool_name'    => (string) $tool_name,
		'args_shape'   => (string) $args_shape,
		'args_hash'    => (string) $args_hash,
		'outcome'      => (string) $outcome,
		'refusal_gate' => null === $refusal_gate ? null : (string) $refusal_gate,
		'latency_ms'   => (int) $latency_ms,
		'result_count' => null === $result_count ? null : (int) $result_count,
		'candidate_id' => null, // Reserved; always NULL this session.
		// Persist-choke bound (review LOW): the column is VARCHAR(32); a
		// longer identifier must resolve to NULL here, never silent SQL
		// truncation — the same belt the error_code grammar re-applies.
		'change_type'  => ( null === $change_type || strlen( (string) $change_type ) > 32 ) ? null : (string) $change_type,
		// v13.46.0. Same refuse-rather-than-cut rule as change_type above.
		'dimensions'   => ( null === $dimensions || strlen( (string) $dimensions ) > SN_MCP_TELEMETRY_DIMENSIONS_MAX ) ? null : (string) $dimensions,
		'error_code'   => 'ok' === $outcome ? null : sn_mcp_telemetry_error_code_allowed( $error_code ),
		// v13.48.0, diagnostic-only pair. Same success-forces-NULL rule as
		// error_code: a row that did not fail has nothing to explain, so an
		// internal caller cannot attach prose to a successful call. The 255
		// bound is re-applied here rather than trusted from the extractor,
		// because build_row() is the persist choke point and a future caller
		// may pass a raw string positionally.
		'error_detail' => ( 'ok' === $outcome || ! is_string( $error_detail ) || '' === $error_detail )
			? null
			: substr( $error_detail, 0, SN_MCP_TELEMETRY_ERROR_DETAIL_MAX ),
		'error_status' => 'ok' === $outcome ? null : sn_mcp_telemetry_error_status_allowed( $error_status ),
	);
}
