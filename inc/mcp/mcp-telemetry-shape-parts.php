<?php
/**
 * Signal & Noise: MCP Layer B telemetry, the pure call-shape helpers (elapsed, args shape and hash, change type, dimensions, error code, detail and status, result count).
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

/* ════════════════════════════════════════════════════════════════════════
 * Pure helpers — no get_option()/time()/global $wpdb inside any of these.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Elapsed milliseconds since a microtime(true) start mark. Tiny pure helper
 * so every sn_mcp_call_tool() return point can compute latency identically.
 *
 * @param float $t0
 * @return int
 */
function sn_mcp_telemetry_elapsed_ms( $t0 ) {
	return (int) round( ( microtime( true ) - (float) $t0 ) * 1000 );
}

/**
 * Sorted, comma-joined, truncated top-level argument keys. Never a value.
 *
 * @param mixed $args
 * @return string
 */
function sn_mcp_telemetry_args_shape( $args ) {
	if ( ! is_array( $args ) || empty( $args ) ) {
		return '';
	}
	$keys = array_map( 'strval', array_keys( $args ) );
	sort( $keys );
	return substr( implode( ',', $keys ), 0, 255 );
}

/**
 * The ONE nested value this module records, and the only exception to the
 * "top-level keys, never a value" rule above (v11.8.0).
 *
 * WHY THE EXCEPTION EXISTS: args_shape captures top-level keys only, so every
 * sn-apply call recorded the identical shape `change,dry_run,idempotency_key,
 * mode,target` regardless of what it actually did. link_reshape, unlink,
 * create_draft, og_card and the rest were indistinguishable in telemetry, which
 * meant the consolidation programme's aggregate sn-apply count could never
 * justify retiring or keeping any individual change type — and the most
 * destructive surface in the system was the least observable.
 *
 * WHY IT IS SAFE: this is an ALLOWLIST, never a passthrough. The value is
 * returned only when it is a member of SNT_SN_APPLY_CHANGE_TYPES — a closed,
 * schema-fixed enum of identifiers that carry no user content and have bounded
 * cardinality. An arbitrary caller string (or a secret pasted into the field)
 * resolves to NULL and is never stored. Sourcing the allowlist from the
 * registration constant rather than a local copy means a type added there is
 * picked up automatically and cannot drift.
 *
 * Guarded with defined(): telemetry is fail-open and must never depend on
 * another module's load order.
 *
 * @param mixed $args The raw tool arguments.
 * @return string|null An allowlisted change type, or null.
 */
function sn_mcp_telemetry_change_type( $args, $tool_name = '' ) {
	if ( is_array( $args ) && isset( $args['change'] ) && is_array( $args['change'] ) ) {
		$type = $args['change']['type'] ?? null;
		if ( is_string( $type ) && '' !== $type ) {
			$allowed = defined( 'SNT_SN_APPLY_CHANGE_TYPES' ) ? (array) constant( 'SNT_SN_APPLY_CHANGE_TYPES' ) : array();
			return in_array( $type, $allowed, true ) ? $type : null;
		}
		return null;
	}

	// v13.3.0 — the same per-dimension observability for the batch READ
	// tools (sn-status / sn-metrics / sn-site-facts), the v11.8.0 rationale
	// verbatim: their aggregate rows could never justify retiring or keeping
	// an INDIVIDUAL section or fact (the wave-4 retirement sheet's exact
	// need). When a call requests EXACTLY ONE section/fact, record it —
	// allowlisted against the tool's own registration map, sourced live
	// (never a local copy) so a section added there is picked up
	// automatically. Multi-entry calls record NULL: the aggregate row is
	// honest; a fabricated "first of three" dimension is not. Every value in
	// those maps is a schema-fixed identifier with bounded cardinality and
	// fits the column's VARCHAR(32).
	// Keyed by BOTH name formats a recorder passes (review MEDIUM): the MCP
	// door records the projected tool name (slug with '/'→'__'), while the
	// lifecycle guard's 'direct' door (WP's native Abilities REST surface)
	// records the raw ability slug. Missing the second format would silently
	// drop every direct-door single-fact read from the dimension — recorded
	// NULL, indistinguishable from a multi-entry call. The agent door builds
	// its rows without this extractor and stays dimension-less (pre-existing,
	// sn-apply included).
	$source = sn_mcp_telemetry_dimension_source( (string) $tool_name );
	if ( null === $source || ! is_array( $args ) || ! function_exists( $source['map'] ) ) {
		return null;
	}
	$requested = $args[ $source['key'] ] ?? null;
	if ( ! is_array( $requested ) ) {
		return null;
	}
	// is_string filter FIRST (review LOW): extraction runs on raw,
	// pre-validation args, and strval over a nested-array entry would warn.
	$requested = array_values( array_unique( array_filter( $requested, 'is_string' ) ) );
	if ( 1 !== count( $requested ) ) {
		return null;
	}
	$allowed = array_keys( (array) call_user_func( $source['map'] ) );
	return in_array( $requested[0], $allowed, true ) ? $requested[0] : null;
}

