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

// The SEO Framework has been inactive since the v2.0.0 (Phase 13) cutover;
// its OG pool filter was removed 2026-11-10. Core's wp_robots() and
// rel_canonical() print robots and the singular canonical, fed by the
// wp_robots and get_canonical_url filters in inc/seo-head-parts.php.

// The rest of the module lives in four parts files (house line cap, 2026-10-14),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/seo-meta-parts.php';
require_once __DIR__ . '/seo-head-parts.php';
require_once __DIR__ . '/seo-og-parts.php';
require_once __DIR__ . '/seo-delivery-parts.php';
require_once __DIR__ . '/seo-noindex-parts.php'; // 2026-10: feeds and MailPoet's page stay out of the index.
