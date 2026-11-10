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
 * Resolution order for the og:image the plugin emits
 * (sn_resolve_og_image_url(); no other SEO plugin is involved):
 *
 *   1. The per-post _sn_og_image_url override.
 *   2. Featured image, if the post has one.
 *   3. Generated card (this module), if the cached file exists. It is
 *      never generated inside the request.
 *   4. The site default.
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
