<?php
/**
 * Signal & Noise Tools — JSON-LD structured data (schema.org).
 *
 * Emits a single @graph JSON-LD script in <head> with three schemas:
 *
 *   - WebSite      — every page; publisher references the Person
 *   - Person       — every page; name + url + sameAs (social profiles)
 *   - Article      — singular posts; headline, dates, author, image,
 *                    mainEntityOfPage. (BlogPosting would also be fine
 *                    but Article is the broader supertype.)
 *
 * SearchAction: WebSite emits a SearchAction targeting the on-site search at
 * `/notes/?s=` (theme v9.8.0 Notes-scoped search) so Google can surface a
 * sitelinks search box. (Pre-v4.8.1 this was skipped — there was no search route.)
 *
 * Skipped vs The SEO Framework's emission:
 *   - WebPage on non-post singulars — marginal value, omit.
 *
 * NOTE: BreadcrumbList JSON-LD IS emitted from here (sn_schema_breadcrumb_list
 * below). The earlier v2.0.0 docblock claimed WP 7.0's native Breadcrumbs
 * block would emit its own structured data so we could drop this — that
 * claim was false. Verified 2026-05-20 against Gutenberg trunk: the native
 * core/breadcrumbs block emits visual <nav><ol> HTML only, no JSON-LD. So
 * this function remains load-bearing for SERP breadcrumb rich results.
 *
 * Social profile URLs (sameAs) are filterable via `sn_schema_same_as`
 * for future configurability.
 *
 * Added in v1.7.0 (Phase 11 absorption, 2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/seo-schema-identity-parts.php';
require_once __DIR__ . '/seo-schema-pages-parts.php';
require_once __DIR__ . '/seo-schema-archive-parts.php';