/**
 * The batch-read tools' dimension source, keyed by BOTH name formats a recorder
 * passes: the MCP door records the projected name (slug with '/'->'__'), the
 * lifecycle guard's 'direct' door and the agent door record the raw slug.
 *
 * ONE map, shared by change_type and dimensions. A second copy is precisely the
 * drift this file already warns about elsewhere — two extractors disagreeing
 * about which sections exist would be invisible in the data.
 *
 * @since 13.46.0
 * @param string $tool_name
 * @return array{key:string,map:string}|null
 */
function sn_mcp_telemetry_dimension_source( $tool_name ) {
	$sources = array(
		'signal-noise__sn-status'     => array( 'key' => 'sections', 'map' => 'snt_sn_status_map' ),
		'signal-noise/sn-status'      => array( 'key' => 'sections', 'map' => 'snt_sn_status_map' ),
		'signal-noise__sn-metrics'    => array( 'key' => 'sections', 'map' => 'snt_sn_metrics_map' ),
		'signal-noise/sn-metrics'     => array( 'key' => 'sections', 'map' => 'snt_sn_metrics_map' ),
		'signal-noise__sn-site-facts' => array( 'key' => 'facts', 'map' => 'snt_sn_site_facts_map' ),
		'signal-noise/sn-site-facts'  => array( 'key' => 'facts', 'map' => 'snt_sn_site_facts_map' ),
	);
	return $sources[ (string) $tool_name ] ?? null;
}

/** Column width for `dimensions`. Overflow records NULL, never a cut list. */
const SN_MCP_TELEMETRY_DIMENSIONS_MAX = 512;

/**
 * EVERY requested section/fact, sorted, comma-joined — the companion to
 * sn_mcp_telemetry_change_type()'s single-entry answer.
 *
 * WHY BOTH EXIST. change_type deliberately returns null for a multi-entry call:
 * a fabricated "first of three" dimension is not honest. That is right, and it
 * means the more the consolidated tools are used AS DESIGNED — batched — the
 * blinder the per-section record gets. v13.44.0 added six sections, so the
 * blindness scales with the feature. Rather than weaken an honest rule, this
 * answers the OTHER question. change_type: "was the SOLE dimension of N calls".
 * dimensions: "APPEARED IN N calls". Neither fabricates; neither replaces.
 *
 * SORTED, always — unsorted, one pair arrives under two spellings and a
 * by_dimension rollup silently disagrees with itself.
 *
 * OVERFLOW IS NULL. change_type already refuses a value wider than its column
 * rather than cutting it; a half-list misrepresents what was requested, which is
 * worse than recording nothing.
 *
 * @since 13.46.0
 * @param array  $args
 * @param string $tool_name
 * @return string|null
 */
function sn_mcp_telemetry_dimensions( $args, $tool_name = '' ) {
	$source = sn_mcp_telemetry_dimension_source( (string) $tool_name );
	if ( null === $source || ! is_array( $args ) || ! function_exists( $source['map'] ) ) {
		return null;
	}
	$requested = $args[ $source['key'] ] ?? null;
	if ( ! is_array( $requested ) ) {
		return null;
	}
	$allowed   = array_keys( (array) call_user_func( $source['map'] ) );
	$requested = array_values( array_unique( array_filter( $requested, 'is_string' ) ) );
	$kept      = array_values( array_intersect( $requested, $allowed ) );
	if ( empty( $kept ) ) {
		return null;
	}
	sort( $kept );
	$joined = implode( ',', $kept );
	return strlen( $joined ) > SN_MCP_TELEMETRY_DIMENSIONS_MAX ? null : $joined;
}

