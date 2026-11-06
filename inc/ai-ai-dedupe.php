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
 * (assets/ai-meta-description.js, assets/ai-excerpt.js) and ai/ai's other
 * NON-duplicated features (summary, suggest cats/tags, title gen) are
 * deliberately left alone.
 *
 * LOCKED OFF (owner, 2026-11-06), a different reason from the duplicates above:
 * these can change a signed note outside sn-apply, so its gates and change_class
 * never see the edit and provenance mints an unlabeled version.
 *   - editorial-updates rewrites content from pending Notes, and saves.
 *   - editorial-notes feeds it.
 *   - webmcp lets an agent browser edit and publish in the block editor.
 *   - text-to-speech adds its player on the_content, the filter that put
 *     MailPoet's slide-in inside the signed region of all 50 notes.
 * A Settings checkbox was the only thing keeping them off. Ids verified at
 * ai/ai 1.5.0; remove a line to allow one.
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
 *   - SN tag fit (inc/jev-tags.php, 16.9.0) reads existing tags only: Jev judges,
 *     never invents a term.
 * They live on separate surfaces (Health page / Content→Tags vs the editor), so
 * there is no double-button collision. Do NOT disable either side — that would
 * delete genuine distinct value. An existing-vocab tag mode is not future:
 * ai/ai's Content Classification has offered "Only suggest existing terms"
 * since it landed in 0.7.0 (read at the 0.7.0 and 1.0.2 tags). It suggests
 * terms for one post in the editor; SN tag fit judges the tags a note
 * already carries, so the surfaces still differ. If a future ai/ai release
 * adds a site-wide alt audit or a site-wide review of attached tags, revisit.
 *
 * @package SignalNoiseTools
 * @since 4.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wpai_feature_meta-description_enabled', '__return_false' );
add_filter( 'wpai_feature_excerpt-generation_enabled', '__return_false' );
add_filter( 'wpai_feature_editorial-updates_enabled', '__return_false' );
add_filter( 'wpai_feature_editorial-notes_enabled', '__return_false' );
add_filter( 'wpai_feature_webmcp_enabled', '__return_false' );
add_filter( 'wpai_feature_text-to-speech_enabled', '__return_false' );
