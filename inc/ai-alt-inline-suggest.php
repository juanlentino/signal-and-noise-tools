<?php
/**
 * Signal & Noise Tools — AI-assisted inline-<img> alt suggest.
 *
 * Sibling to inc/ai-alt-text-suggest.php (v4.0.0). The attachment-alt
 * impl keys off an attachment_id and gathers context from the attachment
 * record (title, caption, filename, parent post). This impl keys off
 * (post_id, image_src) because inline-<img> findings have no attachment
 * — they're <img src="..."> tags in post_content where the src may or
 * may not point at a media-library attachment.
 *
 * Context gathering: ~500 chars centered on the image src position in
 * post_content, with HTML and shortcodes stripped. Plus post title +
 * filename from the URL.
 *
 * No Apply impl ships in v4.0.2 (or any v4.x). Editing post_content to
 * mutate inline <img alt="..."> is a block-serialization-roundtrip
 * problem (WORDPRESS-REFERENCE.md gotcha #4). v4.0.2 ships
 * Suggest + Copy only — user pastes manually in the editor.
 *
 * Surface convention mirrors inc/ai-alt-text-suggest.php:
 *   - snt_ai_alt_inline_suggest_impl() is single source of truth
 *   - REST endpoint under /signal-noise/v1/ai/alt-inline-suggest wraps it
 *   - Abilities API (inc/abilities-registration.php) wraps it
 *   - JS calls the abilities REST URL via wp.apiFetch
 *
 * @package SignalNoiseTools
 * @since 4.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Composes from the shared SNT_AI_ALT_BASE_RULES (owned by the primary
// inc/ai-alt-text-suggest.php, which loads first) + this surface's own framing:
// the image is an inline <img> referenced by URL with no attachment record, so
// the context is the surrounding paragraph + the URL filename.
const SNT_AI_ALT_INLINE_SUGGEST_SYSTEM = 'Generate descriptive alt text for an inline image in a post body. ' .
	'Output 80-125 characters. Describe what is visible in the attached image when one is present; otherwise describe it factually from the surrounding paragraph context + the URL filename. ' .
	SNT_AI_ALT_BASE_RULES;

const SNT_AI_ALT_INLINE_SUGGEST_MAX_TOKENS    = 80;
const SNT_AI_ALT_INLINE_SUGGEST_CONTEXT_CHARS = 500;

/**
 * Extract ~$window chars of stripped paragraph context centered on the
 * first occurrence of $image_src in $post_content.
 *
 * Strategy: take a 2x-wider window of raw post_content around the src
 * position, then strip shortcodes + HTML + collapse whitespace, then
 * truncate to $window chars at a word boundary.
 *
 * Returns '' if $image_src isn't found in $post_content (caller checks
 * + returns snt_ai_img_not_found WP_Error) or if the stripped context
 * is empty.
 *
 * @param string $post_content
 * @param string $image_src
 * @param int    $window  Target chars after strip; default 500.
 * @return string
 *
 * @since 4.0.2
 */
function snt_ai_extract_inline_img_context( $post_content, $image_src, $window = SNT_AI_ALT_INLINE_SUGGEST_CONTEXT_CHARS ) {
	$haystack = (string) $post_content;
	$needle   = (string) $image_src;
	if ( '' === $needle ) {
		return '';
	}

	$pos = strpos( $haystack, $needle );
	if ( false === $pos ) {
		return '';  // src not in post — caller returns snt_ai_img_not_found.
	}

	// Take a 2x-wider raw window because strip can compress significantly.
	$wide_start = max( 0, $pos - ( $window * 2 ) );
	$wide_end   = min( strlen( $haystack ), $pos + $window * 2 );
	$wide       = substr( $haystack, $wide_start, $wide_end - $wide_start );

	// Strip shortcodes first (their content may contain HTML that strip_all_tags shouldn't see),
	// then strip HTML/markup, then collapse whitespace.
	if ( function_exists( 'strip_shortcodes' ) ) {
		$wide = strip_shortcodes( $wide );
	}
	$stripped = wp_strip_all_tags( $wide );
	$stripped = trim( preg_replace( '/\s+/', ' ', $stripped ) );

	// Truncate to $window chars at a word boundary.
	if ( strlen( $stripped ) > $window ) {
		$stripped = substr( $stripped, 0, $window );
		$last_space = strrpos( $stripped, ' ' );
		if ( false !== $last_space && $last_space > $window - 50 ) {
			$stripped = substr( $stripped, 0, $last_space );
		}
	}

	return $stripped;
}

