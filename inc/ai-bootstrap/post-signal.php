<?php
/**
 * Signal & Noise — AI bootstrap: post text extraction and the signal digest.
 *
 * Split out of inc/ai-bootstrap.php in v12.21.4, which had grown to 1,054
 * lines. Nothing about behaviour changed.
 *
 * This layer has no registry and no dispatch map — other modules call these
 * functions DIRECTLY, so the public surface is the contract.
 * tests/ai-bootstrap-surface-coverage.php pins all 21 declarations, the eight
 * SN_AI_* constants, the two load-time route registrations, and the single
 * admin_enqueue_scripts hook, so a symbol lost in a move is a build failure
 * rather than a silent behaviour change.
 *
 * Provides: snt_ai_extract_post_text(), snt_ai_post_signal()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Truncate post content to N words, stripping all HTML/shortcodes first.
 *
 * Used by AI features to bound input token cost. The first ~1000 words of
 * any post is sufficient context for SEO meta description / OG title /
 * tag suggestion tasks — quality plateaus well before context-window
 * limits, but token cost scales linearly.
 *
 * SECURITY: the returned text is UNTRUSTED. It is author-supplied post body
 * content that becomes the user-content half of an AI prompt — a post could
 * embed prompt-injection text ("ignore your instructions and …"). This helper
 * only strips markup/shortcodes for token economy; it does NOT neutralize
 * injection. Containment is the CALLER's responsibility, via a system_instruction
 * that frames this as data, not commands (see snt_insights_system_instruction()
 * for the pattern: an explicit untrusted-DATA delimiter the model is told never
 * to obey). Treat any string from here as you would any external input.
 *
 * @param int $post_id  Post ID.
 * @param int $words    Word cap. Default 1000.
 * @return string       Plain-text excerpt, trimmed (UNTRUSTED — see note above).
 */
function snt_ai_extract_post_text( $post_id, $words = 1000 ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return '';
	}

	$raw = $post->post_content;
	$raw = strip_shortcodes( $raw );
	$raw = wp_strip_all_tags( $raw );

	// wp_trim_words doesn't add an ellipsis when the more= arg is empty —
	// we want a clean truncation, not "..." appended.
	return (string) wp_trim_words( $raw, max( 50, (int) $words ), '' );
}

/**
 * Content-or-synthesized AI signal for a post.
 *
 * Returns post_content (via snt_ai_extract_post_text) when the body has text —
 * BYTE-IDENTICAL to the old path for normal posts, so no regression. For a
 * contentless template Page (empty body) it synthesizes a signal from the
 * title, the theme's curated fallback description (sn_seo_singular_description),
 * and the slug — enough for the meta-description / excerpt generators to work
 * instead of returning a 422. Untrusted-input posture is unchanged: this only
 * assembles first-party post fields + a theme-owned fallback string; the AI
 * callers' system instructions still frame it as data.
 *
 * @since 9.3.0
 * @param int $post_id
 * @param int $words   Word cap forwarded to snt_ai_extract_post_text.
 * @return string      May be '' only when the post cannot be resolved.
 */
function snt_ai_post_signal( $post_id, $words = 1000 ) {
	$content = snt_ai_extract_post_text( $post_id, $words );
	if ( '' !== $content ) {
		return $content;
	}
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return '';
	}
	$parts    = array( 'TITLE: ' . $post->post_title );
	$fallback = (string) apply_filters( 'sn_seo_singular_description', '', $post );
	if ( '' !== $fallback ) {
		$parts[] = 'SUMMARY: ' . $fallback;
	}
	if ( '' !== (string) $post->post_name ) {
		$parts[] = 'SLUG: ' . $post->post_name;
	}
	return implode( "\n", $parts );
}
