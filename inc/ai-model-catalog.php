<?php
/**
 * Signal & Noise Tools: the AI model catalog (20.10.0).
 *
 * One place for which models the pickers offer and what the defaults are.
 * Before this the default id was a literal in eight files, and the lists
 * could only change with a release: by 2026-10 they offered Sonnet 5 and
 * Gemini 2.5 while both vendors had shipped newer generations.
 *
 * THE LISTS UPDATE THEMSELVES (owner, 2026-10-03). Once a day a cron asks the
 * WordPress AI Client's provider registry what each configured provider
 * serves; the Anthropic and Google provider plugins answer from the vendors'
 * own model-list APIs with the site's connector keys. The pickers read the
 * stored answer. The built-in lists below are the seed: what shows before the
 * first read, when no provider is connected, or when the stored answer has
 * gone stale.
 *
 * Three things stay in code on purpose. The DEFAULT model is pinned (a
 * default that advanced by itself would change behavior and cost nobody
 * chose). PRICES are a map in inc/ai-bootstrap/pricing.php (a discovered
 * model with no price is counted as unpriced, never as $0). And the read
 * happens in cron, never while a settings screen renders.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lowercase letters, digits, dot and hyphen; 3 to 64 characters. */
const SN_AI_MODEL_ID_PATTERN = '/^[a-z0-9][a-z0-9.\-]{2,63}$/';
const SN_AI_MODELS_OPT       = 'sn_ai_models_discovered'; // { fetched, at: { id: unix }, providers: { id: rows }, errors: { id: text } }.
const SN_AI_MODELS_HOOK      = 'snt_ai_models_refresh';
const SN_AI_MODELS_MAX       = 12;                        // rows a picker shows from a provider.
const SN_AI_MODELS_STALE     = 7 * 86400;                 // older than this, the seed list shows again.

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
 * From a provider's rows, the ones a picker should offer. PURE.
 *
 * Prose wants a text model. Vision wants a text model that takes an image.
 * A dated snapshot ("...-20250929") is dropped when its undated alias is in
 * the list too, and single-purpose variants (speech, image generation, live
 * audio, embeddings, previews) are not chat models. The provider plugins
 * already sort newest and flagship first, so the order is kept and capped.
 *
 * @param array<int,array> $rows Rows from sn_ai_models_ask().
 * @param string           $kind 'prose' or 'vision'.
 * @return array<string,string> id => label.
 */
function sn_ai_models_pick( array $rows, $kind ) {
	$ids = array_column( $rows, 'id' );
	$out = array();
	foreach ( $rows as $r ) {
		$id = (string) ( $r['id'] ?? '' );
		if ( ! sn_ai_model_id_ok( $id ) || empty( $r['text'] ) || ( 'vision' === $kind && empty( $r['vision'] ) ) ) {
			continue;
		}
		if ( 1 === preg_match( '/(tts|image|live|audio|transcribe|embedding|preview|exp|robotics|computer-use|customtools)/', $id ) ) {
			continue;
		}
		if ( 1 === preg_match( '/^(.+)-\d{8}$/', $id, $m ) && in_array( $m[1], $ids, true ) ) {
			continue;
		}
		$name       = trim( (string) ( $r['name'] ?? '' ) );
		$out[ $id ] = '' !== $name && $name !== $id ? $name . ' (' . $id . ')' : $id;
		if ( count( $out ) >= SN_AI_MODELS_MAX ) {
			break;
		}
	}
	return $out;
}

/**
 * What the providers were last seen serving for a picker, or array() when
 * that is unknown or stale.
 *
 * @param string   $kind 'prose' (Anthropic) or 'vision' (Google).
 * @param int|null $now  Unix time; null reads the clock.
 * @return array<string,string>
 */
function sn_ai_models_discovered( $kind, $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$state = get_option( SN_AI_MODELS_OPT, array() );
	$provider = 'vision' === $kind ? 'google' : 'anthropic';
	if ( ! is_array( $state ) || (int) ( $state['at'][ $provider ] ?? 0 ) < $now - SN_AI_MODELS_STALE ) {
		return array(); // this provider's own last good read, not the other one's.
	}
	return sn_ai_models_pick( (array) ( $state['providers'][ $provider ] ?? array() ), $kind );
}

/**
 * Everything a picker offers: what the provider serves when that is known,
 * else the built-in list. The default and the stored choice are always in
 * it, so a saved setting never shows as nothing selected.
 *
 * @param string $kind 'prose' or 'vision'.
 * @return array<string,string> id => label.
 */
function sn_ai_models( $kind ) {
	$seed = sn_ai_models_builtin( $kind );
	$list = sn_ai_models_discovered( $kind );
	if ( array() === $list ) {
		$list = $seed; // and the stored choice is kept below here too: a discovered model that was chosen stays chosen when the read goes stale.
	}
	// The default is always offered. The stored choice is kept only when it is
	// a model this site has actually seen: in the built-in list, or among the
	// provider's last rows even if that read has since gone stale. An id that
	// is neither (a hand-edited option) stays off the list and is not used.
	$state    = get_option( SN_AI_MODELS_OPT, array() );
	$seen     = array_column( (array) ( is_array( $state ) ? ( $state['providers'][ 'vision' === $kind ? 'google' : 'anthropic' ] ?? array() ) : array() ), 'name', 'id' );
	$default  = 'vision' === $kind ? sn_ai_default_vision_model() : sn_ai_default_model();
	$stored   = (string) sn_setting( 'vision' === $kind ? 'theme.ai_alt_model' : 'theme.ai_model', '' );
	if ( ! isset( $list[ $default ] ) ) {
		$list[ $default ] = $seed[ $default ] ?? $default;
	}
	if ( sn_ai_model_id_ok( $stored ) && ! isset( $list[ $stored ] ) && ( isset( $seed[ $stored ] ) || isset( $seen[ $stored ] ) ) ) {
		$list[ $stored ] = $seed[ $stored ] ?? $stored;
	}
	return $list;
}

require_once __DIR__ . '/ai-model-discovery.php'; // the daily read of what the providers serve.
