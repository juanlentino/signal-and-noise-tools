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

/**
 * Minimum cacheable prefix, in tokens, for a model id.
 *
 * Anthropic silently caches nothing below this — no error, no warning, and
 * `cache_creation_input_tokens: 0` in the response, which is indistinguishable
 * from "caching was never requested". That makes the floor the single most
 * important number when reading this probe: a prefix under it cannot pay no
 * matter how often it repeats.
 *
 * The floor is NOT monotonic across generations (512 on the newest Opus, 4096
 * on Opus 4.6 and Haiku 4.5), so it cannot be inferred from a model's age or
 * tier and is table-driven here. An unknown id returns null rather than a
 * guess — "we don't know this model's floor" is a real answer and the caller
 * renders it as one.
 *
 * @since 10.52.0
 *
 * @param string $model Model id as sent to the API.
 * @return int|null Minimum cacheable prefix in tokens, or null if unknown.
 */
function snt_ai_cache_probe_min_prefix_tokens( $model ) {
	$floors = array(
		'claude-opus-5'      => 512,
		'claude-fable-5'     => 512,
		'claude-mythos-5'    => 512,
		'claude-opus-4-8'    => 1024,
		'claude-sonnet-5'    => 1024,
		'claude-sonnet-4-6'  => 1024,
		'claude-sonnet-4-5'  => 1024,
		'claude-opus-4-1'    => 1024,
		'claude-opus-4-0'    => 1024,
		'claude-sonnet-4-0'  => 1024,
		'claude-opus-4-7'    => 2048,
		'claude-opus-4-6'    => 4096,
		'claude-opus-4-5'    => 4096,
		'claude-haiku-4-5'   => 4096,
	);

	$model = (string) $model;

	/**
	 * Filters the minimum cacheable prefix for a model.
	 *
	 * @since 10.52.0
	 *
	 * @param int|null $floor Minimum cacheable prefix in tokens, or null if unknown.
	 * @param string   $model Model id.
	 */
	return apply_filters( 'snt_ai_cache_probe_min_prefix', $floors[ $model ] ?? null, $model );
}

/**
 * Upper bound on the token count of a prefix, from its byte length and — when
 * the API reported it — the request's own `input_tokens`.
 *
 * Two independent bounds, and the tighter one wins:
 *
 * 1. A byte estimate at a deliberately DENSE 3.0 bytes/token. Erring high
 *    matters because this figure is what declares a prefix BELOW the floor;
 *    the probe must never talk the owner out of a saving that was real. The
 *    divisor is calibrated against this probe's own live data rather than the
 *    usual ~4-bytes/token folklore: a 922-byte request measured 297 input
 *    tokens, i.e. 3.10 bytes/token for JSON-wrapped English.
 * 2. `input_tokens` as reported by Anthropic, when present. The cacheable
 *    prefix (tools + system) is a strict SUBSET of the request's input, so
 *    the reported figure is an EXACT upper bound — no estimation involved.
 *    When a call's whole input is under the floor, its prefix is under the
 *    floor, and that conclusion is arithmetic rather than inference.
 *
 * @since 10.52.0
 *
 * @param int      $bytes        Prefix byte length.
 * @param int|null $input_tokens Reported input_tokens, or null if unmeasured.
 * @return int Upper-bound token count for the prefix.
 */
function snt_ai_cache_probe_tokens_hi( $bytes, $input_tokens = null ) {
	$estimate = (int) ceil( max( 0, (int) $bytes ) / 3.0 );
	if ( null === $input_tokens ) {
		return $estimate;
	}
	return min( $estimate, max( 0, (int) $input_tokens ) );
}

/**
 * Turn the probe log into a decision.
 *
 * Caching pays only when BOTH hold: a prefix clears its model's floor, and
 * that prefix repeats inside the TTL. Either alone is worth nothing, which is
 * why a raw summary can mislead — `repeatable: 1` on a 677-byte prefix looks
 * like a signal and is not one.
 *
 * States: no_data (nothing recorded yet — not a verdict), caching_active (a
 * cache read was observed, so something upstream now emits a breakpoint),
 * candidate (clears the floor AND repeats), no_repeats (clears the floor,
 * never repeated in the window), below_floor (nothing came close),
 * unknown_floor (a model with no floor on file, so no claim is made).
 *
 * @since 10.52.0
 *
 * @param array|null $log Probe log, or null to read the option.
 * @return array{state:string,summary:array,models:array,best:array|null}
 */
