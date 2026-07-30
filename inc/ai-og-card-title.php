<?php
/**
 * Signal & Noise Tools — AI-assisted OG card title generation.
 *
 * Phase 16, slice 1 — fills `_sn_og_card_title` (the per-post meta box
 * field added in v2.4.0) with a 60-90 character punchy variant of the
 * post title via the WP AI Client. The OG card generator's
 * sn_og_card_title filter (added in v2.4.0 at og-card-generator.php:181)
 * reads the override at PNG render time.
 *
 * The og:title HTML meta tag is untouched — search engines and social-
 * share scrapers still see the canonical article title. Only the visual
 * title baked into the PNG is replaced. Think of it as "alt for the
 * card image."
 *
 * Two surfaces, both gated on snt_ai_is_available():
 *
 *   1. Ability: signal-noise/ai-generate-og-card-title (registered in
 *      inc/abilities-ai-post-editor.php; the bespoke REST route was removed
 *      in v5.0.0 — abilities' /wp-json/abilities/v1 run path is the only
 *      transport). Input: { post_id: int }. Returns { ok, title, length,
 *      card_regenerated, card_url }. Side effect: writes _sn_og_card_title
 *      AND re-runs sn_generate_og_card so the PNG on disk picks up the new
 *      title immediately. Permission: edit_post for the given post_id.
 *
 *   2. Meta-box button: rendered inside the per-post SN meta box next to
 *      the OG card title textarea (post-settings.php:OG card title). JS
 *      at assets/ai-og-card-title.js runs the ability via sntAbilityRun,
 *      fills the textarea on success, shows inline status.
 *
 * Prompt design:
 *   - 60-90 chars (vs meta description's 140-160)
 *   - Punchier, more direct, headline-like
 *   - Drop subtitles after a colon if they pad length
 *   - Match the source's voice — don't go generic clickbait
 *   - Same provider-agnostic / no-temperature posture as ai-meta-description.php
 *
 * @package SignalNoiseTools
 * @since 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v10.6.1 — the voice fix. The social card is where the pull toward
// clickbait is strongest and does the most damage to a peer register, so
// the prompt bans the clickbait shapes by name instead of asking for
// "punchier" and hoping.
const SNT_AI_OG_CARD_TITLE_SYSTEM = 'Rewrite an essay title for a social share card. ' .
	'The card restates the title\'s claim in different words, in a declarative register written for peers. ' .
	'Form: 60-90 characters total, count carefully. ' .
	'No questions, no curiosity gaps, no "here\'s why", no "the truth about", no numbered framing. ' .
	'No colon used as a hook: "Provenance: why the word is wrong" is the shape to avoid. ' .
	'Never make a claim the essay does not make, and never soften a claim it does make. ' .
	'If the title contains the word "provenance", the card title keeps the word "provenance". ' .
	'Never use the phrase "proof of origin" in this title; that phrase belongs to prose and body headings only. ' .
	'Banned outright: em dashes in any form or spacing; the words delve, landscape, crucial, and leverage used as a verb. ' .
	'Output ONLY the title text. No quotes, no preamble, no labels, no markdown.';

const SNT_AI_OG_CARD_TITLE_MAX_TOKENS = 60;

/* ════════════════════════════════════════════════════════════════════════
 * FILTER — sn_og_card_title
 *
 * Wired at priority 10 so explicit higher-priority listeners can override.
 * Reads _sn_og_card_title post meta (the manual override + AI-write target);
 * if set, returns it; else returns the default ($post->post_title).
 * ════════════════════════════════════════════════════════════════════════ */

add_filter( 'sn_og_card_title', function( $default, $post_id ) {
	if ( ! function_exists( 'sn_post_settings_get_og_card_title' ) ) {
		return $default;
	}
	$override = sn_post_settings_get_og_card_title( (int) $post_id );
	return ( '' !== $override ) ? $override : $default;
}, 10, 2 );


