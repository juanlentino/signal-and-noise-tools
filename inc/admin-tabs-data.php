<?php
/**
 * Signal & Noise — admin tab data (v3.8.0+ IA).
 *
 * The single source of truth for the 6 top-level admin tabs and their
 * sub-tabs / in-page sub-sections. Pure data (no side effects, no output) so
 * registration, routing, rendering, and the legacy-redirect layer all read
 * from one place. Extracted from inc/admin-page.php in v4.5.4.
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
			// Phase 1: landing tab has no sub_tabs; the dispatcher calls this
			// tab-level render directly (inc/admin-render-sections.php).
			'render'   => 'sn_admin_render_dashboard',
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
					'render'       => 'sn_admin_render_identity_and_seo_form',
					'sub_sections' => array(
						'identity'   => array( 'label' => 'Identity' ),
						'social'     => array( 'label' => 'Social' ),
						'open-graph' => array( 'label' => 'Open Graph' ),
						'seo-copy'   => array( 'label' => 'SEO Copy' ),
					),
				),
				// Cloudflare is its own sub-tab — independent form, its own save button via module hook.
				'cloudflare' => array(
					'label'  => 'Cloudflare',
					'render' => 'sn_admin_render_cloudflare_section',
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
				'login'     => array( 'label' => 'Login URL', 'render' => 'sn_admin_render_login_section' ),
				// v3.8.3: audit-log sub-tab. Adding the 2nd sub-tab automatically
				// reveals the sub-tab nav row (sn_admin_render_sub_tabs() hides at count<2).
				'audit-log' => array( 'label' => 'Audit log', 'render' => 'snt_audit_log_render_tab' ),
			),
		),
		array(
			'slug'     => 'sn-automation',
			'tab'      => 'automation',
			'label'    => 'Automation',
			'title'    => 'Signal & Noise — Automation',
			'subtitle' => 'Webhooks and scheduled jobs.',
			'sub_tabs' => array(
				'webhooks'  => array( 'label' => 'Webhooks', 'render' => 'sn_admin_render_webhooks_section' ),
				'cron'      => array( 'label' => 'Cron', 'render' => 'sn_admin_render_cron_section' ),
				'indexnow'  => array( 'label' => 'IndexNow', 'render' => 'sn_admin_render_indexnow_section' ),
			),
		),
		array(
			'slug'     => 'sn-monitoring',
			'tab'      => 'monitoring',
			'label'    => 'Monitoring',
			'title'    => 'Signal & Noise — Monitoring',
			'subtitle' => 'Insights, content health, analytics, RSS subscribers.',
			'sub_tabs' => array(
				'insights'  => array( 'label' => 'Insights', 'render' => 'sn_admin_render_insights_section' ),
				'health'    => array( 'label' => 'Health', 'render' => 'sn_admin_render_health_section' ),
				// v5.4.0: Analytics returns as a SETTINGS-ONLY sub-tab (creds + Test
				// connection + Worker setup). The comprehensive READ-ONLY dashboard
				// now lives under the native WP Dashboard menu (Dashboard → Analytics,
				// inc/analytics-dashboard-page.php). This sub-tab's form posts on the
				// page=sn-theme-options route (sn_admin_render_sub_tabs hardcodes that
				// slug) so sn_handle_admin_post() accepts analytics_save/_test unchanged.
				'analytics' => array( 'label' => 'Analytics', 'render' => 'snt_analytics_render_settings_section' ),
				'rss'       => array( 'label' => 'RSS', 'render' => 'sn_admin_render_rss_section' ),
				// v4.13.0 (Music Identity): Muso.AI + Spotify discography sync —
				// an external-API integration with credentials + sync status.
				// In-page sub-tab only (no new sidebar entry, so the
				// desktop-mode 6-submenu = 6-top-tab invariant is intact).
				'music'     => array( 'label' => 'Music', 'render' => 'sn_admin_render_music_section' ),
			),
		),
		array(
			'slug'     => 'sn-tools',
			'tab'      => 'tools',
			'label'    => 'Tools',
			'title'    => 'Signal & Noise — Tools',
			'subtitle' => 'Utility surfaces and external shortcuts.',
			'sub_tabs' => array(
				'reading-time'     => array( 'label' => 'Reading Time', 'render' => 'sn_admin_render_reading_time_section' ),
				'links'            => array( 'label' => 'Links', 'render' => 'sn_admin_render_links_section' ),
				'block-migrations' => array( 'label' => 'Block Migrations', 'render' => 'sn_admin_render_block_migrations_section' ),
				'performance'      => array( 'label' => 'Performance', 'render' => 'sn_admin_render_performance_section' ),
				// v4.11.0 (T4): AI release-notes drafter. 5th Tools sub-tab -
				// keep in-page tab count == submenu count (desktop-mode
				// horizontal-submenu rule).
				'release-notes'    => array( 'label' => 'Release Notes', 'render' => 'sn_admin_render_release_notes_section' ),
				// v4.12.0: front-end render knobs (theme filter values).
				'front-end'        => array( 'label' => 'Front-End', 'render' => 'sn_admin_render_front_end_form' ),
			),
		),
	);
}
