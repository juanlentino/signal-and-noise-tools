<?php
/**
 * Signal & Noise — admin POST handlers: theme settings and AI provider configuration.
 *
 * Split out of inc/admin-post-actions.php in v12.22.0, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: save_theme, ml_embed_compare, ai_settings_save
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v4.12.0: the AI text-generation model allowlist (single source for the
 * Front-End form's <select> AND the save handler's validation). Keys are the
 * model ids passed to the snt_ai_model_preference filter; values are UI labels.
 *
 * Ids are the alias form (no date suffix), verified Active against the
 * claude-api model catalog: Sonnet 5 (default), Opus 4.8 (most capable),
 * Haiku 4.5 (fastest/cheapest). v6.52.0: this stays a small
 * hand-maintained list rather than a live enumeration. The WP AI Client exposes
 * no public model-list helper (only an SDK-internal registry path that hits the
 * network on admin render and is untestable in CI), so a curated allowlist keeps
 * the picker priced, predictable, and testable. Loaded unconditionally at
 * bootstrap, so it is available on the front end too (sn_tf_ai_model() calls it
 * during AI requests).
 *
 * @return array<string,string>
 */
function sn_theme_ai_models() {
	return array(
		'claude-sonnet-5'   => 'Claude Sonnet 5 (balanced, default)',
		'claude-opus-4-8'   => 'Claude Opus 4.8 (most capable)',
		'claude-haiku-4-5'  => 'Claude Haiku 4.5 (fastest, cheapest)',
	);
}

/**
 * Curated vision-capable model allowlist for the alt-text route (v7.3.0).
 * Same contract as sn_theme_ai_models(): keys are wp-ai-client model ids
 * (Gemini ids resolve live from the provider), values are UI labels. The
 * default pin matches the ai-bootstrap alt-text route.
 *
 * @return array<string,string>
 */
function sn_theme_ai_vision_models() {
	return array(
		'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite (default: fast, cheap vision)',
		'gemini-2.5-flash'      => 'Gemini 2.5 Flash (stronger vision)',
		'gemini-2.5-pro'        => 'Gemini 2.5 Pro (strongest: slower, pricier)',
	);
}

/**
 * v4.12.0: persist the Front-End settings form (Site → Front-End sub-tab).
 *
 * Sparse writes via sn_setting_update() so the sibling sn_settings subtrees are
 * never clobbered (same whole-option-replace hazard the audit/monitoring/perf
 * handlers avoid). Ints are clamped to the same bounds the theme-filter
 * callbacks enforce; the model select is VALIDATED against the allowlist
 * (validation > sanitization) and falls back to the current value (then the
 * first allowlisted id) when an off-list id is posted.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_save_theme( $post ) {
	$ok  = sn_setting_update( 'theme.related_count', max( 1, min( 12, (int) ( $post['theme_related_count'] ?? 3 ) ) ) );
	$ok &= sn_setting_update( 'theme.palette_recent_count', max( 0, min( 20, (int) ( $post['theme_palette_recent_count'] ?? 8 ) ) ) );
	$ok &= sn_setting_update( 'theme.palette_enabled', ! empty( $post['theme_palette_enabled'] ) );
	$ok &= sn_setting_update( 'theme.json_feed_items', max( 1, min( 50, (int) ( $post['theme_json_feed_items'] ?? 20 ) ) ) );
	$ok &= sn_setting_update( 'theme.updated_threshold_days', max( 1, min( 90, (int) ( $post['theme_updated_threshold_days'] ?? 14 ) ) ) );
	$ok &= sn_setting_update( 'theme.reading_wpm', max( 100, min( 400, (int) ( $post['theme_reading_wpm'] ?? 225 ) ) ) );
	$ok &= sn_setting_update( 'theme.notes_per_page', max( 1, min( 100, (int) ( $post['theme_notes_per_page'] ?? 20 ) ) ) );

	// v10.46.0: theme.ai_model / theme.ai_alt_model / theme.ai_monthly_budget
	// moved to sn_handle_ai_settings_save() below. They MUST NOT be read here any
	// more — this handler now runs against a form that no longer posts them, so a
	// leftover read would resolve to `?? 0` / '' on every front-end save and
	// silently reset the budget to zero on each one.
	return $ok ? 'theme_saved' : 'theme_unchanged';
}

/**
 * item 8: run the TF-IDF vs embeddings comparison across the corpus.
 *
 * The result is stashed in a transient because a flash code cannot carry a
 * table, and because re-running costs real API calls — the readout should
 * survive a page refresh rather than silently re-spending.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_ml_embed_compare( $post ) {
	unset( $post );
	$res = snt_ml_embedding_compare_corpus( 5 );
	if ( is_wp_error( $res ) ) {
		set_transient( 'snt_ml_embed_compare', array( 'ok' => false, 'error' => $res->get_error_message(), 'when' => time() ), HOUR_IN_SECONDS );
		return 'ml_embed_compare_failed';
	}
	set_transient( 'snt_ml_embed_compare', array( 'ok' => true, 'result' => $res, 'when' => time() ), DAY_IN_SECONDS );
	return 'ml_embed_compare_ok';
}

function sn_handle_ai_settings_save( $post ) {
	$allowed = array_keys( sn_theme_ai_models() );
	$model   = isset( $post['theme_ai_model'] ) ? sanitize_text_field( wp_unslash( $post['theme_ai_model'] ) ) : '';
	$ok      = sn_setting_update( 'theme.ai_model', in_array( $model, $allowed, true ) ? $model : (string) sn_setting( 'theme.ai_model', $allowed[0] ) );

	// v7.3.0: vision (alt-text) model — same validate-against-allowlist pattern;
	// an off-list id keeps the current value (then the pinned default).
	$vision_allowed = array_keys( sn_theme_ai_vision_models() );
	$vision         = isset( $post['theme_ai_alt_model'] ) ? sanitize_text_field( wp_unslash( $post['theme_ai_alt_model'] ) ) : '';
	$ok            &= sn_setting_update( 'theme.ai_alt_model', in_array( $vision, $vision_allowed, true ) ? $vision : (string) sn_setting( 'theme.ai_alt_model', $vision_allowed[0] ) );

	// v9.26.0: monthly AI budget in USD. Clamp to >= 0 at cents precision; 0 = off.
	$ok &= sn_setting_update( 'theme.ai_monthly_budget', round( max( 0, (float) ( $post['theme_ai_monthly_budget'] ?? 0 ) ), 2 ) );

	// item 8: the Workers AI token. Masked field, so an un-edited '••••…'
	// placeholder must never be written back over the real value — the same
	// round-trip the analytics token already uses. 'clear' removes it.
	if ( isset( $post['sn_ml_embeddings_token'] ) ) {
		$embed = sanitize_text_field( wp_unslash( $post['sn_ml_embeddings_token'] ) );
		if ( 'clear' === strtolower( $embed ) ) {
			$ok &= sn_setting_update( 'ml.embeddings_token', '' );
		} elseif ( '' !== $embed && 0 !== strpos( $embed, '••••' ) ) {
			$ok &= sn_setting_update( 'ml.embeddings_token', $embed );
		}
	}

	return $ok ? 'ai_settings_saved' : 'ai_settings_unchanged';
}
