<?php
/**
 * Signal & Noise Tools — TypeSafe Jev client (System One).
 *
 * One documented request: POST https://api.typesafe.ai/v1/systemone with a
 * bearer key, `{state, model, questions}`; the answer is a map keyed like the
 * questions, each a typed value (noul 0..1; score with probabilities and
 * confidence; choice with probabilities and confidence) plus usage. Jev
 * generates nothing; it decides. The key is the connector's (16.5.2:
 * Connector for TypeSafe Jev registers `typesafe` with Core's Connectors
 * API; Settings › Connectors holds it) and is redacted from every error string.
 *
 * Read against docs.typesafe.ai on 2026-09-18: api.md, models.md (jev-1.13,
 * 64k tokens a request, 32k of state), confidence.md (act above ~0.9, never
 * below 0.5), model-jaggedness/jev-1.13.md (literal reading, no arithmetic,
 * no dates, small state, state is not treated as hostile).
 *
 * @since 16.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_JEV_API              = 'https://api.typesafe.ai/v1/systemone';
const SN_JEV_MODEL            = 'jev-latest';
const SN_JEV_CONFIDENCE_FLOOR = 0.9;

const SN_JEV_CONNECTOR_CLASS  = 'JevConnector\\Connector';
const SN_JEV_LEGACY_OPTION    = 'sn_typesafe_api_key';
const SN_JEV_NOT_READY        = 'Install Connector for TypeSafe Jev and add the key under Settings › Connectors.';

/**
 * The key, from the connector; '' when the connector is absent or empty.
 *
 * 16.5.2: the keyring row is gone. Connector for TypeSafe Jev resolves env,
 * then constant, then Core's `connectors_typesafe_api_key`; this plugin
 * holds no second path.
 */
function sn_jev_key() {
	if ( ! class_exists( SN_JEV_CONNECTOR_CLASS ) || ! method_exists( SN_JEV_CONNECTOR_CLASS, 'get_api_key' ) ) {
		return '';
	}
	return (string) call_user_func( array( SN_JEV_CONNECTOR_CLASS, 'get_api_key' ) );
}

/**
 * One-shot migration: the key this plugin stored before 16.5.2 moves into
 * Core's connector option when the connector is active and has none, and
 * the old option is deleted either way once the connector is present. Runs
 * on every load until the old option is gone; a no-op after.
 *
 * @return string moved | dropped | none
 */
function sn_jev_migrate_legacy_key() {
	$old = (string) get_option( SN_JEV_LEGACY_OPTION, '' );
	if ( '' === $old ) {
		return 'none';
	}
	if ( ! class_exists( SN_JEV_CONNECTOR_CLASS ) ) {
		return 'none'; // keep it until the connector is here to receive it.
	}
	$target = 'connectors_typesafe_api_key';
	if ( defined( SN_JEV_CONNECTOR_CLASS . '::SETTING_NAME' ) ) {
		$target = (string) constant( SN_JEV_CONNECTOR_CLASS . '::SETTING_NAME' );
	}
	$moved = false;
	if ( '' === trim( (string) get_option( $target, '' ) ) ) {
		update_option( $target, $old, false );
		$moved = true;
	}
	delete_option( SN_JEV_LEGACY_OPTION );
	return $moved ? 'moved' : 'dropped';
}
add_action( 'plugins_loaded', 'sn_jev_migrate_legacy_key', 20 );

/**
 * One request. Returns {ok, code, answers, usage, error} and never throws.
 *
 * @since 16.3.0
 * @param string|array $state     Text, an object of named fields, or an array of texts.
 * @param array        $questions Map of question objects (type noul|score|choice).
 * @param string|null  $key       Injected for the probe; null reads the keyring.
 */
