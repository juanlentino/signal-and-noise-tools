<?php
/**
 * Anthropic ↔ desktop-mode tool format translation helpers.
 *
 * Three responsibilities:
 *   1. Translate OpenAI-shape tools (the format desktop-mode's search.php
 *      builds) into Anthropic-shape (`input_schema` instead of `parameters`,
 *      no `type:function` wrapper).
 *   2. Extract `tool_use` content blocks from Anthropic responses into the
 *      shape desktop-mode's registry contract expects (`{name, call_id,
 *      arguments}` where arguments is a JSON-encoded STRING).
 *   3. Build a synthetic single-tool definition for JSON-mode dispatch
 *      (Anthropic has no native `response_format: json_schema` — the
 *      canonical pattern is to force a tool_use on a tool whose
 *      input_schema is the desired response schema).
 *
 * @since plugin v3.8.0
 * @package SignalNoiseTools\AICopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translate OpenAI Responses-API tool definitions into Anthropic tool definitions.
 *
 * OpenAI: { type:'function', name, description, parameters, strict? }
 * Anthropic: { name, description, input_schema }
 *
 * Non-function entries pass through unchanged (defensive — shouldn't happen
 * in practice with desktop-mode's current tool assembly).
 *
 * @param array $openai_tools Array of OpenAI-shape tool definitions.
 * @return array Array of Anthropic-shape tool definitions.
 *
 * @since 3.8.0
 */
function snt_anthropic_translate_tools( array $openai_tools ): array {
	$out = array();
	foreach ( $openai_tools as $t ) {
		if ( ( $t['type'] ?? '' ) !== 'function' ) {
			$out[] = $t;
			continue;
		}
		$out[] = array(
			'name'         => (string) ( $t['name'] ?? '' ),
			'description'  => (string) ( $t['description'] ?? '' ),
			'input_schema' => $t['parameters'] ?? array( 'type' => 'object', 'properties' => (object) array() ),
		);
	}
	return $out;
}

/**
 * Extract `tool_use` content blocks from an Anthropic response into the
 * registry's expected shape.
 *
 * desktop-mode's providers-registry contract (lines 32-34 of
 * providers-registry.php) requires:
 *   - `name`: string
 *   - `call_id`: string
 *   - `arguments`: JSON-encoded string (NOT a decoded array)
 *
 * Anthropic's tool_use.input is a decoded object/array. We re-encode to
 * match the contract. desktop-mode's search loop will json_decode this
 * string again at search.php:1185 to extract the arguments — that
 * double-encode/decode is the price of bridging the two APIs.
 *
 * @param array $content_blocks The `content` array from an Anthropic response.
 * @return array<int, array{name: string, call_id: string, arguments: string}>
 *
 * @since 3.8.0
 */
function snt_anthropic_extract_tool_uses( array $content_blocks ): array {
	$out = array();
	foreach ( $content_blocks as $block ) {
		if ( ( $block['type'] ?? '' ) !== 'tool_use' ) continue;
		$input = $block['input'] ?? (object) array();
		$out[] = array(
			'name'      => (string) ( $block['name'] ?? '' ),
			'call_id'   => (string) ( $block['id'] ?? '' ),
			'arguments' => wp_json_encode( $input ),
		);
	}
	return $out;
}

/**
 * Concatenate all `text` content blocks into a single string.
 *
 * Anthropic responses interleave text and tool_use blocks. Final-turn
 * responses (stop_reason='end_turn') typically have just text blocks.
 *
 * @param array $content_blocks The `content` array from an Anthropic response.
 * @return string Concatenated text. Empty string if no text blocks present.
 *
 * @since 3.8.0
 */
function snt_anthropic_extract_text_blocks( array $content_blocks ): string {
	$text = '';
	foreach ( $content_blocks as $block ) {
		if ( ( $block['type'] ?? '' ) !== 'text' ) continue;
		$text .= (string) ( $block['text'] ?? '' );
	}
	return $text;
}

/**
 * Build a synthetic tool definition for forced-tool-call JSON mode.
 *
 * Anthropic has no native `response_format: json_schema` like OpenAI. The
 * canonical pattern is:
 *   1. Define a tool whose `input_schema` is the desired response schema
 *   2. Send `tool_choice: { type: 'tool', name: <schema_name> }`
 *   3. Read the response from the single tool_use block's `input` field
 *
 * @param string $name   The tool name to use (also used in tool_choice).
 * @param array  $schema The JSON Schema for the desired structured response.
 * @return array Anthropic-shape tool definition.
 *
 * @since 3.8.0
 */
function snt_anthropic_synthetic_structured_tool( string $name, array $schema ): array {
	return array(
		'name'         => $name,
		'description'  => 'Return the final structured answer using this tool. Call this exactly once with the final response data.',
		'input_schema' => $schema,
	);
}

/**
 * Extract the `input` of a tool_use block whose name matches `$schema_name`.
 *
 * Used for the JSON-mode final-answer extraction — when `agentic_call` has
 * added a synthetic schema tool, the model may call it as its final action
 * instead of emitting plain text.
 *
 * @param array       $content_blocks The `content` array from an Anthropic response.
 * @param string|null $schema_name    The name of the synthetic schema tool. If null, returns null.
 * @return array|null The schema tool's input as a decoded array, or null if not found.
 *
 * @since 3.8.0
 */
function snt_anthropic_extract_schema_tool_use( array $content_blocks, ?string $schema_name ): ?array {
	if ( null === $schema_name || '' === $schema_name ) return null;
	foreach ( $content_blocks as $block ) {
		if ( ( $block['type'] ?? '' ) !== 'tool_use' ) continue;
		if ( ( $block['name'] ?? '' ) !== $schema_name ) continue;
		$input = $block['input'] ?? null;
		return is_array( $input ) ? $input : null;
	}
	return null;
}
