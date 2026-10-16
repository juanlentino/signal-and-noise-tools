<?php
/**
 * Signal & Noise Tools — per-post OG/Twitter card generator.
 *
 * Renders a 1200×630 brutalist text card per post/page using PHP GD.
 * The active theme owns the typography (Bebas Neue Regular for the
 * title, DM Mono Light for the eyebrow/dek/footer) and registers
 * font paths via the `sn_og_font_paths` filter. Cards cache as PNG
 * files in `wp-content/uploads/sn-og/` and rebuild on every save
 * via `wp_after_insert_post`.
 *
 * Resolution order for "what image should Yoast/the theme emit":
 *
 *   1. Featured image, if the post has one — never overridden.
 *   2. Generated card (this module) — if the cache exists, or can be
 *      generated on demand.
 *   3. Fallback to whatever Yoast would have chosen (typically the
 *      site logo, or nothing).
 *
 * Moved from theme inc/og-image.php in Phase 3 (theme v8.4.0 /
 * plugin v1.3.0, 2026-05-16). The only structural change vs the
 * theme version: get_theme_file_path() font lookups replaced with
 * apply_filters('sn_og_font_paths', []) so the rendering pipeline
 * is theme-independent.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_OG_DIRNAME = 'sn-og';
const SN_OG_WIDTH   = 1200;
const SN_OG_HEIGHT  = 630;

// The rest of the module lives in three parts files (house line cap, 2026-10-15),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/og-card-generator-resolve-parts.php';
require_once __DIR__ . '/og-card-generator-render-parts.php';
require_once __DIR__ . '/og-card-generator-hooks-parts.php';
