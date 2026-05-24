<?php
/**
 * Anthropic AI provider for desktop-mode 0.18.0+ Copilot.
 *
 * Implements the 3 callbacks required by desktop-mode's provider registry:
 *
 *   - snt_anthropic_make_turn_input( $kind, $payload )
 *   - snt_anthropic_agentic_call( $key, $turn_input, $tools, $text_format, $instructions, $state )
 *   - snt_anthropic_structured_request( $key, $messages, $schema, $schema_name, $model )
 *
 * State threading: Anthropic has no `previous_response_id` equivalent
 * (unlike OpenAI Responses API). Our `next_state` carries the full message
 * history; every turn replays it. Token cost grows linearly with depth,
 * capped by desktop-mode's 10-iteration loop.
 *
 * API key sourcing: defers to wp-ai-client's settings via the filterable
 * `snt_anthropic_resolved_api_key` hook. Plugin tests stub this filter.
 *
 * @since plugin v3.8.0
 * @package SignalNoiseTools\AICopilot
 *
 * @see https://github.com/WordPress/desktop-mode/blob/trunk/includes/ai-copilot/providers-registry.php
 * @see https://docs.anthropic.com/en/api/messages
 */

defined( 'ABSPATH' ) || exit;

/** Default model. Filter `snt_anthropic_provider_model` to override per-request. */
const SNT_ANTHROPIC_DEFAULT_MODEL = 'claude-sonnet-4-6';

/** Default max_tokens for Messages API calls. */
const SNT_ANTHROPIC_DEFAULT_MAX_TOKENS = 4096;

/**
 * Source the Anthropic API key.
 *
 * Resolution order:
 *   1. `snt_anthropic_resolved_api_key` filter (test override OR custom routing)
 *   2. wp-ai-client provider settings (canonical path — single credential setup)
 *   3. WP_Error('anthropic_no_key') with remediation hint
 *
 * @return string|WP_Error
 * @since 3.8.0
 */
function snt_anthropic_resolve_api_key() {
	$override = apply_filters( 'snt_anthropic_resolved_api_key', '' );
	if ( is_string( $override ) && '' !== $override ) {
		return $override;
	}
	if ( function_exists( 'wp_ai_client_get_provider_settings' ) ) {
		$settings = wp_ai_client_get_provider_settings( 'anthropic' );
		if ( is_array( $settings ) && ! empty( $settings['api_key'] ) ) {
			return (string) $settings['api_key'];
		}
	}
	return new WP_Error(
		'anthropic_no_key',
		__( 'No Anthropic API key configured. Set one in Settings → AI Connectors (wp-ai-client plugin).', 'signal-noise-tools' )
	);
}

/**
 * Provider callback: build the "turn input" payload from a kind+payload pair.
 *
 * @param string $kind     'user_message' | 'tool_results'
 * @param mixed  $payload  string (user_message) | array (tool_results)
 * @return array Anthropic-shaped messages array (one element for user_message; one user message with multiple tool_result blocks for tool_results)
 *
 * @since 3.8.0
 */
function snt_anthropic_make_turn_input( string $kind, $payload ): array {
	if ( 'user_message' === $kind ) {
		return array(
			array( 'role' => 'user', 'content' => (string) $payload ),
		);
	}
	if ( 'tool_results' === $kind ) {
		$blocks = array();
		foreach ( (array) $payload as $tr ) {
			$blocks[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => (string) ( $tr['call_id'] ?? '' ),
				'content'     => (string) ( $tr['output'] ?? '' ),
			);
		}
		return array( array( 'role' => 'user', 'content' => $blocks ) );
	}
	// Unknown kind — return empty messages array; agentic_call will error cleanly
	error_log( '[SN] snt_anthropic_make_turn_input: unknown kind: ' . $kind );
	return array();
}

/**
 * Provider callback: one turn of the agentic loop.
 *
 * @param string $api_key      Anthropic API key (already resolved by desktop-mode's settings; we may override via filter).
 * @param array  $turn_input   Output of make_turn_input — Anthropic-shaped messages to append.
 * @param array  $tools        OpenAI-shape tool definitions (we translate inside).
 * @param array|null $text_format  OpenAI text.format object (`{type:'json_schema',name,strict,schema}`) or null.
 * @param string $instructions System prompt.
 * @param mixed  $state        Prior state from previous turn's `next_state`, or null on first turn.
 * @return array|WP_Error  { text: ?string, function_calls: array, next_state: array, raw: array }
 *
 * @since 3.8.0
 */