/**
 * USER-facing entry: generate an OG card title for a post the caller may edit.
 *
 * Called by the signal-noise/ai-generate-og-card-title ability execute
 * callback (the pre-v5.0.0 bespoke REST handler is gone), which already
 * gates on edit_post upstream via the ability permission callback.
 * v6.39.2 adds an internal per-post cap check as defense-in-depth so the
 * impl can never write meta / regenerate a card for a post the current user
 * cannot edit, regardless of how it is reached.
 *
 * WP-Cron prepop must NOT use this entry — cron has no logged-in user, so the
 * cap check would reject it. The prepop engine calls snt_ai_og_card_title_write()
 * (the no-cap writer below) directly. See inc/ai-prepopulate.php.
 *
 * @param int $post_id
 * @return array{ok:bool,title:string,length:int,card_regenerated:bool,card_url:?string}|WP_Error
 * @since v2.5.0
 */
function snt_ai_og_card_title_impl( $post_id ) {
	if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
		return new WP_Error(
			'snt_ai_og_card_title_forbidden',
			__( 'You cannot edit this post.', 'signal-and-noise-tools' ),
			array( 'status' => 403 )
		);
	}
	return snt_ai_og_card_title_write( $post_id );
}

/**
 * No-cap internal writer: generate an OG card title via the WP AI Client +
 * persist the override meta + regenerate the card PNG.
 *
 * Gates ONLY on AI availability — NOT on any capability — so the WP-Cron
 * prepopulation path (snt_run_prepop(), which runs with no logged-in user)
 * can fill an empty OG card title. User-facing callers go through
 * snt_ai_og_card_title_impl() instead, which adds the edit_post cap.
 *
 * @param int $post_id
 * @return array{ok:bool,title:string,length:int,card_regenerated:bool,card_url:?string}|WP_Error
 * @since v6.39.2
 */
function snt_ai_og_card_title_write( $post_id ) {
	// v4.1.1 (D-03): shared AI-gate helper.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) { return $gate; }

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error(
			'snt_ai_post_not_found',
			__( 'Post not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	// Source for the prompt: the post title plus a small slice of the body
	// (first ~250 words) for context. Title alone often isn't enough for the
	// model to find a punchier variant — body content reveals what the
	// post is "really about" beyond a generic headline.
	$body_excerpt = function_exists( 'snt_ai_extract_post_text' )
		? snt_ai_extract_post_text( $post_id, 250 )
		: '';

	$prompt = "ARTICLE TITLE: " . $post->post_title . "\n\n";
	if ( '' !== $body_excerpt ) {
		$prompt .= "BODY EXCERPT (first 250 words): " . $body_excerpt . "\n\n";
	}
	$prompt .= "Generate the OG card title now.";

	$result = snt_ai_generate_with_constraints(
		$prompt,
		SNT_AI_OG_CARD_TITLE_SYSTEM,
		SNT_AI_OG_CARD_TITLE_MAX_TOKENS,
		'og_title'
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$title = trim( $result );
	$title = trim( $title, "\"'" );

	// Persist the override so the next card regeneration (and future
	// reads via the sn_og_card_title filter) pick it up.
	update_post_meta( $post_id, '_sn_og_card_title', $title );

	// Re-run the card generator immediately so the PNG on disk reflects
	// the new title without the user having to save the post. Quiet on
	// failure — sn_generate_og_card returns bool; we surface success/no
	// to the JS so the UI can tell the user whether the card refreshed.
	$card_regenerated = function_exists( 'sn_generate_og_card' )
		? (bool) sn_generate_og_card( $post_id )
		: false;

	$card_url = ( $card_regenerated && function_exists( 'sn_og_image_url_for_post' ) )
		? sn_og_image_url_for_post( $post )
		: null;

	return array(
		'ok'               => true,
		'title'            => $title,
		'length'           => strlen( $title ),
		'card_regenerated' => $card_regenerated,
		'card_url'         => $card_url,
	);
}


/* ════════════════════════════════════════════════════════════════════════
 * META-BOX UI INJECTION
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	// v9.81.0: shared helper (ai-bootstrap.php) replaces the drifted local copy.
	snt_ai_enqueue_editor_script( $hook_suffix, 'snt-ai-og-card-title', 'ai-og-card-title.js', 'sntAiOgCardTitle', array(
		'targetId' => 'sn_og_card_title',
	) );
} );