/**
 * Error-code identifier captured from the real WP_Error, at classification.
 *
 * Like change_type, this is an ALLOWLIST, never an arbitrary passthrough. The
 * error-code surface has no central enum equivalent to
 * SNT_SN_APPLY_CHANGE_TYPES, so its allowlist is the WordPress identifier
 * grammar plus the column's 64-byte bound. The input is the WP_Error object,
 * not caller arguments or a later message-only result; values containing
 * spaces, punctuation, or other content-bearing syntax resolve to NULL.
 *
 * @param mixed $error A WP_Error (or test stand-in) exposing get_error_code().
 * @return string|null An allowlisted error-code identifier, or null.
 */
function sn_mcp_telemetry_error_code( $error ) {
	if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_code' ) ) {
		return null;
	}
	return sn_mcp_telemetry_error_code_allowed( $error->get_error_code() );
}

/**
 * The identifier grammar itself, callable on a bare string. build_row()
 * re-applies it so the allowlist holds at the persist choke point too, not
 * only at the classify site — a future caller passing an unfiltered string
 * positionally must not be able to put user content in the column.
 *
 * @param mixed $code Candidate error-code value.
 * @return string|null The code if it passes the grammar, else null.
 */
function sn_mcp_telemetry_error_code_allowed( $code ) {
	if ( ! is_string( $code ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $code ) ) {
		return null;
	}
	return $code;
}

/**
 * The WP_Error's own message, truncated to the column bound — DIAGNOSTIC ONLY.
 *
 * WHY THIS IS NOT A SECOND error_code: error_code is the aggregation
 * dimension, and it stays a bounded identifier grammar precisely so a GROUP BY
 * over it can never fan out into free text. This column is the opposite kind of
 * thing. It is unbounded provider/plugin prose, it has no grammar, and it must
 * never appear in a GROUP BY, a WHERE, or the tool_telemetry rollup. Read it
 * per-row when investigating one failure; aggregate on error_code, always.
 *
 * WHAT IT BUYS: WP 7.1 core's WP_AI_Client_Prompt_Builder::exception_to_wp_error()
 * returns the provider's actual message alongside the code, and until v13.48.0
 * this layer discarded it — a `prompt_client_error` row recorded that a 4xx
 * happened and threw away the sentence saying which parameter the provider
 * rejected. That is the difference between a countable event and a debuggable
 * one.
 *
 * TRUNCATION, not refusal: change_type and dimensions refuse rather than cut,
 * because a half-list misrepresents a bounded set. A message is prose, and its
 * first 255 bytes are still true — the leading clause of a provider error is
 * where the cause lives. mb_substr() (when available) keeps the cut off a
 * multibyte boundary so the column never receives a broken UTF-8 tail; the
 * bound is applied in BYTES afterwards because the column is VARCHAR(255) and
 * the storage limit is what has to hold.
 *
 * @since 13.48.0
 * @param mixed $error A WP_Error (or test stand-in) exposing get_error_message().
 * @return string|null The message, bounded to 255 bytes, or null when absent.
 */
function sn_mcp_telemetry_error_detail( $error ) {
	if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_message' ) ) {
		return null;
	}
	$message = $error->get_error_message();
	if ( ! is_string( $message ) ) {
		return null;
	}
	$message = sn_mcp_telemetry_unwrap_envelope_message( trim( $message ) );
	if ( '' === $message ) {
		return null;
	}
	if ( strlen( $message ) <= SN_MCP_TELEMETRY_ERROR_DETAIL_MAX ) {
		return $message;
	}
	if ( function_exists( 'mb_substr' ) ) {
		// Cut on a CHARACTER boundary first, then re-check the BYTE bound: a
		// 255-character cut can still exceed 255 bytes in multibyte prose, and
		// the column bound is bytes.
		$cut = mb_substr( $message, 0, SN_MCP_TELEMETRY_ERROR_DETAIL_MAX );
		while ( '' !== $cut && strlen( $cut ) > SN_MCP_TELEMETRY_ERROR_DETAIL_MAX ) {
			$cut = mb_substr( $cut, 0, mb_strlen( $cut ) - 1 );
		}
		return '' === $cut ? null : $cut;
	}
	return substr( $message, 0, SN_MCP_TELEMETRY_ERROR_DETAIL_MAX );
}

