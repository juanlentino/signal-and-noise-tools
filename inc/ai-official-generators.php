<?php
/**
 * Signal & Noise Tools: the official AI plugin writes the excerpt and the meta
 * description; S&N attaches to it.
 *
 * Until 2026-11-09 S&N switched both official features off and ran its own
 * buttons (inc/ai-ai-dedupe.php, 2026-06-06). Owner rule, restated that day:
 * S&N complements and enhances what core and the official plugins do. So the
 * official generators run, and S&N keeps by hook what was its own:
 *   - storage: the description lands in `_sn_meta_description`, the field the
 *     SEO module prints, because S&N is declared the SEO plugin in charge;
 *   - one tag: with an SEO plugin declared, the AI plugin prints no
 *     `<meta name="description">` of its own (the double tag the old block
 *     existed to prevent);
 *   - voice: the house rules are appended to the official system instruction.
 * A person still clicks Apply and then Save, as the house rule asks.
 *
 * What does not carry over: the monthly cap and the per-feature spend line.
 * These calls are the AI plugin's and are listed under Other AI (owner call
 * 2026-10-24 A: the cap stays plugin-only). S&N's own two buttons stand down
 * while the official feature is on, and come back if it is switched off or the
 * AI plugin is absent. Hook names read at ai/ai 1.5.0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_AI_OFFICIAL_SEO_SLUG = 'signal-noise';

/**
 * Whether an official AI plugin feature is running: the plugin is loaded and
 * the feature is on after every filter, the same test the plugin applies.
 *
 * @param string $id Feature id, e.g. 'excerpt-generation'.
 * @return bool
 */
function snt_ai_official_feature_on( $id ) {
	if ( ! function_exists( 'WordPress\\AI\\get_min_content_length' ) ) {
		return false;
	}
	return (bool) apply_filters( "wpai_feature_{$id}_enabled", (bool) get_option( "wpai_feature_{$id}_enabled", false ) );
}

/**
 * S&N as the SEO plugin in charge of the description. PURE.
 *
 * @param array $plugins Map of slug to {file, meta_key}.
 * @return array
 */
function snt_ai_official_seo_plugins( $plugins ) {
	$plugins                             = is_array( $plugins ) ? $plugins : array();
	$plugins[ SNT_AI_OFFICIAL_SEO_SLUG ] = array(
		'file'     => 'signal-and-noise-tools/signal-and-noise-tools.php',
		'meta_key' => '_sn_meta_description',
	);
	return $plugins;
}
add_filter( 'wpai_meta_description_seo_plugins', 'snt_ai_official_seo_plugins' );
// The AI plugin caches which SEO plugin is active in a transient, 'none'
// included. S&N is always the one here, whatever folder it was installed in.
add_filter( 'pre_transient_wpai_active_seo_plugin', static fn() => SNT_AI_OFFICIAL_SEO_SLUG );

/**
 * The house voice, appended to the official instruction for the two
 * generators. Appended, never substituted: the official text also tells the
 * model what shape to answer in. PURE.
 *
 * @param string $instruction The official system instruction.
 * @param string $name        Ability name.
 * @return string
 */
function snt_ai_official_voice( $instruction, $name ) {
	$voice = array(
		'ai/excerpt-generation' => defined( 'SNT_AI_EXCERPT_SYSTEM' ) ? SNT_AI_EXCERPT_SYSTEM : '',
		'ai/meta-description'   => defined( 'SNT_AI_META_DESC_SYSTEM' ) ? SNT_AI_META_DESC_SYSTEM : '',
	);
	// S&N's last sentence fixes the answer's shape for S&N's own parser; the
	// official ability has its own, so that one sentence is left out.
	$extra = trim( (string) preg_replace( '/Output ONLY .*$/s', '', $voice[ (string) $name ] ?? '' ) );
	return '' === $extra ? (string) $instruction : rtrim( (string) $instruction ) . "\n\nHouse rules for this site, which take precedence on wording:\n" . $extra;
}
add_filter( 'wpai_system_instruction', 'snt_ai_official_voice', 10, 2 );
