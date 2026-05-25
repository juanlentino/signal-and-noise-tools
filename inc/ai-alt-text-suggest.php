<?php
/**
 * Signal & Noise Tools — AI-assisted alt-text suggest + apply.
 *
 * Two impl functions + two REST endpoints + two Abilities API
 * registrations (in inc/abilities-registration.php) that together
 * deliver Suggest+Apply UX for the missing_alt Health check.
 *
 * Surface convention follows ai-meta-description.php:
 *   - snt_ai_alt_suggest_impl() / snt_ai_alt_apply_impl() are single
 *     source of truth (pure functions)
 *   - REST endpoints under /signal-noise/v1/ai/alt-* wrap the impls
 *     for back-compat / non-JS callers
 *   - Abilities API (inc/abilities-registration.php) wraps the impls
 *     for the canonical AI-discoverable surface
 *   - JS module (assets/health-suggest-actions.js) calls the abilities
 *     REST URL via wp.apiFetch
 *
 * Concurrency: alt-text apply is a single update_post_meta() write
 * against _wp_attachment_image_alt. No fingerprint needed — attachment
 * alt is not versioned; last-write-wins is acceptable. Capability check
 * is current_user_can('edit_post', $attachment_id).
 *
 * @package SignalNoiseTools
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_AI_ALT_SUGGEST_SYSTEM = 'Generate descriptive alt text for an image. ' .
	'Output 80-125 characters. Describe the image factually, not the page it appears on. ' .
	'No "image of" / "picture of" / "photo of" preamble. ' .
	'No alt="" (empty) suggestions — if there is not enough context for a useful description, ' .
	'output only the literal marker: ALT_INSUFFICIENT_CONTEXT. ' .
	'Output ONLY the alt text or the marker — no quotes, no preamble, no markdown.';

const SNT_AI_ALT_SUGGEST_MAX_TOKENS = 80;
const SNT_AI_ALT_APPLY_MAX_LENGTH   = 250;

/**
 * Pure impl: generate alt text suggestion for an image attachment.
 *
 * Gathers: attachment title + caption + filename + first referencing
 * post title, sends to AI with the constraints in SNT_AI_ALT_SUGGEST_SYSTEM.
 * Returns the suggestion (or a WP_Error for any gate that fails).
 *
 * @param int $attachment_id
 * @return array{ok:bool,suggestion:string,attachment_id:int}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_unavailable          (503)
 *   snt_ai_not_attachment       (422)
 *   snt_ai_empty_post           (422) — no useful context to feed the AI
 *   snt_ai_insufficient_context (422) — AI returned the ALT_INSUFFICIENT_CONTEXT marker
 *   snt_ai_runtime_error        (500)
 *   snt_ai_empty_response       (502)
 *
 * @since 4.0.0
 */