/**
 * Prefer an envelope's INNER message over its JSON scaffolding.
 *
 * WHY THIS EXISTS, measured: `sn-apply` does not return prose. On a single
 * target it returns `new WP_Error( $code, wp_json_encode( $response ), … )`
 * (inc/abilities-sn-apply.php:312) — the message IS the whole response object,
 * and the human-readable reason lives at `error.message`, which the shape puts
 * LAST, after four nested `gates` sub-arrays. A realistic refusal envelope
 * measures 486+ bytes with the reason starting at byte 348, so a flat 255-byte
 * cut keeps `{"target":{...},"change_type":"dismiss","mode":"publish",…` and
 * discards the sentence naming what was refused.
 *
 * That is not a hypothetical loss. The consolidation programme decides which
 * `sn-apply` change types to retire from telemetry, `sn-apply` is its most
 * measured surface, and v13.47.0's `dismiss` refuses BY NAME for six of nine
 * scan types precisely so a caller learns why. Storing the envelope head would
 * have recorded that a refusal happened and thrown away which surface it named.
 *
 * BOUNDED, never a general JSON walk: the message must start with `{`, decode
 * within a shallow depth, and expose a non-empty string at `error.message`.
 * Anything else returns the input untouched, so provider prose and every
 * ordinary WP_Error message are unaffected.
 *
 * @since 13.48.0
 * @param string $message Raw WP_Error message.
 * @return string The inner message when the input is a recognised envelope,
 *                else the input unchanged.
 */
function sn_mcp_telemetry_unwrap_envelope_message( $message ) {
	if ( ! is_string( $message ) || '' === $message || '{' !== $message[0] ) {
		return (string) $message;
	}
	// A pathological length is not worth decoding; the head is as good as
	// anything at that size, and this keeps the work bounded on the hot path.
	if ( strlen( $message ) > SN_MCP_TELEMETRY_ENVELOPE_DECODE_MAX ) {
		return $message;
	}
	$decoded = json_decode( $message, true, 8 );
	if ( ! is_array( $decoded ) || ! isset( $decoded['error']['message'] ) ) {
		return $message;
	}
	$inner = $decoded['error']['message'];
	if ( ! is_string( $inner ) ) {
		return $message;
	}
	$inner = trim( $inner );
	return '' === $inner ? $message : $inner;
}

/**
 * The status bound itself, callable on a bare value so build_row() can
 * re-apply it at the persist choke point — the same belt-and-braces the
 * error_code grammar already gets.
 *
 * @since 13.48.0
 * @param mixed $status Candidate status value.
 * @return int|null
 */
function sn_mcp_telemetry_error_status_allowed( $status ) {
	if ( ! is_numeric( $status ) ) {
		return null;
	}
	$status = (int) $status;
	if ( $status < 100 || $status > 599 ) {
		return null;
	}
	return $status;
}

/**
 * sha256 of the JSON-encoded arguments. Never reversible into the source
 * values by design (session 1's corrected deviation from the spec's original
 * base64 prefix — see docs/mcp-consolidation/FINDINGS.md #0).
 *
 * @param mixed $args
 * @return string
 */
function sn_mcp_telemetry_args_hash( $args ) {
	$clean = is_array( $args ) ? $args : array();
	$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $clean ) : json_encode( $clean );
	return hash( 'sha256', (string) $json );
}

/**
 * Trivial top-level result count: only when the ability's raw output is a
 * plain (list-shaped) PHP array — never introspects nested shapes. Anything
 * else (assoc array, scalar, object, null) is NULL, not guessed.
 *
 * @param mixed $out The ability's raw execute() output (pre-wrap).
 * @return int|null
 */
function sn_mcp_telemetry_result_count( $out ) {
	if ( ! is_array( $out ) ) {
		return null;
	}
	if ( function_exists( 'array_is_list' ) ) {
		return array_is_list( $out ) ? count( $out ) : null;
	}
	// Defensive fallback only — composer.json requires php>=8.3, so
	// array_is_list() (8.1+) is always available in practice.
	return array_keys( $out ) === range( 0, count( $out ) - 1 ) ? count( $out ) : null;
}