function snt_ai_cache_probe_verdict( $log = null ) {
	if ( null === $log ) {
		$log = get_option( SN_AI_CACHE_PROBE_OPT, array() );
	}
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	$summary = snt_ai_cache_probe_summary( $log );
	$models  = array();
	$seen    = array(); // "model\0hash" => ts of the previous sighting.

	foreach ( $log as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$model = (string) ( $row['model'] ?? '' );
		$ts    = (int) ( $row['ts'] ?? 0 );
		$bytes = (int) ( $row['tools_bytes'] ?? 0 ) + (int) ( $row['sys_bytes'] ?? 0 );

		if ( ! isset( $models[ $model ] ) ) {
			$floor              = snt_ai_cache_probe_min_prefix_tokens( $model );
			$models[ $model ] = array(
				'model'            => $model,
				'calls'            => 0,
				'repeatable'       => 0,
				'max_prefix_bytes' => 0,
				'max_prefix_tokens'=> 0,
				'floor'            => $floor,
				'may_clear_floor'  => null === $floor ? null : false,
			);
		}

		++$models[ $model ]['calls'];

		$key = $model . "\0" . (string) ( $row['prefix_hash'] ?? '' );
		if ( isset( $seen[ $key ] ) && ( $ts - $seen[ $key ] ) <= SN_AI_CACHE_PROBE_TTL ) {
			++$models[ $model ]['repeatable'];
		}
		$seen[ $key ] = $ts;

		$tokens_hi = snt_ai_cache_probe_tokens_hi( $bytes, $row['in'] ?? null );
		if ( $tokens_hi > $models[ $model ]['max_prefix_tokens'] ) {
			$models[ $model ]['max_prefix_tokens'] = $tokens_hi;
		}
		if ( $bytes > $models[ $model ]['max_prefix_bytes'] ) {
			$models[ $model ]['max_prefix_bytes'] = $bytes;
		}

		$floor                               = $models[ $model ]['floor'];
		$models[ $model ]['may_clear_floor'] = null === $floor
			? null
			: ( $models[ $model ]['max_prefix_tokens'] >= $floor );
	}

	// Strongest candidate first: clears the floor and repeats, then by prefix size.
	uasort(
		$models,
		static function ( $a, $b ) {
			$score = static function ( $m ) {
				return ( true === $m['may_clear_floor'] && $m['repeatable'] > 0 ) ? 2 : ( true === $m['may_clear_floor'] ? 1 : 0 );
			};
			$cmp = $score( $b ) <=> $score( $a );
			return 0 !== $cmp ? $cmp : ( (int) $b['max_prefix_bytes'] <=> (int) $a['max_prefix_bytes'] );
		}
	);

	$best  = null;
	$state = 'below_floor';

	if ( 0 === (int) $summary['calls'] ) {
		$state = 'no_data';
	} elseif ( (int) $summary['cache_read'] > 0 ) {
		$state = 'caching_active';
	} else {
		$clearing = array_filter(
			$models,
			static function ( $m ) {
				return true === $m['may_clear_floor'];
			}
		);
		$unknown  = array_filter(
			$models,
			static function ( $m ) {
				return null === $m['may_clear_floor'];
			}
		);

		if ( $clearing ) {
			$repeating = array_filter(
				$clearing,
				static function ( $m ) {
					return $m['repeatable'] > 0;
				}
			);
			$state     = $repeating ? 'candidate' : 'no_repeats';
			$best      = $repeating ? reset( $repeating ) : reset( $clearing );
		} elseif ( $unknown ) {
			$state = 'unknown_floor';
			$best  = reset( $unknown );
		}
	}

	return array(
		'state'   => $state,
		'summary' => $summary,
		'models'  => array_values( $models ),
		'best'    => $best,
	);
}