/**
 * Pure impl: generate alt text suggestion for an inline <img> in a post body.
 *
 * @param int    $post_id   The post containing the inline <img>.
 * @param string $image_src The <img src="..."> URL as it appears in post_content.
 * @return array{ok:true,suggestion:string,post_id:int,image_src:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_unavailable          (503) — AI client / provider not configured
 *   snt_ai_post_not_found       (404) — post_id doesn't resolve
 *   snt_ai_img_not_found        (422) — image_src isn't in post_content
 *   snt_ai_empty_post           (422) — context after strip is empty
 *   snt_ai_insufficient_context (422) — AI returned ALT_INSUFFICIENT_CONTEXT marker
 *   snt_ai_runtime_error        (500)
 *   snt_ai_empty_response       (502)
 *
 * @since 4.0.2
 */
function snt_ai_alt_inline_suggest_impl( $post_id, $image_src ) {
	// v4.1.1 (D-03): shared AI-gate helper.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) { return $gate; }

	$post_id   = (int) $post_id;
	$image_src = (string) $image_src;

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Post not found.', 'signal-noise-tools' ), array( 'status' => 404 ) );
	}

	$post_content = (string) $post->post_content;
	$post_title   = trim( (string) $post->post_title );

	// Verify the image src is still present in post_content (handles
	// post-edited-since-scan + general bad input).
	if ( false === strpos( $post_content, $image_src ) ) {
		return new WP_Error(
			'snt_ai_img_not_found',
			__( 'Image src no longer found in post content — post may have been edited since the scan. Re-run the Health scan to refresh.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$context = snt_ai_extract_inline_img_context( $post_content, $image_src );
	if ( '' === $context ) {
		return new WP_Error(
			'snt_ai_empty_post',
			__( 'No usable context around the image — post body may be too short.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$filename = wp_basename( $image_src );

	$context_parts = array_filter( array(
		$filename   ? "Filename: $filename" : '',
		$post_title ? "Post: $post_title"   : '',
		"Context: $context",
	) );

	// v6.48.0: if the inline <img> URL resolves to a LOCAL media-library
	// attachment, attach a downscaled copy for vision (shared resolver). An
	// EXTERNAL or unresolvable URL stays text-only — we NEVER hand the raw URL to
	// the provider (the SDK would forward it as a remote reference and a
	// provider-side fetch could land on a Cloudflare challenge and corrupt it).
	// The 'alt-text' feature tag routes to the Gemini vision model either way.
	$image_path = '';
	$image_mime = '';
	if ( function_exists( 'snt_ai_alt_resolve_image_file' ) && function_exists( 'attachment_url_to_postid' ) ) {
		$inline_attachment_id = (int) attachment_url_to_postid( $image_src );
		if ( $inline_attachment_id > 0 ) {
			$image      = snt_ai_alt_resolve_image_file( $inline_attachment_id );
			$image_path = $image['path'];
			$image_mime = $image['mime'];
		}
	}

	$prompt = implode( "\n", $context_parts );
	$result = snt_ai_generate_with_constraints(
		$prompt,
		SNT_AI_ALT_INLINE_SUGGEST_SYSTEM,
		SNT_AI_ALT_INLINE_SUGGEST_MAX_TOKENS,
		'alt-text',
		$image_path,
		$image_mime
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$suggestion = (string) $result;  // v4.1.6 (D-10): quote-strip now happens in snt_ai_generate_with_constraints().

	if ( 'ALT_INSUFFICIENT_CONTEXT' === $suggestion ) {
		return new WP_Error(
			'snt_ai_insufficient_context',
			__( 'Not enough context for a useful alt-text suggestion. Try adding more text around the image first.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	return array(
		'ok'         => true,
		'suggestion' => $suggestion,
		'post_id'    => $post_id,
		'image_src'  => $image_src,
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoint — back-compat surface for non-JS callers.
 * JS clients use the Abilities API REST surface via wp.apiFetch.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/ai/alt-inline-suggest', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_ai_alt_inline_suggest_impl(
				(int) $request->get_param( 'post_id' ),
				(string) $request->get_param( 'image_src' )
			);
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( $result );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
		},
		'args' => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'image_src' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
} );
