<?php
/**
 * Signal & Noise Tools — Anthropic prompt-cache probe (read-only).
 *
 * Answers one question with evidence instead of estimates: would Anthropic
 * prompt caching pay on this site, and where?
 *
 * WHY A WP HTTP HOOK AND NOT THE AI CLIENT
 * The WP AI Client's TokenUsage DTO cannot answer it. Verified against
 * WordPress/anthropic-ai-provider trunk (AnthropicTextGenerationModel.php,
 * parseResponseToGenerativeAiResult): the provider reads Anthropic's
 * `cache_creation_input_tokens` and `cache_read_input_tokens` and then SUMS
 * them into a single `inputTokens` figure. The split is destroyed before any
 * caller sees it — so snt_ai_record_usage() structurally cannot report cache
 * behaviour, and (if caching were ever enabled) would price cache reads at
 * the full input rate, over-reporting spend and mis-firing the budget cap.
 *
 * The split survives one layer lower. Verified against WordPress/wp-ai-client
 * trunk (includes/HTTP/WordPress_HTTP_Client.php:64): the AI Client's PSR-18
 * transporter is `wp_remote_request()`. Every Anthropic call on this site —
 * ours AND any other plugin's, including OpenStation/Desktop Mode's Copilot
 * and agent turns, which route through wp_ai_client_prompt() too — therefore
 * passes through core's `http_response` filter with the raw request body in
 * $args['body'] and the raw response body in $response.
 *
 * WHAT IT RECORDS (and deliberately does not)
 * Sizes, counts, a prefix fingerprint, and the token figures Anthropic
 * returns. NEVER prompt text, system instructions, tool schemas, or any
 * response content — the log must stay safe to read, export, and screenshot,
 * and must not balloon the options table.
 *
 * `prefix_hash` is the load-bearing field. Anthropic renders the prompt as
 * tools → system → messages and caching is a prefix match, so the hash of
 * (model, tools, system) IS the cache-key identity: two entries sharing a
 * prefix_hash inside the TTL are a cache hit that did not happen. Repeat rate
 * is the unknown a size measurement alone cannot settle.
 *
 * READ-ONLY BY CONSTRUCTION: the filter returns $response untouched on every
 * path, and nothing here mutates a request. Enabling caching is a separate,
 * later decision that this data exists to inform.
 *
 * @package SignalNoiseTools
 * @since 10.50.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capped FIFO option holding probe entries. Separate from SN_AI_USAGE_LOG_OPT
 * because it spans traffic SN does not originate (Copilot turns), carries a
 * different shape, and must never be confused with the spend ledger.
 */
define( 'SN_AI_CACHE_PROBE_OPT', 'sn_ai_cache_probe' );
define( 'SN_AI_CACHE_PROBE_CAP', 200 );

/**
 * Window, in seconds, inside which two identical prefixes would have been a
 * cache hit. Anthropic's default cache TTL is 5 minutes.
 */
define( 'SN_AI_CACHE_PROBE_TTL', 300 );

add_filter( 'http_response', 'snt_ai_cache_probe_record', 10, 3 );

/**
 * Record one Anthropic Messages API call's cache-relevant shape.
 *
 * Runs on every HTTP response site-wide, so the non-Anthropic bail is the
 * first statement and costs one strpos().
 *
 * @since 10.50.0
 *
 * @param array|WP_Error $response HTTP response array (or WP_Error).
 * @param array          $args     Request args, including the JSON body string.
 * @param string         $url      Request URL.
 * @return array|WP_Error The response, always untouched.
 */
function snt_ai_cache_probe_record( $response, $args, $url ) {
	// Fast bail — the overwhelming majority of site HTTP traffic ends here.
	if ( ! is_string( $url ) || false === strpos( $url, 'api.anthropic.com' ) ) {
		return $response;
	}

	// Exact host + endpoint match: strpos alone would also catch a URL that
	// merely mentions the host (a webhook payload, a proxied callback).
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || 'api.anthropic.com' !== ( $parts['host'] ?? '' ) ) {
		return $response;
	}
	if ( false === strpos( (string) ( $parts['path'] ?? '' ), '/v1/messages' ) ) {
		return $response;
	}

	// Kill switch, evaluated only for calls we would actually record so the
	// filter is not fired for every HTTP request the site makes.
	if ( ! apply_filters( 'snt_ai_cache_probe_enabled', true ) ) {
		return $response;
	}

	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return $response;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		// Non-200 carries no usage object; there is nothing to measure and a
		// zero row would read as "measured, no cache" rather than "not measured".
		return $response;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) ) {
		return $response;
	}

	$request = array();
	if ( isset( $args['body'] ) && is_string( $args['body'] ) ) {
		$decoded = json_decode( $args['body'], true );
		if ( is_array( $decoded ) ) {
			$request = $decoded;
		}
	}

	snt_ai_cache_probe_append( snt_ai_cache_probe_entry( $request, $body ) );

	return $response;
}

/**
 * Build one probe entry from a decoded request/response pair.
 *
 * Split out from the filter so the shaping logic is testable without an HTTP
 * round trip, and so a malformed payload degrades to a partial row rather
 * than a missing one.
 *
 * @since 10.50.0
 *
 * @param array $request  Decoded request body (may be empty).
 * @param array $response Decoded response body.
 * @return array Probe entry.
 */
