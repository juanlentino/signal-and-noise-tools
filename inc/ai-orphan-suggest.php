<?php
/**
 * Signal & Noise Tools — AI-assisted orphan-media suggest + apply.
 *
 * Sibling to inc/ai-alt-text-suggest.php (v4.0.0). This impl evaluates
 * SQL-flagged orphan attachments via the AI Client and returns a
 * binary-ish verdict (delete/keep/unsure) instead of a freeform string
 * suggestion. The apply impl is a destructive force-delete.
 *
 * Surface convention mirrors inc/ai-alt-text-suggest.php:
 *   - snt_ai_orphan_suggest_impl() / snt_ai_orphan_apply_impl() are
 *     single sources of truth (pure functions)
 *   - REST endpoints under /signal-noise/v1/ai/orphan-* wrap the impls
 *     for back-compat / non-JS callers
 *   - Abilities API (inc/abilities-registration.php) wraps the impls
 *     for the canonical AI-discoverable surface
 *   - JS (assets/health-suggest-actions.js) calls the abilities REST URL
 *
 * Cache: 30-day transient per (attachment_id, post_modified, md5(SYSTEM))
 * mirroring v4.0.1 drift pattern.
 *
 * @package SignalNoiseTools
 * @since 4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_AI_ORPHAN_SUGGEST_SYSTEM = "You are evaluating whether a WordPress media attachment is safe to delete.\n\n" .
	"Context provided: filename, title, caption, MIME type, upload date, parent post title (if any).\n\n" .
	"The attachment has already been confirmed via SQL to be:\n" .
	"- NOT used as any post's featured image\n" .
	"- NOT referenced (by basename) in any published post body\n" .
	"- Older than 7 days\n\n" .
	"Your job: weigh whether the attachment is genuinely orphaned (safe to delete) or might still be in use somewhere the SQL didn't catch (widgets, customizer settings, theme.json refs, template parts).\n\n" .
	"Return ONLY a JSON object on a single line: {\"verdict\": \"delete\"|\"keep\"|\"unsure\", \"reason\": \"<one short sentence>\"}\n\n" .
	"Rules:\n" .
	"- \"delete\": filename + metadata suggest a one-off upload that was never integrated (e.g., screenshot-2024-03.png with no caption, no parent post)\n" .
	"- \"keep\": filename or metadata suggest a meaningful asset that may be referenced outside post_content (logo-final.svg, hero-banner.jpg, site-logo-*)\n" .
	"- \"unsure\": insufficient signal to recommend either way\n" .
	"- Be conservative: if in doubt, return \"unsure\" not \"delete\"\n" .
	"- Output JSON only. No markdown, no preamble, no trailing text.";

const SNT_AI_ORPHAN_SUGGEST_MAX_TOKENS = 120;
const SNT_AI_ORPHAN_CACHE_TTL          = 30 * DAY_IN_SECONDS;

/**
 * Pure impl: AI verdict for whether an orphan-flagged attachment is safe to delete.
 *
 * @param int $attachment_id
 * @return array{ok:true,verdict:string,reason:string,attachment_id:int,thumbnail_url:string,filename:string}|WP_Error
 *
 * @since 4.1.0
 */
