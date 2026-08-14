<?php
/**
 * Signal & Noise — admin tab data (v10.46.0 Phase-3 IA).
 *
 * The single source of truth for the 8 top-level admin tabs and their
 * sub-tabs / in-page sub-sections. Pure data (no side effects, no output) so
 * registration, routing, rendering, and the legacy-redirect layer all read
 * from one place. Extracted from inc/admin-page.php in v4.5.4; restructured
 * to 7 intent-coherent tabs in v6.18.0 (admin refactor Phase 2); regrouped to
 * 8 in v10.46.0 after eleven leaves had accumulated wherever there was room.
 *
 * Also holds sn_admin_suggest_js_leaves() at the foot of the file — a second
 * registry-adjacent list that must agree with this one, kept here so the two
 * cannot drift apart unnoticed.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The 8 top-level tabs of the SN admin UI (v10.46.0 Phase-3 IA).
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
 * v10.46.0 regroup (Phase 3): eleven leaves landed after the v6.18.0 IA was
 * designed, and they landed wherever there was room. This pass re-sorts by
 * intent rather than by history:
 *   - AI becomes a tab. Its budget cap was field 10 of a form whose own intro
 *     called itself "render knobs"; the rest of the AI surface was scattered
 *     across four more tabs.
 *   - Site absorbs Front-End / Performance / Redirects — how the site itself
 *     behaves, as opposed to what is published on it.
 *   - Content keeps the page editors and reunites the three sibling content
 *     scanners (Tags, Pattern Adoption, Block Migrations) that had been sitting
 *     in three different tabs.
 *   - Monitoring becomes Measurement and takes RSS (feed-request analytics).
 *     The v6.18.0 "observability-only" rule was never true — all four of its
 *     leaves carry writes — so the tab is now honestly named for what it holds.
 *   - Music joins Connections: it is an external-API credential form.
 *   - Tools stops being a junk drawer.
 *
 * LABELS CHANGED, KEYS DID NOT. `tab=monitoring&sub=health` is hardcoded in
 * five call sites (inc/admin-tab-dashboard.php, inc/site-health-widget.php,
 * inc/analytics-recommendations.php); keeping the key keeps all five valid.
 * Every leaf that changed parent tab has a line in sn_admin_subtab_moves()
 * (inc/admin-legacy-redirect.php), which feeds BOTH the GET 301 and the POST
 * PRG — so bookmarks and in-flight saves land identically.
 *
 * @since 3.8.0  (restructured 6.18.0, regrouped 10.46.0)
 * @return array<int,array<string,mixed>>
 */