function snt_anthropic_agentic_call(
	string $api_key,
	array $turn_input,
	array $tools,
	$text_format,
	string $instructions,
	$state
) {
	// Override API key from wp-ai-client if available (gives single-credential setup).
	$resolved = snt_anthropic_resolve_api_key();
	if ( is_string( $resolved ) && '' !== $resolved ) {
		$api_key = $resolved;
	}

	// 1. Build messages: prior history + this turn's new input
	$messages = ( is_array( $state ) && is_array( $state['messages'] ?? null ) ) ? $state['messages'] : array();
	$messages = array_merge( $messages, $turn_input );

	// 2. Translate tools (OpenAI → Anthropic) and conditionally append a synthetic schema tool
	$anthropic_tools = snt_anthropic_translate_tools( $tools );
	$schema_name     = null;
	if ( is_array( $text_format ) && ! empty( $text_format['format']['schema'] ) ) {
		$schema_name = (string) ( $text_format['format']['name'] ?? 'final_answer' );
		$anthropic_tools[] = snt_anthropic_synthetic_structured_tool(
			$schema_name,
			$text_format['format']['schema']
		);
	}

	// 3. Build the request body
	$body = array(
		'model'      => apply_filters( 'snt_anthropic_provider_model', SNT_ANTHROPIC_DEFAULT_MODEL ),
		'max_tokens' => apply_filters( 'snt_anthropic_provider_max_tokens', SNT_ANTHROPIC_DEFAULT_MAX_TOKENS ),
		'system'     => $instructions,
		'messages'   => $messages,
	);
	if ( ! empty( $anthropic_tools ) ) {
		$body['tools'] = $anthropic_tools;
	}

	// 4. POST to Anthropic
	$response = snt_anthropic_messages_call( $api_key, $body );
	if ( is_wp_error( $response ) ) {
		error_log( '[SN] anthropic agentic_call failed: ' . $response->get_error_message() );
		return $response;
	}

	// 5. Parse response by stop_reason
	$stop_reason = (string) ( $response['stop_reason'] ?? 'unknown' );
	$content     = (array) ( $response['content'] ?? array() );

	$assistant_msg = array(
		'role'    => 'assistant',
		'content' => $content,
	);

	if ( 'end_turn' === $stop_reason ) {
		$schema_input = snt_anthropic_extract_schema_tool_use( $content, $schema_name );
		$text = is_array( $schema_input )
			? wp_json_encode( $schema_input )
			: snt_anthropic_extract_text_blocks( $content );
		return array(
			'text'           => $text,
			'function_calls' => array(),
			'next_state'     => array( 'messages' => array_merge( $messages, array( $assistant_msg ) ) ),
			'raw'            => $response,
		);
	}

	if ( 'tool_use' === $stop_reason ) {
		$function_calls = snt_anthropic_extract_tool_uses( $content );
		// If a synthetic schema tool is present, exclude it from function_calls
		// (we don't want desktop-mode to dispatch it as a normal tool).
		if ( null !== $schema_name ) {
			$schema_name_local = $schema_name;
			$function_calls = array_values( array_filter(
				$function_calls,
				static function ( $fc ) use ( $schema_name_local ) {
					return ( $fc['name'] ?? '' ) !== $schema_name_local;
				}
			) );
		}
		return array(
			'text'           => null,
			'function_calls' => $function_calls,
			'next_state'     => array( 'messages' => array_merge( $messages, array( $assistant_msg ) ) ),
			'raw'            => $response,
		);
	}

	if ( 'max_tokens' === $stop_reason ) {
		return new WP_Error(
			'anthropic_max_tokens',
			__( 'Anthropic response truncated by max_tokens. Increase max_tokens or simplify the query.', 'signal-noise-tools' )
		);
	}

	return new WP_Error(
		'anthropic_unknown_stop_reason',
		sprintf( __( 'Anthropic returned unknown stop_reason: %s', 'signal-noise-tools' ), $stop_reason )
	);
}

