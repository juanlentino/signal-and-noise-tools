<?php
/**
 * Signal & Noise Tools: the AI model catalog (20.10.0).
 *
 * One place for which models the pickers offer and what the defaults are.
 * Before this the default id was a literal in eight files, and the lists
 * could only change with a release: by 2026-10 they offered Sonnet 5 and
 * Gemini 2.5 while both vendors had shipped newer generations.
 *
 * Two ways a model gets into a picker now:
 *   1. The built-in lists below, updated with the plugin.
 *   2. An id the owner types into the settings form ("Another model id").
 *      It is checked for shape, remembered, and offered from then on. No
 *      release needed. The WordPress AI Client resolves ids live from each
 *      provider, so a typed id works as soon as the provider serves it, and
 *      the request falls back to SN_AI_FALLBACK_MODEL when it does not.
 *
 * A typed id has no price in snt_ai_model_pricing() until a release adds one;
 * the spend readout already counts such calls as unpriced, never as $0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lowercase letters, digits, dot and hyphen; 3 to 64 characters. */
const SN_AI_MODEL_ID_PATTERN = '/^[a-z0-9][a-z0-9.\-]{2,63}$/';
const SN_AI_MODELS_EXTRA_MAX = 20;

/** The prose default. One literal; SN_AI_DEFAULT_MODEL and settings.php must equal it (pinned). */
function sn_ai_default_model() {
	return 'claude-sonnet-5-5';
}

/** The vision (alt text) default: Google's cheapest stable multimodal model. */
function sn_ai_default_vision_model() {
	return 'gemini-3.1-flash-lite';
}

/**
 * The built-in list for a picker. Ids are the vendors' alias form.
 * Verified 2026-10-03 against platform.claude.com/docs (models overview) and
 * ai.google.dev/gemini-api/docs/models.
 *
 * @param string $kind 'prose' or 'vision'.
 * @return array<string,string> id => label.
 */
function sn_ai_models_builtin( $kind ) {
	if ( 'vision' === $kind ) {
		return array(
			'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite (default: fast, cheapest vision)',
			'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite (fast)',
			'gemini-3.8-flash'      => 'Gemini 3.8 Flash (strongest Flash)',
			// Kept so a stored choice still shows as selected. Google limits the
			// 2.5 family to accounts that already used it.
			'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite (older; Google restricts to prior users)',
			'gemini-2.5-flash'      => 'Gemini 2.5 Flash (older; Google restricts to prior users)',
			'gemini-2.5-pro'        => 'Gemini 2.5 Pro (older; Google restricts to prior users)',
		);
	}
	return array(
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (balanced, default)',
		'claude-opus-5-5'   => 'Claude Opus 5.5 (most capable Opus)',
		'claude-fable-5-1'  => 'Claude Fable 5.1 (most capable, priciest)',
		'claude-haiku-4-5'  => 'Claude Haiku 4.5 (fastest, cheapest)',
		'claude-sonnet-5'   => 'Claude Sonnet 5 (previous default)',
		'claude-opus-4-8'   => 'Claude Opus 4.8 (previous)',
	);
}

/**
 * Is this a well-formed model id? PURE.
 *
 * @param mixed $id Candidate.
 * @return bool
 */
function sn_ai_model_id_ok( $id ) {
	return is_string( $id ) && 1 === preg_match( SN_AI_MODEL_ID_PATTERN, $id );
}

/**
 * The ids the owner added for a picker, well-formed ones only.
 *
 * @param string $kind 'prose' or 'vision'.
 * @return string[]
 */
function sn_ai_models_extra( $kind ) {
	$stored = sn_setting( 'vision' === $kind ? 'theme.ai_vision_models_extra' : 'theme.ai_models_extra', array() );
	return array_slice( array_values( array_unique( array_filter( (array) $stored, 'sn_ai_model_id_ok' ) ) ), 0, SN_AI_MODELS_EXTRA_MAX );
}

/**
 * Everything a picker offers: the built-in list, then the owner's ids.
 *
 * @param string $kind 'prose' or 'vision'.
 * @return array<string,string> id => label.
 */
function sn_ai_models( $kind ) {
	$list = sn_ai_models_builtin( $kind );
	foreach ( sn_ai_models_extra( $kind ) as $id ) {
		if ( ! isset( $list[ $id ] ) ) {
			/* translators: %s: a model id the owner typed into the settings form */
			$list[ $id ] = sprintf( __( '%s (added here)', 'signal-and-noise-tools' ), $id );
		}
	}
	return $list;
}

/**
 * Remember a typed id for a picker. Refuses a malformed id; a built-in or
 * already-remembered id is accepted and changes nothing.
 *
 * @param string $kind 'prose' or 'vision'.
 * @param string $id   The typed id, already trimmed and lowercased.
 * @return bool Whether the id can now be selected.
 */
function sn_ai_models_add_extra( $kind, $id ) {
	if ( ! sn_ai_model_id_ok( $id ) ) {
		return false;
	}
	if ( isset( sn_ai_models( $kind )[ $id ] ) ) {
		return true;
	}
	// Newest first, so the cap drops the oldest typed id, never the one just added.
	$extra = array_slice( array_merge( array( $id ), sn_ai_models_extra( $kind ) ), 0, SN_AI_MODELS_EXTRA_MAX );
	sn_setting_update( 'vision' === $kind ? 'theme.ai_vision_models_extra' : 'theme.ai_models_extra', $extra );
	return true;
}