function sn_admin_top_tabs() {
	// Measurement sub-tabs, built first so the Machine Readers preview leaf
	// (v9.85.0, Session 3) can splice in through the pure registry callback in
	// inc/machine-readers-admin.php. Behind the sn_machine_readers_preview flag
	// the callback returns this map byte-identical, so the tab exists nowhere
	// until the flag (or the v10.0.0 GA flip) turns it on. function_exists-
	// guarded for the isolated harnesses that load this data file alone.
	// snt_mr_admin_register() APPENDS, so Machine Readers lands after RSS.
	$monitoring_sub_tabs = array(
		// Analytics SETTINGS-ONLY sub-tab (creds + Test connection + Worker
		// setup); the read-only dashboard lives under the native WP Dashboard
		// menu. Its forms post on the page=sn-theme-options route
		// (sn_admin_render_sub_tabs hardcodes that slug) so sn_handle_admin_post()
		// accepts analytics_save/_test unchanged. Now first leaf (v6.18.0).
		// 'wide' (v6.44.0): lays out as an open-and-wide .sn-2up two-column grid
		// (active settings | edge-worker reference); each column owns its own
		// .sn-fieldset, so it opts out of the wrapper's default capped card.
		'analytics' => array( 'label' => 'Analytics', 'render' => 'snt_analytics_render_settings_section', 'wide' => true ),
		// 'wide': Insights uses the full-width two-column sn_admin_shell;
		// Health uses a full-width glance hero + findings cards (it dropped the
		// shell in v6.44.0). Both opt out of the wrapper's default capped card.
		// v10.47.0 order: the RECORDING surfaces first (Analytics, RSS, and the
		// spliced Machine Readers), then the two that INTERPRET them.
		'rss'       => array( 'label' => 'RSS', 'render' => 'sn_admin_render_rss_section', 'wide' => true ),
		'insights'  => array( 'label' => 'Insights', 'render' => 'sn_admin_render_insights_section', 'wide' => true ),
		'health'    => array( 'label' => 'Health', 'render' => 'sn_admin_render_health_section', 'wide' => true ),
	);
	if ( function_exists( 'snt_mr_admin_register' ) ) {
		$monitoring_sub_tabs = snt_mr_admin_register( $monitoring_sub_tabs );
	}

	return array(
		array(
			'slug'     => 'sn-theme-options',
			'tab'      => 'dashboard',
			'label'    => 'Dashboard',
			'title'    => 'Signal & Noise. Dashboard',
			'subtitle' => 'Status overview and maintenance actions.',
			// Phase 1: landing tab has no sub_tabs; the dispatcher calls this
			// tab-level render directly (inc/admin-render-sections.php).
			'render'   => 'sn_admin_render_dashboard',
			'sub_tabs' => array(),  // landing page, no sub-tabs
		),
		array(
			'slug'     => 'sn-site',
			'tab'      => 'site',
			// v10.46.0: relabelled 'Site'. The tab held exactly one leaf from
			// v6.18.0 until now, so it wore that leaf's name; with Front-End,
			// Performance and Redirects joining, the leaf name no longer describes
			// the tab. KEY unchanged — only the label moved.
			'label'    => 'Site',
			'title'    => 'Signal & Noise — Site',
			'subtitle' => 'How the site itself behaves: identity and SEO, front-end render knobs, performance, and redirects.',
			'sub_tabs' => array(
				// One composite leaf: 4 tightly-coupled form sections under one save
				// button, navigated by the internal TOC. Cloudflare moved to Connections
				// in v6.18.0; from v10.46.0 this is no longer the sole leaf, so the
				// sub-tab nav (auto-hidden at count < 2) now renders.
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
				// v10.46.0: Front-End + Performance move here from Content. Both
				// configure how the site RENDERS, not what is published on it —
				// Content had become "everything with words in it". 'wide' flags
				// carried across verbatim (a dropped flag silently re-caps the
				// field grid / two-column shell at 820px).
				'front-end'        => array( 'label' => 'Front-End', 'render' => 'sn_admin_render_front_end_form', 'wide' => true ),
				'performance'      => array( 'label' => 'Performance', 'render' => 'sn_admin_render_performance_section', 'wide' => true ),
				// v10.46.0: Redirects moves here from Connections. A redirect map +
				// 404 log is site routing, not an outbound integration.
				'redirects'        => array( 'label' => 'Redirects', 'render' => 'sn_admin_render_redirects_section', 'wide' => true ),
			),
		),
		array(
			'slug'     => 'sn-content',
			'tab'      => 'content',
			'label'    => 'Content',
			'title'    => 'Signal & Noise — Content',
			'subtitle' => 'The page editors, and the three scanners that read what is already published.',
			// v10.46.0 ordering: WRITE surfaces first (the three page editors), then
			// READ surfaces (the three scanners). Front-End / Performance left for
			// Site, Music for Connections, RSS for Measurement.
			//
			// 'wide' (Phase 4b, v6.46.0 — rule still governs): a leaf earns the full
			// content width only with real two-column or wide-table content, never by
			// bare-stretching a lone form. Here:
			//   resume            → sectioned form whose small-row lists become a
			//                       responsive two-column grid at full width;
			//   tags              → glance hero over full-width postbox cards/tables;
			//   pattern-adoption  → a 3-column candidate review table (v10.46.0);
			//   block-migrations  → the same 3-column review table.
			// now / uses stay capped textarea cards — a lone textarea earns nothing.
			//
			// block-migrations gained 'wide' in v10.46.0. Its render fn has always
			// emitted its OWN .sn-fieldset (like its two sibling scanners), so while
			// the leaf was capped the wrapper drew a second card around the first —
			// a card inside a card, the exact defect the 'wide' flag exists to avoid
			// (see the copilot-usage note in the AI tab below). Now that all three
			// scanners sit side by side the mismatch would be visible at a glance,
			// so they are consistent: each owns its own card, each gets the width
			// its review table needs.
			'sub_tabs' => array(
				// v7.5.0/v7.6.0: the theme's /now + /uses page content editors
				// (owner: content lives in the plugin, not hardcoded theme files).
				'now'              => array( 'label' => 'Now Page', 'render' => 'sn_admin_render_now_section' ),
				'uses'             => array( 'label' => 'Uses Page', 'render' => 'sn_admin_render_uses_section' ),
				// v10.33.0: the /resume STRUCTURED editor (repeatable rows, not a
				// plain-text box) — same regenerate-on-save architecture as Now/Uses.
				'resume'           => array( 'label' => 'Resume Page', 'render' => 'sn_admin_render_resume_section', 'wide' => true ),
				// ── The three content scanners, reunited (v10.46.0). Tags was here,
				// Pattern Adoption was a section buried inside Measurement → Health,
				// and Block Migrations was in Tools — three siblings in three tabs.
				// inc/block-migrations-admin.php:18 already said it "mirrors
				// inc/pattern-adoption-admin.php structurally"; now they sit together.
				'tags'             => array( 'label' => 'Tags', 'render' => 'sn_admin_render_tag_cleanup_section', 'wide' => true ),
				'pattern-adoption' => array( 'label' => 'Pattern Adoption', 'render' => 'sn_admin_render_pattern_adoption_section', 'wide' => true ),
				'block-migrations' => array( 'label' => 'Block Migrations', 'render' => 'sn_admin_render_block_migrations_section', 'wide' => true ),
				// v11.2.0: the FOURTH read surface (R4 4A). Vocabulary drift is a
				// scanner in the same sense as its three siblings — it reads what
				// is already published and writes nothing — but proposes nothing
				// either: no queue, no buttons, the mirror recomputes on render.
				// 'wide' earned by the four-column movement grid per year pair.
				'vocabulary'       => array( 'label' => 'Vocabulary', 'render' => 'sn_admin_render_drift_section', 'wide' => true ),
			),
		),
		array(
			'slug'     => 'sn-connections',
			'tab'      => 'connections',
			'label'    => 'Connections',
			'title'    => 'Signal & Noise. Connections',
			'subtitle' => 'Everything that talks to a third party: Cloudflare, webhooks, IndexNow, the Spotify discography, and scheduled jobs.',
			'sub_tabs' => array(
				// Cloudflare moved from Site; webhooks/indexnow/cron from the retired
				// Automation tab (v6.18.0). 'wide' (Phase 3, v6.45.0): all
				// Connections leaves use the full-width layout — Cloudflare/Webhooks lay
				// out work + status/reference in the two-column sn_admin_shell; Cron and
				// Scheduled lead with a glance hero over a full-width data table.
				'cloudflare'        => array( 'label' => 'Cloudflare', 'render' => 'sn_admin_render_cloudflare_section', 'wide' => true ),
				'webhooks'          => array( 'label' => 'Webhooks', 'render' => 'sn_admin_render_webhooks_section', 'wide' => true ),
				'indexnow'          => array( 'label' => 'IndexNow', 'render' => 'sn_admin_render_indexnow_section', 'wide' => true ),
				// v10.46.0: Music moves here from Content. What the leaf actually
				// holds is a Spotify client-id/secret credential form plus a sync —
				// an external-API connection that happens to be about records.
				// Stays an in-page sub-tab (no new sidebar entry) so the desktop-mode
				// submenu-count == top-tab-count invariant holds.
				'music'             => array( 'label' => 'Discography', 'render' => 'sn_admin_render_music_section', 'wide' => true ),
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
			// v10.46.0: relabelled 'Measurement'. The v6.18.0 docblock declared this
			// tab "observability-only" while the same file called its first leaf a
			// "SETTINGS-ONLY sub-tab" — all four leaves carry writes, and the one
			// genuinely read-only surface (the 12-view analytics dashboard) lives
			// under the WP Dashboard menu. Naming it for what it measures is honest;
			// naming it for a read-only rule it never kept was not.
			// KEY STAYS 'monitoring' — hardcoded in five call sites.
			'label'    => 'Measurement',
			'title'    => 'Signal & Noise. Measurement',
			'subtitle' => 'What the site records about itself: analytics, AI insights, content health, and feed requests.',
			// Built above so the Machine Readers preview leaf can splice in.
			'sub_tabs' => $monitoring_sub_tabs,
		),
		array(
			// v10.46.0: AI becomes a tab. Until now its most consequential setting —
			// a hard cap on monthly AI spend — was field 10 of Content → Front-End,
			// a form whose own intro described itself as "render knobs the companion
			// theme reads via filters". The rest of the surface was scattered: spend
			// in Measurement → Insights, tool log in AI → Copilot Usage, MCP doors
			// in Tools → MCP. This gathers the three that are configuration.
			//
			// The slug allow-lists itself: sn_admin_post_allowed_pages() derives from
			// this registry (inc/admin-post-handler.php), so no edit is needed there.
			// A new top tab is safe — the desktop-mode invariant forbids sidebar
			// entries WITHOUT a top tab, not new tabs.
			'slug'     => 'sn-ai',
			'tab'      => 'ai',
			'label'    => 'AI',
			'title'    => 'Signal & Noise — AI',
			'subtitle' => 'Which models run, what they may cost, and which tools are exposed to external clients.',
			'sub_tabs' => array(
				// The three extracted settings (prose model, vision model, monthly
				// budget). Capped card — three fields earn no extra width.
				'models-budget' => array( 'label' => 'Models & Budget', 'render' => 'sn_admin_render_ai_settings_form', 'wide' => true ),
				// v10.47.0 order: the two CONFIGURATION leaves first (which models run,
				// which clients may connect), then the observation leaf. It read
				// config / observation / config before.
				// v9.47.0: read-only "how to connect an external MCP client" doc leaf.
				// No form, no side effects — pure reference, like Links.
				'mcp-connect'   => array( 'label' => 'MCP Clients', 'render' => 'sn_admin_render_mcp_connect_section' ),
				// v9.62.2: Copilot tool-usage diagnostic. 'wide' => true so the
				// wrapper emits a bare .sn-section: the render fn owns its own
				// .sn-card, so a capped .sn-fieldset here would nest a card in a card.
				'copilot-usage' => array( 'label' => 'Copilot Usage', 'render' => 'snt_ai_tool_invocations_render', 'wide' => true ),
			),
		),
		array(
			'slug'     => 'sn-security',
			'tab'      => 'security',
			'label'    => 'Security',
			'title'    => 'Signal & Noise. Security',
			'subtitle' => 'Custom login URL and the admin audit log.',
			'sub_tabs' => array(
				'login'         => array( 'label' => 'Login URL', 'render' => 'sn_admin_render_login_section' ),
				'login-defense' => array( 'label' => 'Login defense', 'render' => 'sn_login_defense_render' ),
				// v3.8.3: audit-log sub-tab. Adding the 2nd sub-tab automatically
				// reveals the sub-tab nav row (sn_admin_render_sub_tabs() hides at count<2).
				// 'wide' (v6.47.0): the audit log leads with a 4-card glance hero over a
				// 7-column counter-timeline table — it earns full width via the wide table
				// (like Cron/Scheduled/Tags). It self-chromes (.postbox panels + glance
				// grid), so it opts out of the wrapper's default capped card. Login URL (a
				// short form) and Login defense (a status box) stay capped — neither earns
				// full width.
				'audit-log' => array( 'label' => 'Audit log', 'render' => 'snt_audit_log_render_tab', 'wide' => true ),
			),
		),
		array(
			'slug'     => 'sn-tools',
			'tab'      => 'tools',
			// v10.47.0: relabelled 'Integrity'. v10.46.0 emptied the junk drawer but
			// left two leaves with no sentence covering both. What actually remained
			// was the cryptographic console plus a link list — so the tab takes the
			// concept the console was already carrying, and the four trust checks
			// that had been marooned as rows inside an eighteen-row Health tab come
			// with it. KEY stays 'tools'.
			'label'    => 'Integrity',
			'title'    => 'Signal & Noise. Integrity',
			// v10.46.0: Tools stops being a junk drawer. Its own subtitle used to
			// name three of its five leaves. Block Migrations went to Content (it is
			// a content scanner), MCP + Copilot Usage to AI. What remains is the pair
			// that genuinely had nowhere else: a cryptographic provenance console and
			// a list of external shortcuts.
			'subtitle' => 'Proof that what is published is what was published, and that machines are told the terms.',
			'sub_tabs' => array(
				// Notes provenance (Plan 6): live anchor-status stepper + public key.
				// Writes go to admin-post.php, not the dispatcher → moved at zero cost.
				'provenance' => array( 'label' => 'Provenance', 'render' => 'sn_admin_render_provenance_section', 'wide' => true ),
				// v10.47.0: the four trust checks, read out of the cached health scan.
				// Second in order deliberately — Provenance already opens with its own
				// glance hero, and two heroes stacked would read as one repeated.
				'trust'      => array( 'label' => 'Trust checks', 'render' => 'sn_admin_render_trust_section', 'wide' => true ),
				// Links last — reference shortcuts (GitHub, release pages, Cloudflare, Cloudways).
				'links'      => array( 'label' => 'Links', 'render' => 'sn_admin_render_links_section' ),
			),
		),
	);
}

/**
 * The leaves that render `data-snt-suggest` / `data-snt-dismiss` buttons, as
 * '<tab>/<sub>' pairs. Consumed by the assets/health-suggest-actions.js enqueue
 * guard in inc/admin-menu.php.
 *
 * WHY THIS IS DATA AND NOT AN `if`. When that guard was a pair of hardcoded
 * tab/sub comparisons, the v6.x IA moved Health under Monitoring, the stale
 * `'health' === $_GET['tab']` check stopped matching, and the script was simply
 * never enqueued — every Suggest button dead, on every site, with no console
 * error and no failing test. That was v6.47.2. v10.46.0 moves two of these three
 * leaves at once, which would have re-created the same outage twice over.
 * Keeping the list here, beside the registry it has to agree with, lets
 * tests/admin-menu.php assert that every entry still resolves to a real leaf AND
 * still enqueues at that address — turning a silent dead-button outage into a
 * red suite.
 *
 * @since 10.46.0
 * @return string[]
 */
function sn_admin_suggest_js_leaves() {
	return array(
		'monitoring/health',           // AI alt/drift/orphan column (self-gates on AI at render time)
		'content/pattern-adoption',    // v10.46.0: promoted out of the Health tab
		'content/block-migrations',    // v10.46.0: moved from Tools
	);
}
