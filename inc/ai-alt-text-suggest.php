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

// Shared alt-text rules common to BOTH alt abilities (attachment + inline-<img>).
// Owned here in the primary alt file (loaded first in signal-and-noise-tools.php)
// so the guidance lives in ONE place and cannot drift between the two prompts —
// inc/ai-alt-inline-suggest.php composes its system instruction from this base.
// The two abilities are one capability split by image SOURCE, not two policies.
const SNT_AI_ALT_BASE_RULES = 'No "image of" / "picture of" / "photo of" preamble. ' .
	'No alt="" (empty) suggestions — if there is not enough context for a useful description, ' .
	'output only the literal marker: ALT_INSUFFICIENT_CONTEXT. ' .
	'Output ONLY the alt text or the marker — no quotes, no preamble, no markdown.';

// v6.48.0: vision. The attached image (when present) is the primary subject; the
// text context (title/caption/filename) is supplementary disambiguation only.
// SNT_AI_ALT_BASE_RULES already carries the ALT_INSUFFICIENT_CONTEXT marker rule +
// the no-preamble / no-quotes output contract, so it is NOT repeated here.
const SNT_AI_ALT_SUGGEST_SYSTEM = 'Generate descriptive alt text for the attached image. ' .
	'Output 80-125 characters. Describe what is visible in the image factually, not the page it appears on; use any provided text context only to disambiguate names or specifics you cannot see. ' .
	SNT_AI_ALT_BASE_RULES;

const SNT_AI_ALT_SUGGEST_MAX_TOKENS = 80;
const SNT_AI_ALT_APPLY_MAX_LENGTH   = 250;

/**
 * v6.48.0: resolve a DOWNSCALED LOCAL image file for an attachment, for vision.
 *
 * Returns ['path' => absolute readable path, 'mime' => normalized mime], or empty
 * strings when the attachment has no readable local image (id <= 0, broken file,
 * etc.). Prefers a sized-down variant (large → medium_large → medium) to bound
 * vision token cost + stay within provider image-size caps, falling back to the
 * full-res original. The ABSOLUTE path is rebuilt from dirname(get_attached_file())
 * + basename — image_get_intermediate_size()'s own 'path' key is RELATIVE to the
 * uploads basedir (a core trap). Legacy 'image/jpg' is normalized to 'image/jpeg'.
 * Returns a LOCAL path only — callers pass it to ->with_file(), which base64-inlines
 * it, so the provider never fetches a URL (Cloudflare-challenge-safe).
 *
 * Shared by the attachment-alt impl and the inline-<img> impl (which first maps its
 * URL to a local attachment id via attachment_url_to_postid()).
 *
 * @param int $attachment_id
 * @return array{path:string,mime:string}
 *
 * @since 6.48.0
 */