function snt_ai_cache_probe_entry( $request, $response ) {
	$request  = is_array( $request ) ? $request : array();
	$response = is_array( $response ) ? $response : array();

	$tools    = isset( $request['tools'] ) && is_array( $request['tools'] ) ? $request['tools'] : array();
	$system   = $request['system'] ?? null;
	$messages = isset( $request['messages'] ) && is_array( $request['messages'] ) ? $request['messages'] : array();

	// The provider currently sends `system` as a bare string, but the field is
	// a string|array union in the API, so measure whatever shape arrives.
	$system_bytes = null === $system ? 0 : strlen( (string) wp_json_encode( $system ) );

	$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();

	return array(
		'ts'          => time(),
		'model'       => (string) ( $response['model'] ?? $request['model'] ?? '' ),

		// Prefix identity: model + tools + system, in Anthropic's own render
		// order. Truncated because this is a grouping key, not a signature.
		'prefix_hash' => substr(
			md5(
				(string) ( $request['model'] ?? '' ) . "\0" .
				(string) wp_json_encode( $tools ) . "\0" .
				(string) wp_json_encode( $system )
			),
			0,
			12
		),

		// Sizes in BYTES, never token estimates — the caller compares them to
		// the model's minimum cacheable prefix with its own divisor.
		'req_bytes'   => isset( $request['messages'] ) ? strlen( (string) wp_json_encode( $request ) ) : 0,
		'tools_bytes' => $tools ? strlen( (string) wp_json_encode( $tools ) ) : 0,
		'tools_count' => count( $tools ),
		'sys_bytes'   => $system_bytes,
		'msg_bytes'   => $messages ? strlen( (string) wp_json_encode( $messages ) ) : 0,
		'msg_count'   => count( $messages ),

		// Token counts as reported. cache_write/cache_read are null when the
		// key is ABSENT (not measured) and 0 when present and zero (measured,
		// no caching) — a distinction the flattening TokenUsage DTO loses and
		// that the whole point of this probe depends on.
		'in'          => array_key_exists( 'input_tokens', $usage ) ? (int) $usage['input_tokens'] : null,
		'out'         => array_key_exists( 'output_tokens', $usage ) ? (int) $usage['output_tokens'] : null,
		'cache_write' => array_key_exists( 'cache_creation_input_tokens', $usage ) ? (int) $usage['cache_creation_input_tokens'] : null,
		'cache_read'  => array_key_exists( 'cache_read_input_tokens', $usage ) ? (int) $usage['cache_read_input_tokens'] : null,
	);
}

/**
 * Append one entry to the capped FIFO probe log.
 *
 * @since 10.50.0
 *
 * @param array $entry Probe entry.
 * @return void
 */
function snt_ai_cache_probe_append( $entry ) {
	$log = get_option( SN_AI_CACHE_PROBE_OPT, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = $entry;
	if ( count( $log ) > SN_AI_CACHE_PROBE_CAP ) {
		$log = array_slice( $log, -SN_AI_CACHE_PROBE_CAP );
	}
	update_option( SN_AI_CACHE_PROBE_OPT, $log, false );
}

/**
 * Summarise the probe log into the four numbers the caching decision needs.
 *
 * `repeatable` is the verdict field: calls whose prefix had already been seen
 * within SN_AI_CACHE_PROBE_TTL, i.e. calls that WOULD have read from cache had
 * a breakpoint been set. Zero repeatable calls means caching cannot pay here
 * no matter how large the prefixes are.
 *
 * @since 10.50.0
 *
 * @param array|null $log Probe log, or null to read the option.
 * @return array{calls:int,prefixes:int,repeatable:int,max_prefix_bytes:int,cache_read:int,cache_write:int,measured:int}
 */
function snt_ai_cache_probe_summary( $log = null ) {
	if ( null === $log ) {
		$log = get_option( SN_AI_CACHE_PROBE_OPT, array() );
	}
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	$out = array(
		'calls'            => 0,
		'prefixes'         => 0,
		'repeatable'       => 0,
		'max_prefix_bytes' => 0,
		'cache_read'       => 0,
		'cache_write'      => 0,
		'measured'         => 0,
	);

	$last_seen = array(); // prefix_hash => ts of the previous sighting.

	foreach ( $log as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		++$out['calls'];

		$hash = (string) ( $row['prefix_hash'] ?? '' );
		$ts   = (int) ( $row['ts'] ?? 0 );
		if ( '' !== $hash ) {
			if ( isset( $last_seen[ $hash ] ) && ( $ts - $last_seen[ $hash ] ) <= SN_AI_CACHE_PROBE_TTL ) {
				++$out['repeatable'];
			}
			$last_seen[ $hash ] = $ts;
		}

		// The cacheable prefix is tools + system; messages sit after it.
		$prefix_bytes = (int) ( $row['tools_bytes'] ?? 0 ) + (int) ( $row['sys_bytes'] ?? 0 );
		if ( $prefix_bytes > $out['max_prefix_bytes'] ) {
			$out['max_prefix_bytes'] = $prefix_bytes;
		}

		// Only rows where the API actually reported the fields count as
		// measured — absent (null) is not a measured zero.
		if ( null !== ( $row['cache_read'] ?? null ) || null !== ( $row['cache_write'] ?? null ) ) {
			++$out['measured'];
			$out['cache_read']  += (int) ( $row['cache_read'] ?? 0 );
			$out['cache_write'] += (int) ( $row['cache_write'] ?? 0 );
		}
	}

	$out['prefixes'] = count( $last_seen );

	return $out;
}