function snt_ai_alt_suggest_impl( $attachment_id ) {
	if ( ! function_exists( 'snt_ai_can_text_generate' ) || ! snt_ai_can_text_generate() ) {
		return new WP_Error(
			'snt_ai_unavailable',
			__( 'AI text generation is not available. Upgrade to WordPress 7.0+ and configure a provider in Settings > Connectors.', 'signal-noise-tools' ),
			array( 'status' => 503 )
		);
	}

	$attachment = get_post( (int) $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error(
			'snt_ai_not_attachment',
			__( 'Not an image attachment.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}
	if ( 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
		return new WP_Error(
			'snt_ai_not_attachment',
			__( 'Attachment is not an image MIME type.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	// Build context: attachment title, caption, filename, parent post title (if any).
	$title    = trim( (string) $attachment->post_title );
	$caption  = trim( (string) $attachment->post_excerpt );
	$filename = wp_basename( (string) $attachment->guid );
	$parent_title = '';
	if ( $attachment->post_parent ) {
		$parent = get_post( (int) $attachment->post_parent );
		if ( $parent ) {
			$parent_title = trim( (string) $parent->post_title );
		}
	}

	// Look for the first published post that references this attachment's basename.
	global $wpdb;
	$referencing_post_title = '';
	if ( '' !== $filename ) {
		$referencing_post_title = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_title FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post','page')
			   AND post_content LIKE %s
			 LIMIT 1",
			'%' . $wpdb->esc_like( $filename ) . '%'
		) );
	}

	$context_parts = array_filter( array(
		$title    ? "Title: $title" : '',
		$caption  ? "Caption: $caption" : '',
		$filename ? "Filename: $filename" : '',
		$parent_title       ? "Parent post: $parent_title" : '',
		$referencing_post_title ? "Used in post: $referencing_post_title" : '',
	) );

	if ( empty( $context_parts ) ) {
		return new WP_Error(
			'snt_ai_empty_post',
			__( 'No context available — attachment has no title, caption, filename, or referencing posts.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$prompt = implode( "\n", $context_parts );
	$result = snt_ai_generate_with_constraints(
		$prompt,
		SNT_AI_ALT_SUGGEST_SYSTEM,
		SNT_AI_ALT_SUGGEST_MAX_TOKENS
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$suggestion = trim( (string) $result );
	$suggestion = trim( $suggestion, "\"'" ); // Strip surrounding quotes if model fights the prompt.

	if ( 'ALT_INSUFFICIENT_CONTEXT' === $suggestion ) {
		return new WP_Error(
			'snt_ai_insufficient_context',
			__( 'Not enough context for a useful alt-text suggestion. Try adding a title or caption to the attachment first.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	return array(
		'ok'            => true,
		'suggestion'    => $suggestion,
		'attachment_id' => (int) $attachment_id,
	);
}

/**
 * Pure impl: write alt text to an image attachment's _wp_attachment_image_alt meta.
 *
 * Validates input + capability. Uses update_post_meta() — note gotcha #10
 * in docs/WORDPRESS-REFERENCE.md: update_post_meta() returns false for BOTH
 * "no change" and "real error" cases. We disambiguate via $wpdb->last_error.
 *
 * @param int    $attachment_id
 * @param string $alt_text   Validated: trimmed non-empty, <= SNT_AI_ALT_APPLY_MAX_LENGTH chars.
 * @return array{ok:bool,attachment_id:int,written_alt:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_not_attachment (422)
 *   snt_ai_alt_empty      (422)
 *   snt_ai_alt_too_long   (422)
 *   snt_ai_capability     (403)
 *   snt_ai_write_failed   (500)
 *
 * @since 4.0.0
 */
function snt_ai_alt_apply_impl( $attachment_id, $alt_text ) {
	$attachment_id = (int) $attachment_id;
	$alt_text      = trim( (string) $alt_text );

	if ( '' === $alt_text ) {
		return new WP_Error( 'snt_ai_alt_empty', __( 'Alt text is empty.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( strlen( $alt_text ) > SNT_AI_ALT_APPLY_MAX_LENGTH ) {
		return new WP_Error( 'snt_ai_alt_too_long', sprintf( __( 'Alt text exceeds %d characters.', 'signal-noise-tools' ), SNT_AI_ALT_APPLY_MAX_LENGTH ), array( 'status' => 422 ) );
	}
	if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
		return new WP_Error( 'snt_ai_capability', __( 'You cannot edit this attachment.', 'signal-noise-tools' ), array( 'status' => 403 ) );
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error( 'snt_ai_not_attachment', __( 'Not an attachment.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}

	global $wpdb;
	$written = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

	// update_post_meta returns false for "no change" AND for "real error".
	// Disambiguate via $wpdb->last_error per WORDPRESS-REFERENCE.md gotcha #10.
	if ( false === $written && ! empty( $wpdb->last_error ) ) {
		return new WP_Error( 'snt_ai_write_failed', sprintf( __( 'Database write failed: %s', 'signal-noise-tools' ), $wpdb->last_error ), array( 'status' => 500 ) );
	}

	return array(
		'ok'            => true,
		'attachment_id' => $attachment_id,
		'written_alt'   => $alt_text,
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoints — back-compat surface for non-JS callers (CI, wp-cli).
 * JS clients use the Abilities API REST surface via wp.apiFetch.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/ai/alt-suggest', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_ai_alt_suggest_impl( (int) $request->get_param( 'attachment_id' ) );
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( $result );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'attachment_id' ) );
		},
		'args' => array(
			'attachment_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; },
			),
		),
	) );

	register_rest_route( 'signal-noise/v1', '/ai/alt-apply', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_ai_alt_apply_impl(
				(int) $request->get_param( 'attachment_id' ),
				(string) $request->get_param( 'alt_text' )
			);
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( $result );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'attachment_id' ) );
		},
		'args' => array(
			'attachment_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'alt_text' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
} );