function snt_ai_orphan_suggest_impl( $attachment_id ) {
	$attachment_id = (int) $attachment_id;

	// Gate: AI client available.
	// v4.1.1 (D-03): shared AI-gate helper.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) { return $gate; }

	// Gate: attachment exists and is an image.
	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error( 'snt_ai_not_attachment', __( 'Not an image attachment.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
		return new WP_Error( 'snt_ai_not_attachment', __( 'Attachment is not an image MIME type.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	// Cache lookup. Mirrors v4.0.1 drift cache shape.
	$cache_key      = 'sn_orphan_verdict_' . $attachment_id;
	$post_modified  = (string) $attachment->post_modified_gmt;
	$prompt_version = md5( SNT_AI_ORPHAN_SUGGEST_SYSTEM );
	$cached         = get_transient( $cache_key );

	if ( is_array( $cached )
		&& isset( $cached['modified'], $cached['prompt_version'], $cached['payload'] )
		&& $cached['modified']       === $post_modified
		&& $cached['prompt_version'] === $prompt_version
		&& is_array( $cached['payload'] ) ) {
		return $cached['payload'];
	}

	// Build prompt context.
	$filename     = wp_basename( (string) $attachment->guid );
	$title        = trim( (string) $attachment->post_title );
	$caption      = trim( (string) $attachment->post_excerpt );
	$mime         = (string) $attachment->post_mime_type;
	$upload_date  = substr( (string) $attachment->post_date_gmt, 0, 10 );
	$parent_title = '';
	if ( $attachment->post_parent ) {
		$parent = get_post( (int) $attachment->post_parent );
		if ( $parent ) {
			$parent_title = trim( (string) $parent->post_title );
		}
	}

	$context_parts = array_filter( array(
		$filename     ? "Filename: $filename"          : '',
		$title        ? "Title: $title"                : '',
		$caption      ? "Caption: $caption"            : '',
		$mime         ? "MIME: $mime"                  : '',
		$upload_date  ? "Uploaded: $upload_date"       : '',
		$parent_title ? "Parent post: $parent_title"   : '',
	) );

	$prompt = implode( "\n", $context_parts );
	$raw    = snt_ai_generate_with_constraints( $prompt, SNT_AI_ORPHAN_SUGGEST_SYSTEM, SNT_AI_ORPHAN_SUGGEST_MAX_TOKENS, 'orphan_suggest' );

	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$text = trim( (string) $raw );
	if ( '' === $text ) {
		return new WP_Error( 'snt_ai_empty_response', __( 'AI returned empty response.', 'signal-and-noise-tools' ), array( 'status' => 502 ) );
	}

	// Strip optional markdown fences (same defensive pattern as v4.0.1 drift).
	$text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text ) );

	$parsed  = json_decode( $text, true );
	$verdict = is_array( $parsed ) && isset( $parsed['verdict'] ) ? (string) $parsed['verdict'] : '';
	$reason  = is_array( $parsed ) && isset( $parsed['reason'] )  ? (string) $parsed['reason']  : '';

	// Fallback to 'unsure' for unparseable / out-of-enum responses. Preserves the finding.
	if ( ! in_array( $verdict, array( 'delete', 'keep', 'unsure' ), true ) ) {
		$verdict = 'unsure';
		$reason  = __( 'AI response could not be parsed; review manually', 'signal-and-noise-tools' );
	}

	$payload = array(
		'ok'            => true,
		'verdict'       => $verdict,
		'reason'        => $reason,
		'attachment_id' => $attachment_id,
		'thumbnail_url' => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
		'filename'      => $filename,
	);

	// Cache only successful parses (including unsure-fallbacks). Errors never cache.
	set_transient( $cache_key, array(
		'modified'       => $post_modified,
		'prompt_version' => $prompt_version,
		'payload'        => $payload,
	), SNT_AI_ORPHAN_CACHE_TTL );

	return $payload;
}

/**
 * Pure impl: force-delete an orphan attachment.
 *
 * Defense-in-depth: re-checks delete_post capability + attachment existence
 * even though the Abilities API permission_callback already gates the call.
 * The inner check protects direct PHP callers (wp-cli, REST endpoint in
 * signal-noise/v1/ai/orphan-apply, or future code bypassing Abilities).
 *
 * @param int $attachment_id
 * @return array{ok:true,attachment_id:int,deleted:true}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_capability      (403) — caller lacks delete_post on $attachment_id
 *   snt_ai_not_attachment  (422) — post doesn't exist or isn't an attachment (TOCTOU)
 *   snt_ai_delete_failed   (500) — wp_delete_attachment() returned false/null
 *
 * @since 4.1.0
 */
function snt_ai_orphan_apply_impl( $attachment_id ) {
	$attachment_id = (int) $attachment_id;

	if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
		return new WP_Error( 'snt_ai_capability', __( 'You cannot delete this attachment.', 'signal-and-noise-tools' ), array( 'status' => 403 ) );
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error( 'snt_ai_not_attachment', __( 'Attachment not found or already deleted.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	// wp_delete_attachment() returns WP_Post|false in WP 7.0; null guard is
	// defensive for any future return-type change.
	$result = wp_delete_attachment( $attachment_id, true );
	if ( false === $result || null === $result ) {
		return new WP_Error( 'snt_ai_delete_failed', __( 'Delete failed. Check file permissions on uploads/.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}

	delete_transient( 'sn_orphan_verdict_' . $attachment_id );

	return array(
		'ok'            => true,
		'attachment_id' => $attachment_id,
		'deleted'       => true,
	);
}