function sn_jev_ask( $state, array $questions, $key = null ) {
	$key = null === $key ? sn_jev_key() : (string) $key;
	if ( '' === $key ) {
		return array( 'ok' => false, 'code' => 0, 'answers' => array(), 'usage' => array(), 'error' => 'no-key' );
	}
	if ( array() === $questions ) {
		return array( 'ok' => false, 'code' => 0, 'answers' => array(), 'usage' => array(), 'error' => 'no-questions' );
	}
	$resp = wp_remote_post( SN_JEV_API, array(
		'timeout'     => 20,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'User-Agent'    => 'signal-and-noise-tools',
		),
		'body'        => wp_json_encode( array( 'state' => $state, 'model' => SN_JEV_MODEL, 'questions' => $questions ) ),
	) );
	if ( is_wp_error( $resp ) ) {
		return array( 'ok' => false, 'code' => 0, 'answers' => array(), 'usage' => array(), 'error' => str_replace( $key, '[key]', $resp->get_error_message() ) );
	}
	$code    = (int) wp_remote_retrieve_response_code( $resp );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	$parsed  = sn_jev_parse( $decoded );
	if ( 200 !== $code || null === $parsed ) {
		$msg = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? $decoded['message'] ?? $decoded['detail'] ?? '' ) : '';
		return array( 'ok' => false, 'code' => $code, 'answers' => array(), 'usage' => array(), 'error' => str_replace( $key, '[key]', '' !== $msg ? $msg : 'http-' . $code ) );
	}
	return array( 'ok' => true, 'code' => 200, 'answers' => $parsed['answers'], 'usage' => $parsed['usage'], 'error' => '' );
}

/**
 * The documented response, normalised. PURE. Null when the body is not an
 * answer (a missing `answers` map, an answer without its type's value). Every
 * figure is a float or int, never a string; a score answer carries its
 * confidence (0 when absent, which then never clears the floor).
 *
 * @since 16.3.0
 * @return array{answers:array<string,array>,usage:array{input_tokens:int,output_tokens:int}}|null
 */
function sn_jev_parse( $decoded ) {
	if ( ! is_array( $decoded ) || ! isset( $decoded['answers'] ) || ! is_array( $decoded['answers'] ) ) {
		return null;
	}
	$answers = array();
	foreach ( $decoded['answers'] as $k => $a ) {
		if ( ! is_array( $a ) ) {
			return null;
		}
		$type = (string) ( $a['type'] ?? '' );
		if ( 'noul' === $type && isset( $a['noul'] ) && is_numeric( $a['noul'] ) ) {
			$answers[ (string) $k ] = array( 'type' => 'noul', 'noul' => max( 0.0, min( 1.0, (float) $a['noul'] ) ) );
		} elseif ( 'score' === $type && isset( $a['score'] ) && is_numeric( $a['score'] ) ) {
			$answers[ (string) $k ] = array(
				'type'          => 'score',
				'score'         => (float) $a['score'],
				'confidence'    => isset( $a['confidence'] ) && is_numeric( $a['confidence'] ) ? max( 0.0, min( 1.0, (float) $a['confidence'] ) ) : 0.0,
				'probabilities' => array_map( 'floatval', (array) ( $a['probabilities'] ?? array() ) ),
			);
		} elseif ( 'choice' === $type && isset( $a['choice'] ) ) {
			$answers[ (string) $k ] = array(
				'type'          => 'choice',
				'choice'        => (string) $a['choice'],
				'confidence'    => isset( $a['confidence'] ) && is_numeric( $a['confidence'] ) ? max( 0.0, min( 1.0, (float) $a['confidence'] ) ) : 0.0,
				'probabilities' => array_map( 'floatval', (array) ( $a['probabilities'] ?? array() ) ),
			);
		} else {
			return null;
		}
	}
	$u = is_array( $decoded['usage'] ?? null ) ? $decoded['usage'] : array();
	return array(
		'answers' => $answers,
		'usage'   => array( 'input_tokens' => (int) ( $u['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $u['output_tokens'] ?? 0 ) ),
	);
}

/**
 * Does a score answer clear the floor? PURE. The confidence page: act
 * automatically above ~0.9, never below 0.5; the classification cookbook's
 * confident half was right nine times in ten, the rest four.
 *
 * @since 16.3.0
 */
function sn_jev_is_sure( array $answer, $floor = SN_JEV_CONFIDENCE_FLOOR ) {
	return isset( $answer['confidence'] ) && (float) $answer['confidence'] >= (float) $floor;
}
