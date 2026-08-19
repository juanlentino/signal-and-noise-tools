<?php
/**
 * Signal & Noise Tools — Abilities API permission callbacks.
 *
 * Named-function replacements for the closure-based permission checks
 * that used to live as `$permission_*` local variables inside the
 * `wp_abilities_api_init` action callback in inc/abilities-registration.php.
 *
 * The v4.1.3 abilities-registration split (audit B-11) moved the 26+
 * `wp_register_ability()` calls out of one 1660-line monolith into 8
 * per-feature files. Each ability's permission_callback now references
 * one of the named functions below as a string callable, so a single
 * permission-rule audit reads from one place.
 *
 * All 4 helpers preserve byte-identical semantics to the closures they
 * replaced. The `isset()` checks on `$input` are intentional: the
 * input_schema fires *before* the permission_callback (verified against
 * WordPress/abilities-api `includes/abilities-api.php` trunk 2026-05-17),
 * but if the schema permits null input (DELETE/GET callers), $input may
 * arrive as null at the permission stage too.
 *
 * @package SignalNoiseTools
 * @since 4.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `manage_options` capability — admin-only operations.
 *
 * Used by: purge-all-caches, clear-template-overrides,
 * list-template-overrides, get-rss-stats, get-deploy-status,
 * list-cron-events, get-cron-history, run-insights-scan,
 * get-insights, unschedule-cron-event, get-audit-log, run-audit-prune.
 *
 * @return bool
 */
function snt_ability_perm_manage_options() {
	return current_user_can( 'manage_options' );
}

/**
 * `edit_others_posts` — the corpus READ tier (v11.21.0).
 *
 * Policy: docs/ops/ability-permission-policy.md. Every ability using this reads
 * post bodies, titles or corpus statistics and writes nothing.
 *
 * WHY NOT `edit_posts`, which the arc's scaffold proposed: these abilities read
 * across publish, future, draft, pending AND private statuses, and `edit_posts`
 * is held by the Author role — who must not read other people's unpublished
 * work. `edit_others_posts` is held by Editor and above and by neither Author
 * nor Contributor, which is precisely the sentence these abilities need: *may
 * read other people's unpublished content*.
 *
 * This is a PUBLIC permission contract. Loosening one is a reviewed decision
 * recorded in the policy document, never a sweep.
 *
 * @return bool
 */
function snt_ability_perm_read_corpus() {
	return current_user_can( 'edit_others_posts' );
}

/**
 * `edit_post` capability on `$input['post_id']`.
 *
 * Used by: regenerate-og-card, ai-generate-meta-description,
 * ai-generate-og-card-title, ai-generate-excerpt, ai-drift-suggest,
 * ai-drift-apply, ai-alt-inline-suggest, ai-link-suggest, ai-link-apply.
 *
 * @param array|null $input
 * @return bool
 */
function snt_ability_perm_edit_post( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	return current_user_can( 'edit_post', $post_id );
}

/**
 * `edit_post` capability on `$input['attachment_id']`.
 *
 * Attachments are posts in WordPress, so `edit_post` is the correct
 * cap for editing alt text / metadata on an attachment.
 *
 * Used by: ai-alt-suggest, ai-alt-apply, ai-orphan-suggest.
 *
 * @param array|null $input
 * @return bool
 */
function snt_ability_perm_edit_attachment( $input ) {
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	return current_user_can( 'edit_post', $attachment_id );
}

/**
 * `delete_post` capability on `$input['attachment_id']`.
 *
 * Used by: ai-orphan-apply (destructive — force-deletes the attachment).
 *
 * @param array|null $input
 * @return bool
 */
function snt_ability_perm_delete_attachment( $input ) {
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	return current_user_can( 'delete_post', $attachment_id );
}
