<?php
/**
 * Signal & Noise Tools — ai/ai duplicate-feature dedupe.
 *
 * The upstream "AI" plugin (folder/file ai/ai, v1.0.x) ships two editor
 * features that DUPLICATE Signal & Noise's own generators:
 *   - meta-description  → duplicates SN's _sn_meta_description field + its
 *                         "Generate with AI" button (and, with no third-party
 *                         SEO plugin active, ai/ai ALSO emits its own
 *                         <meta name="description"> on wp_head pri 1 —
 *                         competing with SN's SEO module: a latent
 *                         double-tag. Disabling the feature stops that emit.)
 *   - excerpt-generation → duplicates SN's post_excerpt "Generate" button.
 *
 * Both are JS editor plugins (registerPlugin), NOT PHP meta boxes — so
 * remove_meta_box does nothing. The clean, server-side off-switch is the
 * per-feature filter ai/ai checks in Abstract_Feature::is_enabled() before
 * Loader::register() runs (stable since ai/ai 0.7.0). Returning false stops
 * the feature registering at all: no enqueue, no bundle, no panel, and the
 * feature's WordPress Ability is not registered either (no orphan).
 *
 * Filters register at plugin-load time (before init), so they are in place
 * before ai/ai's feature loader checks is_enabled(). SN's own generators
 * (assets/ai-meta-description.js, assets/ai-excerpt.js) and ai/ai's four
 * NON-duplicated features (editorial notes, summary, suggest cats/tags,
 * title gen) are deliberately left alone.
 *
 * COUPLING CAVEAT: the feature-id slugs below are verified against ai/ai
 * 1.0.x (audit HEAD c71c9c9, 2026-06-05). ai/ai is explicitly experimental;
 * if a future release renames a feature id, the filter silently no-ops and
 * the duplicate re-surfaces — an accepted, documented fragility. Re-verify
 * on ai/ai updates. tests/ai-ai-dedupe.php asserts our filters are wired.
 *
 * @package SignalNoiseTools
 * @since 4.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wpai_feature_meta-description_enabled', '__return_false' );
add_filter( 'wpai_feature_excerpt-generation_enabled', '__return_false' );