/**
 * Provider callback: single-shot structured-output request.
 *
 * Uses forced `tool_choice` to coerce JSON output (Anthropic has no native
 * `response_format: json_schema`).
 *
 * @param string $api_key
 * @param array  $messages    Chat-shape messages [{role, content}]. System messages split out and joined.
 * @param array  $schema      The desired output JSON Schema.
 * @param string $schema_name Name for the synthetic tool. Becomes the forced tool_choice.
 * @param string $model       Empty = provider default.
 * @return array|WP_Error     Decoded structured response as an array, or WP_Error.
 *
 * @since 3.8.0
 */
function snt_anthropic_structured_request(
	string $api_key,
	array $messages,
	array $schema,
	string $schema_name,
	string $model
) {
	$resolved = snt_anthropic_resolve_api_key();
	if ( is_string( $resolved ) && '' !== $resolved ) {
		$api_key = $resolved;
	}

	// Split system messages out
	$system_parts = array();
	$msgs         = array();
	foreach ( $messages as $m ) {
		if ( 'system' === ( $m['role'] ?? '' ) ) {
			$system_parts[] = (string) ( $m['content'] ?? '' );
		} else {
			$msgs[] = $m;
		}
	}
	$system = trim( implode( "\n\n", $system_parts ) );

	$body = array(
		'model'       => '' !== $model ? $model : apply_filters( 'snt_anthropic_provider_model', SNT_ANTHROPIC_DEFAULT_MODEL ),
		'max_tokens'  => apply_filters( 'snt_anthropic_provider_max_tokens', SNT_ANTHROPIC_DEFAULT_MAX_TOKENS ),
		'system'      => $system,
		'messages'    => $msgs,
		'tools'       => array( snt_anthropic_synthetic_structured_tool( $schema_name, $schema ) ),
		'tool_choice' => array( 'type' => 'tool', 'name' => $schema_name ),
	);

	$response = snt_anthropic_messages_call( $api_key, $body );
	if ( is_wp_error( $response ) ) {
		error_log( '[SN] anthropic structured_request failed: ' . $response->get_error_message() );
		return $response;
	}

	$content = (array) ( $response['content'] ?? array() );
	$schema_input = snt_anthropic_extract_schema_tool_use( $content, $schema_name );
	if ( null === $schema_input ) {
		return new WP_Error(
			'anthropic_no_structured_output',
			__( 'Anthropic did not return a tool_use block for the requested structured output.', 'signal-noise-tools' )
		);
	}
	return $schema_input;
}

/**
 * Register the Anthropic provider with desktop-mode.
 *
 * Hooked to `desktop_mode_ai_register_providers` (fires lazily on first
 * provider lookup per request).
 *
 * @since 3.8.0
 */
function snt_anthropic_register_provider(): void {
	if ( ! function_exists( 'desktop_mode_register_ai_provider' ) ) {
		error_log( '[SN] Anthropic provider registration skipped: desktop_mode_register_ai_provider() not available.' );
		return;
	}
	$result = desktop_mode_register_ai_provider( 'anthropic', array(
		'label'              => __( 'Anthropic (Claude)', 'signal-noise-tools' ),
		'description'        => __( 'Anthropic Messages API. Defaults to Sonnet 4.6.', 'signal-noise-tools' ),
		'api_key_label'      => __( 'Anthropic API key', 'signal-noise-tools' ),
		'api_key_link'       => 'https://console.anthropic.com/account/keys',
		'default_model'      => SNT_ANTHROPIC_DEFAULT_MODEL,
		'capabilities'       => array( 'tools', 'structured_output' ),
		'make_turn_input'    => 'snt_anthropic_make_turn_input',
		'agentic_call'       => 'snt_anthropic_agentic_call',
		'structured_request' => 'snt_anthropic_structured_request',
	) );
	if ( is_wp_error( $result ) ) {
		error_log( '[SN] Anthropic provider registration failed: ' . $result->get_error_message() );
	}
}
add_action( 'desktop_mode_ai_register_providers', 'snt_anthropic_register_provider' );
