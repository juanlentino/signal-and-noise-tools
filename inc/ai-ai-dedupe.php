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
 * COEXISTENCE POLICY (deliberate, owner directive 2026-06-23 — "SN should
 * complement the official one"): meta-description + excerpt are DISABLED above
 * because SN's versions do the SAME job on the SAME surface (editor button →
 * the same field), so two would be redundant. But ai/ai's alt-text and
 * suggest-cats/tags are LEFT ENABLED on purpose, because SN's equivalents do a
 * DIFFERENT job on a DIFFERENT surface and COMPLEMENT rather than duplicate:
 *   - SN alt-text (inc/ai-alt-text-suggest.php) is driven by the site-wide
 *     Health "missing_alt" AUDIT — find every image missing alt across the
 *     site, suggest→apply in bulk. ai/ai's is a per-image button in the editor.
 *     One is a11y remediation, the other is inline convenience.
 *   - SN tag-suggest (inc/ai-tag-suggest.php) is constrained to your EXISTING
 *     vocabulary (tag hygiene for untagged Notes; never invents a tag). ai/ai's
 *     suggest-cats/tags is GENERATIVE classification (proposes new terms). One
 *     keeps the taxonomy tight, the other expands it.
 * They live on separate surfaces (Health page / Content→Tags vs the editor), so
 * there is no double-button collision. Do NOT disable either side — that would
 * delete genuine distinct value. If a future ai/ai release adds a site-wide alt
 * audit or an existing-vocab tag mode, revisit.
 *
 * @package SignalNoiseTools
 * @since 4.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wpai_feature_meta-description_enabled', '__return_false' );
add_filter( 'wpai_feature_excerpt-generation_enabled', '__return_false' );
