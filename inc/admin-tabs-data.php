<?php
/**
 * Signal & Noise — admin tab data (v3.8.0+ IA).
 *
 * The single source of truth for the 6 top-level admin tabs and their
 * sub-tabs / in-page sub-sections. Pure data (no side effects, no output) so
 * registration, routing, rendering, and the legacy-redirect layer all read
 * from one place. Extracted from inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The 6 top-level tabs of the SN admin UI (v3.8.0+ IA).
 *
 * Each entry has a `sub_sections` array (may be empty for landing pages
 * with no in-page TOC). Sub-section ordering = display order in the
 * in-page TOC. Slugs are stable URL fragments (`?tab=<top>#sn-sec-<sub>`).
 *
 * @since 3.8.0
 * @return array<int,array<string,mixed>>
 */
function sn_admin_top_tabs() {
	return array(
		array(
			'slug'     => 'sn-theme-options',
			'tab'      => 'dashboard',
			'label'    => 'Dashboard',
			'title'    => 'Signal & Noise — Dashboard',
			'subtitle' => 'Status overview and maintenance actions.',
			'sub_tabs' => array(),  // landing page, no sub-tabs
		),
		array(
			'slug'     => 'sn-site',
			'tab'      => 'site',
			'label'    => 'Site',
			'title'    => 'Signal & Noise — Site',
			'subtitle' => 'Site identity, social profiles, Open Graph, SEO copy, Cloudflare.',
			'sub_tabs' => array(
				// Identity-and-SEO bundles 4 tightly-coupled form sections (one save button
				// saves all 4). Internal TOC navigates between them.
				'identity-and-seo' => array(
					'label'        => 'Identity & SEO',
					'sub_sections' => array(
						'identity'   => array( 'label' => 'Identity' ),
						'social'     => array( 'label' => 'Social' ),
						'open-graph' => array( 'label' => 'Open Graph' ),
						'seo-copy'   => array( 'label' => 'SEO Copy' ),
					),
				),
				// Cloudflare is its own sub-tab — independent form, its own save button via module hook.
				'cloudflare' => array(
					'label' => 'Cloudflare',
				),
			),
		),
		array(
			'slug'     => 'sn-security',
			'tab'      => 'security',
			'label'    => 'Security',
			'title'    => 'Signal & Noise — Security',
			'subtitle' => 'Custom login URL.',
			'sub_tabs' => array(
				'login'     => array( 'label' => 'Login URL' ),
				// v3.8.3: audit-log sub-tab. Adding the 2nd sub-tab automatically
				// reveals the sub-tab nav row (sn_admin_render_sub_tabs() hides at count<2).
				'audit-log' => array( 'label' => 'Audit log' ),
			),
		),
		array(
			'slug'     => 'sn-automation',
			'tab'      => 'automation',
			'label'    => 'Automation',
			'title'    => 'Signal & Noise — Automation',
			'subtitle' => 'Webhooks and scheduled jobs.',
			'sub_tabs' => array(
				'webhooks' => array( 'label' => 'Webhooks' ),
				'cron'     => array( 'label' => 'Cron' ),
			),
		),
		array(
			'slug'     => 'sn-monitoring',
			'tab'      => 'monitoring',
			'label'    => 'Monitoring',
			'title'    => 'Signal & Noise — Monitoring',
			'subtitle' => 'Insights, content health, analytics, RSS subscribers.',
			'sub_tabs' => array(
				'insights'  => array( 'label' => 'Insights' ),
				'health'    => array( 'label' => 'Health' ),
				'plausible' => array( 'label' => 'Plausible' ),
				'rss'       => array( 'label' => 'RSS' ),
			),
		),
		array(
			'slug'     => 'sn-tools',
			'tab'      => 'tools',
			'label'    => 'Tools',
			'title'    => 'Signal & Noise — Tools',
			'subtitle' => 'Utility surfaces and external shortcuts.',
			'sub_tabs' => array(
				'reading-time'     => array( 'label' => 'Reading Time' ),
				'links'            => array( 'label' => 'Links' ),
				'block-migrations' => array( 'label' => 'Block Migrations' ),
			),
		),
	);
}