function snt_ai_alt_resolve_image_file( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 ) {
		return array( 'path' => '', 'mime' => '' );
	}

	// v6.48.0: only ever resolve an IMAGE attachment — never base64-inline a
	// non-image media file (PDF/doc/etc.) to the vision model. The attachment impl
	// pre-checks this, but the inline-<img> impl (and any future caller) relies on
	// this guard, so it lives in the shared resolver. Legacy 'image/jpg' normalizes
	// to the canonical 'image/jpeg'.
	$mime = (string) get_post_mime_type( $attachment_id );
	if ( 'image/jpg' === $mime ) {
		$mime = 'image/jpeg';
	}
	if ( 0 !== strpos( $mime, 'image/' ) ) {
		return array( 'path' => '', 'mime' => '' );
	}

	$original = (string) get_attached_file( $attachment_id );
	$path     = '';
	foreach ( array( 'large', 'medium_large', 'medium' ) as $size ) {
		$intermediate = image_get_intermediate_size( $attachment_id, $size );
		if ( is_array( $intermediate ) && ! empty( $intermediate['file'] ) && '' !== $original ) {
			// Rebuild the ABSOLUTE path: the sized variant lives in the same dir as
			// the original. ($intermediate['path'] is RELATIVE to uploads — do NOT use it.)
			$candidate = dirname( $original ) . '/' . basename( (string) $intermediate['file'] );
			if ( is_readable( $candidate ) ) {
				$path = $candidate;
				break;
			}
		}
	}
	if ( '' === $path && '' !== $original && is_readable( $original ) ) {
		$path = $original;
	}

	// v6.48.2: never inline an OVERSIZED file. base64-encoding a multi-MB image to
	// send to the provider can exhaust PHP's memory_limit — an UNCATCHABLE fatal
	// (it bypasses the seam's try/catch and surfaces as the WordPress "critical
	// error" page on that one image) — and it exceeds the provider's inline-image
	// cap. The sized variants resolved above are small; this only bites the
	// fall-back-to-original case for a huge original. Over the cap → degrade to
	// text-only (return no image) rather than risk the fatal. Filterable.
	if ( '' !== $path ) {
		$bytes = @filesize( $path );
		$cap   = (int) apply_filters( 'snt_ai_alt_image_max_bytes', 5 * 1024 * 1024 );
		if ( false === $bytes || $bytes > $cap ) {
			$path = '';
		}
	}

	return array(
		'path' => $path,
		'mime' => ( '' !== $path ) ? $mime : '',
	);
}

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
	// v4.1.1 (D-03): shared AI-gate helper. Centralizes the snt_ai_unavailable
	// WP_Error so the message stays consistent across all AI impls.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) { return $gate; }

	$attachment = get_post( (int) $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error(
			'snt_ai_not_attachment',
			__( 'Not an image attachment.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}
	if ( 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
		return new WP_Error(
			'snt_ai_not_attachment',
			__( 'Attachment is not an image MIME type.', 'signal-and-noise-tools' ),
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

	// v6.48.0: attach a DOWNSCALED LOCAL copy of the image for vision. The
	// 'alt-text' feature tag (passed to the seam below) routes this call to a
	// multimodal model (Gemini Flash) that describes what is actually in the
	// picture; the text context above stays as supplementary disambiguation.
	$image      = snt_ai_alt_resolve_image_file( $attachment_id );
	$image_path = $image['path'];
	$image_mime = $image['mime'];
	// is_readable() mirrors the seam's own guard (snt_ai_generate_with_constraints)
	// so the empty-context bail + minimal-prompt path here can't disagree with
	// whether the seam will actually attach the image.
	$has_image  = ( '' !== $image_path && '' !== $image_mime && is_readable( $image_path ) );

	// With vision the IMAGE is the primary context, so only bail when there is
	// NEITHER usable text context NOR a readable image to describe.
	if ( empty( $context_parts ) && ! $has_image ) {
		return new WP_Error(
			'snt_ai_empty_post',
			__( 'No context available — attachment has no readable image, title, caption, filename, or referencing posts.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$prompt = implode( "\n", $context_parts );
	if ( '' === $prompt ) {
		// Image-only (no text metadata): give the model a minimal user turn.
		$prompt = 'Describe the attached image for alt text.';
	}
	$result = snt_ai_generate_with_constraints(
		$prompt,
		SNT_AI_ALT_SUGGEST_SYSTEM,
		SNT_AI_ALT_SUGGEST_MAX_TOKENS,
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
			__( 'Not enough context for a useful alt-text suggestion. Try adding a title or caption to the attachment first.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	return array(
		'ok'            => true,
		'suggestion'    => $suggestion,
		'attachment_id' => (int) $attachment_id,
		'thumbnail_url' => (string) wp_get_attachment_image_url( (int) $attachment_id, 'thumbnail' ),
		'filename'      => (string) wp_basename( (string) get_attached_file( (int) $attachment_id ) ),
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
		return new WP_Error( 'snt_ai_alt_empty', __( 'Alt text is empty.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( strlen( $alt_text ) > SNT_AI_ALT_APPLY_MAX_LENGTH ) {
		/* translators: %d is the maximum allowed number of characters */
		return new WP_Error( 'snt_ai_alt_too_long', sprintf( __( 'Alt text exceeds %d characters.', 'signal-and-noise-tools' ), SNT_AI_ALT_APPLY_MAX_LENGTH ), array( 'status' => 422 ) );
	}
	if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
		return new WP_Error( 'snt_ai_capability', __( 'You cannot edit this attachment.', 'signal-and-noise-tools' ), array( 'status' => 403 ) );
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error( 'snt_ai_not_attachment', __( 'Not an attachment.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	global $wpdb;
	$written = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

	// update_post_meta returns false for "no change" AND for "real error".
	// Disambiguate via $wpdb->last_error per WORDPRESS-REFERENCE.md gotcha #10.
	if ( false === $written && ! empty( $wpdb->last_error ) ) {
		/* translators: %s is the database error message */
		return new WP_Error( 'snt_ai_write_failed', sprintf( __( 'Database write failed: %s', 'signal-and-noise-tools' ), $wpdb->last_error ), array( 'status' => 500 ) );
	}

	return array(
		'ok'            => true,
		'attachment_id' => $attachment_id,
		'written_alt'   => $alt_text,
	);
}
