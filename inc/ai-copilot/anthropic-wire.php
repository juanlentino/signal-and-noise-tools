<?php
/**
 * Anthropic Messages API HTTP transport.
 *
 * Single function: snt_anthropic_messages_call() — POST to
 * https://api.anthropic.com/v1/messages with the required headers and
 * returns the decoded response array or WP_Error on any failure.
 *
 * This is the seam tests mock. All real API calls go through here.
 *
 * @since plugin v3.8.0
 * @package SignalNoiseTools\AICopilot
 */

defined( 'ABSPATH' ) || exit;

/** Anthropic API base URL. */
const SNT_ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

/** Anthropic API version header value. Stable since 2023-06-01. */
const SNT_ANTHROPIC_API_VERSION = '2023-06-01';

/**
 * Issue a POST to Anthropic's Messages API.
 *
 * @param string $api_key The Anthropic API key (sk-ant-...).
 * @param array  $body    The request body — must include `model`, `max_tokens`, `messages`.
 *                        Optional: `system`, `tools`, `tool_choice`.
 * @return array|WP_Error Decoded response array on success, WP_Error on any failure.
 *
 * @since 3.8.0
 */
function snt_anthropic_messages_call( string $api_key, array $body ): array|WP_Error {
	if ( '' === $api_key ) {
		return new WP_Error(
			'anthropic_no_key',
			__( 'Anthropic API key is empty. Configure one in Settings → AI Connectors.', 'signal-noise-tools' )
		);
	}

	$encoded = wp_json_encode( $body );
	if ( false === $encoded ) {
		return new WP_Error(
			'anthropic_encode_error',
			__( 'Failed to JSON-encode Anthropic request body.', 'signal-noise-tools' )
		);
	}

	// 60s timeout — multi-turn tool-use conversations can take a while.
	@set_time_limit( 120 );
	$response = wp_remote_post( SNT_ANTHROPIC_API_URL, array(
		'timeout'     => 60,
		'redirection' => 0,
		'headers'     => array(
			'content-type'      => 'application/json',
			'x-api-key'         => $api_key,
			'anthropic-version' => SNT_ANTHROPIC_API_VERSION,
		),
		'body'        => $encoded,
	) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'anthropic_transport_error',
			sprintf( __( 'Anthropic transport error: %s', 'signal-noise-tools' ), $response->get_error_message() )
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	$decoded = json_decode( $body, true );

	if ( $code < 200 || $code >= 300 ) {
		$msg = '';
		if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
			$msg = (string) $decoded['error']['message'];
		}
		return new WP_Error(
			'anthropic_http_' . $code,
			sprintf( __( 'Anthropic HTTP %d: %s', 'signal-noise-tools' ), $code, $msg ?: 'no error message' )
		);
	}

	if ( ! is_array( $decoded ) ) {
		return new WP_Error(
			'anthropic_decode_error',
			__( 'Anthropic response body was not valid JSON.', 'signal-noise-tools' )
		);
	}

	return $decoded;
}
