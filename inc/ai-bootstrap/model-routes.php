<?php
/**
 * Signal & Noise — AI bootstrap: per-feature model routing (alt-text, economy).
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
 * Provides: snt_ai_register_alt_text_model_route(),
 * snt_ai_economy_features(), snt_ai_register_economy_model_route()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v6.48.0: route the 'alt-text' feature to a vision-capable Gemini Flash model.
 *
 * Registered as a default `snt_ai_model_preference` filter so the routing lives
 * in the repo, not in a deployment-time filter. Alt text is fundamentally about
 * describing what is IN the image, so it goes to a multimodal model (which also
 * receives the attached image via ->with_file() in the seam above); every other
 * feature stays on the pinned Claude Sonnet text model. The Gemini id is itself
 * filterable via `snt_ai_alt_text_model` so the owner can re-pin (e.g. to
 * gemini-2.5-flash) with NO release — the WP AI Client resolves Gemini ids LIVE
 * from Google's API, so the exact resolvable id depends on the configured provider
 * + key/region. Default = gemini-2.5-flash-lite (Google's cheapest multimodal
 * Flash, ideal for bulk alt-text). Registered with accepted_args=4 so the callback
 * receives $feature.
 *
 * Registered via a named function (not a bare add_filter at file scope) so the
 * test harness can re-register it after it clears its filter registry between
 * blocks.
 *
 * @since 6.48.0
 */
function snt_ai_register_alt_text_model_route() {
	add_filter(
		'snt_ai_model_preference',
		function ( $model, $prompt, $system_instruction, $feature = 'generic' ) {
			if ( 'alt-text' === $feature ) {
				// v7.3.0: the settings dropdown (theme.ai_alt_model) feeds the
				// DEFAULT; the snt_ai_alt_text_model filter still wins for
				// code-level pins. Absent setting = the original pin.
				$alt_default = function_exists( 'sn_setting' )
					? (string) sn_setting( 'theme.ai_alt_model', 'gemini-2.5-flash-lite' )
					: 'gemini-2.5-flash-lite';
				return (string) apply_filters( 'snt_ai_alt_text_model', $alt_default );
			}
			return $model;
		},
		10,
		4
	);
}

/**
 * v9.26.0: the feature labels routed to the economy text model.
 *
 * Short, mechanical prose one-liners a human judges at a glance — NOT the
 * structured-JSON suggesters (link/orphan/pair, whose parse-robustness we care
 * about) or the reasoning calls (insights, insights_narration, drift_detect,
 * release_notes), which stay on the default model. Filterable so a deployment
 * can add or drop economy features with no release.
 *
 * @since 9.26.0
 * @return string[] Economy feature labels (see snt_ai_generate_with_constraints $feature).
 */
function snt_ai_economy_features() {
	return (array) apply_filters(
		'snt_ai_economy_features',
		array( 'meta_desc', 'excerpt', 'og_title', 'drift_phrase', 'tag_suggest' )
	);
}

/**
 * v9.26.0: economy-tier model routing for the short one-liner features.
 *
 * The default text model (Sonnet 5) is right for reasoning-heavy calls, but the
 * HIGHEST-FREQUENCY calls are tiny prose one-liners that fire on every post
 * save (a 150-char meta description, a 60-token OG title, a tag list). Those
 * don't need a premium model: Haiku 4.5 is ~3x cheaper ($1/$5 vs $3/$15 per
 * MTok) at effectively equal quality on a glance-judged task. That is a
 * decision, not a preference, so it ships as a default FLOOR rather than a
 * settings toggle.
 *
 * Priority 20 — AFTER the owner's model dropdown (sn_tf_ai_model, priority 10)
 * — makes it a hard floor: economy features run on Haiku even if the owner
 * picks Opus for everything else. Every non-economy feature still follows the
 * dropdown. The alt-text route (priority 10) is disjoint ('alt-text' is not an
 * economy feature) and unaffected.
 *
 * Escape hatch: `snt_ai_economy_model` receives (model, feature,
 * inherited_model) — return the inherited model to opt a feature back onto the
 * owner's choice, or a different id to re-pin. Named function (not a bare
 * add_filter) so the test harness can re-register after clearing filters,
 * mirroring snt_ai_register_alt_text_model_route().
 *
 * @since 9.26.0
 * @return void
 */
function snt_ai_register_economy_model_route() {
	add_filter(
		'snt_ai_model_preference',
		function ( $model, $prompt, $system_instruction, $feature = 'generic' ) {
			if ( in_array( $feature, snt_ai_economy_features(), true ) ) {
				return (string) apply_filters( 'snt_ai_economy_model', 'claude-haiku-4-5', $feature, $model );
			}
			return $model;
		},
		20,
		4
	);
}
