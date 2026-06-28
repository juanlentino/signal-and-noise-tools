<?php
/**
 * Signal & Noise — admin tab data (v6.18.0 IA).
 *
 * The single source of truth for the 7 top-level admin tabs and their
 * sub-tabs / in-page sub-sections. Pure data (no side effects, no output) so
 * registration, routing, rendering, and the legacy-redirect layer all read
 * from one place. Extracted from inc/admin-page.php in v4.5.4; restructured
 * to 7 intent-coherent tabs in v6.18.0 (admin refactor Phase 2).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The 7 top-level tabs of the SN admin UI (v6.18.0 IA).
 *
 * Each entry has a `sub_tabs` array (empty for the Dashboard landing page).
 * Sub-tab ordering = display order in both the in-page sub-tab nav and the
 * WP sidebar submenu. Slugs are stable URL fragments; relocated/renamed
 * surfaces keep working through inc/admin-legacy-redirect.php.
 *
 * v6.18.0 regroup: Cloudflare → Connections (with Webhooks, IndexNow, Cron);
 * Music + RSS → Content (with Front-End, Reading Time, Performance);
 * Monitoring is observability-only; Tools is trimmed to Block Migrations,
 * Release Notes, Links. Slugs sn-content / sn-connections are new;
 * the retired sn-automation slug 301s to Connections.
 *
 * @since 3.8.0  (restructured 6.18.0)
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
			'label'    => 'Identity & SEO',
			'title'    => 'Signal & Noise — Identity & SEO',
			'subtitle' => 'Site identity, social profiles, Open Graph cards, and per-route SEO copy.',
			'sub_tabs' => array(
				// One composite leaf: 4 tightly-coupled form sections under one save
				// button, navigated by the internal TOC. Cloudflare moved to Connections
				// in v6.18.0, leaving this the sole leaf (the sub-tab nav auto-hides at
				// count < 2).
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
			),
		),
		array(
			'slug'     => 'sn-content',
			'tab'      => 'content',
			'label'    => 'Content',
			'title'    => 'Signal & Noise — Content',
			'subtitle' => 'Front-end rendering, reading time, performance, music discography, and RSS subscribers.',
			'sub_tabs' => array(
				'front-end'    => array( 'label' => 'Front-End', 'render' => 'sn_admin_render_front_end_form' ),
				'reading-time' => array( 'label' => 'Reading Time', 'render' => 'sn_admin_render_reading_time_section' ),
				'performance'  => array( 'label' => 'Performance', 'render' => 'sn_admin_render_performance_section' ),
				// Music + RSS moved here from Monitoring in v6.18.0 (content surfaces,
				// not observability). Music stays an in-page sub-tab (no new sidebar
				// entry) so the desktop-mode submenu-count == top-tab-count invariant
				// holds (7 == 7).
				// 'wide': music uses the full-width two-column sn_admin_shell, so it
				// opts out of the wrapper's default capped .sn-fieldset card.
				'music'        => array( 'label' => 'Music', 'render' => 'sn_admin_render_music_section', 'wide' => true ),
				'rss'          => array( 'label' => 'RSS', 'render' => 'sn_admin_render_rss_section' ),
				'tags'         => array( 'label' => 'Tags', 'render' => 'sn_admin_render_tag_cleanup_section' ),
			),
		),
		array(
			'slug'     => 'sn-connections',
			'tab'      => 'connections',
			'label'    => 'Connections',
			'title'    => 'Signal & Noise — Connections',
			'subtitle' => 'Cloudflare edge cache, outbound webhooks, IndexNow, scheduled jobs, and scheduled content.',
			'sub_tabs' => array(
				// Cloudflare moved from Site; webhooks/indexnow/cron from the retired
				// Automation tab (v6.18.0). 'wide' (Phase 3, v6.45.0): all five
				// Connections leaves use the full-width layout — Cloudflare/Webhooks lay
				// out work + status/reference in the two-column sn_admin_shell; Cron and
				// Scheduled lead with a glance hero over a full-width data table.
				'cloudflare'        => array( 'label' => 'Cloudflare', 'render' => 'sn_admin_render_cloudflare_section', 'wide' => true ),
				'webhooks'          => array( 'label' => 'Webhooks', 'render' => 'sn_admin_render_webhooks_section', 'wide' => true ),
				'indexnow'          => array( 'label' => 'IndexNow', 'render' => 'sn_admin_render_indexnow_section', 'wide' => true ),
				'cron'              => array( 'label' => 'Cron', 'render' => 'sn_admin_render_cron_section', 'wide' => true ),
				// Scheduled-content status list (Task 8): the read-mostly union of
				// signal-noise/scheduled fragment rows + native future posts. A sub-tab
				// only (no sidebar submenu), so the desktop-mode 7-top-tab invariant
				// holds. Render fn is defined in inc/schedule-admin.php.
				'scheduled-content' => array( 'label' => 'Scheduled', 'render' => 'sn_admin_render_scheduled_content_section', 'wide' => true ),
			),
		),
		array(
			'slug'     => 'sn-monitoring',
			'tab'      => 'monitoring',
			'label'    => 'Monitoring',
			'title'    => 'Signal & Noise — Monitoring',
			'subtitle' => 'Analytics settings, AI insights, and content-health scans.',
			'sub_tabs' => array(
				// Analytics SETTINGS-ONLY sub-tab (creds + Test connection + Worker
				// setup); the read-only dashboard lives under the native WP Dashboard
				// menu. Its forms post on the page=sn-theme-options route
				// (sn_admin_render_sub_tabs hardcodes that slug) so sn_handle_admin_post()
				// accepts analytics_save/_test unchanged. Now first leaf (v6.18.0).
				// 'wide' (v6.44.0): lays out as an open-and-wide .sn-2up two-column grid
				// (active settings | edge-worker reference); each column owns its own
				// .sn-fieldset, so it opts out of the wrapper's default capped card.
				'analytics' => array( 'label' => 'Analytics', 'render' => 'snt_analytics_render_settings_section', 'wide' => true ),
				// 'wide': both Insights and Health lay out with the full-width
				// two-column sn_admin_shell, so they opt out of the wrapper's
				// default capped .sn-fieldset card (which was squeezing the shell).
				'insights'  => array( 'label' => 'Insights', 'render' => 'sn_admin_render_insights_section', 'wide' => true ),
				'health'    => array( 'label' => 'Health', 'render' => 'sn_admin_render_health_section', 'wide' => true ),
			),
		),
		array(
			'slug'     => 'sn-security',
			'tab'      => 'security',
			'label'    => 'Security',
			'title'    => 'Signal & Noise — Security',
			'subtitle' => 'Custom login URL and the admin audit log.',
			'sub_tabs' => array(
				'login'         => array( 'label' => 'Login URL', 'render' => 'sn_admin_render_login_section' ),
				'login-defense' => array( 'label' => 'Login defense', 'render' => 'sn_login_defense_render' ),
				// v3.8.3: audit-log sub-tab. Adding the 2nd sub-tab automatically
				// reveals the sub-tab nav row (sn_admin_render_sub_tabs() hides at count<2).
				'audit-log' => array( 'label' => 'Audit log', 'render' => 'snt_audit_log_render_tab' ),
			),
		),
		array(
			'slug'     => 'sn-tools',
			'tab'      => 'tools',
			'label'    => 'Tools',
			'title'    => 'Signal & Noise — Tools',
			'subtitle' => 'Block migrations, AI release notes, and external shortcuts.',
			'sub_tabs' => array(
				'block-migrations' => array( 'label' => 'Block Migrations', 'render' => 'sn_admin_render_block_migrations_section' ),
				// v4.11.0 (T4): AI release-notes drafter.
				'release-notes'    => array( 'label' => 'Release Notes', 'render' => 'sn_admin_render_release_notes_section' ),
				// Links last — reference shortcuts (GitHub, release pages, Cloudflare, Cloudways).
				'links'            => array( 'label' => 'Links', 'render' => 'sn_admin_render_links_section' ),
			),
		),
	);
}
