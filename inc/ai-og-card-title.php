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
 *   1. REST endpoint:  POST signal-noise/v1/ai/generate-og-card-title
 *      Body: { post_id: int }
 *      Returns: { ok: true, title: string, card_url: string|null }
 *      Side effect: writes _sn_og_card_title AND re-runs sn_generate_og_card
 *                   so the PNG on disk picks up the new title immediately.
 *      Permission: edit_post for the given post_id
 *
 *   2. Meta-box button: rendered inside the per-post SN meta box next to
 *      the OG card title textarea (post-settings.php:OG card title). JS
 *      at assets/ai-og-card-title.js calls the REST endpoint via
 *      wp.apiFetch, fills the textarea on success, shows inline status.
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

const SNT_AI_OG_CARD_TITLE_SYSTEM = 'Generate a punchier, shorter version of an article title for a social-share card image. Constraints: ' .
	'(1) 60-90 characters total — count carefully; ' .
	'(2) Direct and declarative — active voice, no fluff; ' .
	'(3) Drop subtitles after a colon if they pad length unnecessarily, but only if the meaning survives; ' .
	'(4) Match the source title\'s voice — don\'t turn an editorial title into generic clickbait; ' .
	'(5) Avoid words: amazing, ultimate, best, powerful, transformative, revolutionary, cutting-edge; ' .
	'(6) No quotes, no preamble, no "OG title:" labels, no markdown. ' .
	'Output ONLY the title text.';

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

/* ════════════════════════════════════════════════════════════════════════
 * REST ENDPOINT — signal-noise/v1/ai/generate-og-card-title
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/ai/generate-og-card-title', array(
		'methods'             => 'POST',
		'callback'            => 'snt_ai_og_card_title_rest_handler',
		'permission_callback' => 'snt_ai_og_card_title_rest_permission',
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		),
	) );
} );

function snt_ai_og_card_title_rest_permission( WP_REST_Request $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	return current_user_can( 'edit_post', $post_id );
}

/**
 * Generate an OG card title via the WP AI Client + persist + regenerate card.
 *
 * Pure function called by both the (@deprecated since 2.5.0) REST handler
 * AND the signal-noise/ai-generate-og-card-title ability execute callback.
 *
 * @param int $post_id
 * @return array{ok:bool,title:string,length:int,card_regenerated:bool,card_url:?string}|WP_Error
 * @since v2.5.0
 */
function snt_ai_og_card_title_impl( $post_id ) {
	if ( ! function_exists( 'snt_ai_can_text_generate' ) || ! snt_ai_can_text_generate() ) {
		return new WP_Error(
			'snt_ai_unavailable',
			__( 'AI text generation is not available. Upgrade to WordPress 7.0+ and configure a provider in Settings > Connectors.', 'signal-noise-tools' ),
			array( 'status' => 503 )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error(
			'snt_ai_post_not_found',
			__( 'Post not found.', 'signal-noise-tools' ),
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
		SNT_AI_OG_CARD_TITLE_MAX_TOKENS
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

/**
 * @deprecated since 2.5.0 — prefer
 *   POST /wp-abilities/v1/signal-noise/ai-generate-og-card-title/run
 * via the WP Abilities REST surface. This endpoint stays wired for
 * back-compat with v2.3.0+ JS clients on installs running pre-v2.5.0.
 */
function snt_ai_og_card_title_rest_handler( WP_REST_Request $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$result  = snt_ai_og_card_title_impl( $post_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result );
}

/* ════════════════════════════════════════════════════════════════════════
 * META-BOX UI INJECTION
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}
	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_register_script(
		'snt-ai-og-card-title',
		plugins_url( 'assets/ai-og-card-title.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'wp-i18n' ),
		SNT_VERSION,
		true
	);

	wp_localize_script( 'snt-ai-og-card-title', 'sntAiOgCardTitle', array(
		'restPath' => '/signal-noise/v1/ai/generate-og-card-title',
		'targetId' => 'sn_og_card_title',
	) );

	wp_enqueue_script( 'snt-ai-og-card-title' );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-ai-og-card-title', 'signal-noise-tools' );
	}
} );
