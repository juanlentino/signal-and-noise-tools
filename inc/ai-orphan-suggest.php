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
	// TASK 2 will replace this stub with the real impl.
	return new WP_Error( 'snt_ai_not_implemented', 'Stub.', array( 'status' => 501 ) );
}

/**
 * Pure impl: force-delete an orphan attachment.
 *
 * @param int $attachment_id
 * @return array{ok:true,attachment_id:int,deleted:true}|WP_Error
 *
 * @since 4.1.0
 */
function snt_ai_orphan_apply_impl( $attachment_id ) {
	// TASK 4 will replace this stub with the real impl.
	return new WP_Error( 'snt_ai_not_implemented', 'Stub.', array( 'status' => 501 ) );
}
