<?php
/**
 * Signal & Noise — SEO + analytics delivery.
 *
 * - Meta description on front page and singular posts
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/seo-validators.php'; // 2026.10 live audit: the dates the page and feed validators send.

/**
 * SEO: Suppress The SEO Framework's Open Graph / Facebook / Twitter emission.
 *
 * Why: our own wp_head OG emission (below) and TSF's both emit `og:image`
 * and `twitter:image` meta tags. The result is duplicate conflicting tags
 * in <head> — TSF emits the site icon as fallback; we emit the generated
 * /sn-og/post-*.png card. Crawler parsing of duplicates is undefined.
 *
 * This filter removes TSF from those generator pools, leaving our wp_head
 * emission as the single source of truth for OG/Twitter meta.
 *
 * TSF owns nothing here now: it has been inactive on the live site since
 * the v2.0.0 (Phase 13) cutover. Since 2026-10-24 core prints the robots
 * meta (wp_robots(), on wp_head) and the singular canonical
 * (rel_canonical(), on wp_head), and inc/seo-head-parts.php feeds them
 * through the wp_robots and get_canonical_url filters. The JSON-LD is ours
 * (inc/seo-schema.php). This filter only does anything if TSF is activated
 * again.
 *
 * Added in plugin v1.4.1 (2026-05-16, Phase 6 diagnostic outcome).
 */
add_filter( 'the_seo_framework_meta_generator_pools', function( $pools ) {
	return array_diff( (array) $pools, array( 'Open_Graph', 'Facebook', 'Twitter' ) );
} );

// The rest of the module lives in four parts files (house line cap, 2026-10-14),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/seo-meta-parts.php';
require_once __DIR__ . '/seo-head-parts.php';
require_once __DIR__ . '/seo-og-parts.php';
require_once __DIR__ . '/seo-delivery-parts.php';
require_once __DIR__ . '/seo-noindex-parts.php'; // 2026-10: feeds and MailPoet's page stay out of the index.
