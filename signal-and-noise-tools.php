<?php
/**
 * Plugin Name: Signal & Noise Tools
 * Plugin URI:  https://github.com/juanlentino/signal-and-noise-tools
 * Description: Companion plugin for the Signal & Noise theme. The site's operational layer: first-party edge analytics with insights and narration, content health scans, SEO + OG cards, Note provenance and anchoring, AI editor assists exposed as WP Abilities (no bespoke REST routes), cron/uptime monitoring, and GitHub-driven self-updates. Security headers are delegated to the Cloudflare edge (drift-probed here).
 * Version:     10.2.6
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.3
 * Author:      Juan Lentino
 * Author URI:  https://juanlentino.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: signal-and-noise-tools
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v3.8.2: derive SNT_VERSION from the docblock Version header above so future
// version bumps need only edit the docblock (single source of truth). Previously
// hardcoded as a literal string, which drifted: bumping the docblock to 3.8.0 then
// 3.8.1 left this constant at '3.7.6', causing (1) wrong version on the dashboard
// widget that reads SNT_VERSION directly, and (2) stale `?ver=…` cache-buster on
// admin.css so browsers served the OLD admin.css that lacked `.sn-sub-tabs` rules
// (file on disk was updated but URL cache key didn't change → browser served cached
// content). Reading from the docblock at load eliminates the two-sources-of-truth bug.
$snt_plugin_data = function_exists( 'get_file_data' )
	? get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' )
	: array( 'Version' => '0.0.0' );
define( 'SNT_VERSION', $snt_plugin_data['Version'] ?: '0.0.0' );
unset( $snt_plugin_data );
define( 'SNT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SNT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Pre-flight: detect a legacy-theme conflict before loading any modules.
 *
 * Phase 1 of the theme/plugin split shipped as a coordinated release —
 * plugin v1.0.0 first, then theme v8.2.0. Activating the plugin while
 * the theme is still at v8.1.x leaves the 9 legacy module files on disk
 * inside the theme. PHP would then fatal with "Cannot redeclare function
 * sn_*" at theme-load time, the moment both packages try to declare the
 * same function names on the same request.
 *
 * The fix: if any of the legacy theme files is still present, skip the
 * entire require_once chain below and surface an admin notice asking the
 * maintainer to update the theme first. Detection runs against one
 * canonical file (inc/admin-page.php) — the theme either has all 9 of
 * the legacy modules or none of them; checking one is sufficient.
 *
 * @since 1.0.1
 */
$snt_stylesheet = get_option( 'stylesheet', '' );
if ( 'signal-and-noise' === $snt_stylesheet
	&& file_exists( WP_CONTENT_DIR . '/themes/' . $snt_stylesheet . '/inc/admin-page.php' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p><strong>Signal &amp; Noise Tools:</strong> The Signal &amp; Noise theme is still at v8.1.x (the legacy <code>inc/</code> modules that were supposed to migrate to this plugin are still on disk inside the theme). <strong>Please update the theme to v8.2.0+ before this plugin can load</strong> — otherwise PHP would fatal on duplicate function declarations.</p></div>';
	} );
	return; // Skip the entire require chain; the theme owns the modules right now.
}

// Module includes.
require_once SNT_PATH . 'inc/settings.php';
require_once SNT_PATH . 'inc/beacon-owner-exclusion.php'; // v6.23.0: Plausible-style owner/role analytics exclusion (sn_beacon_enabled filter)
require_once SNT_PATH . 'inc/seo.php';
require_once SNT_PATH . 'inc/robots-txt.php'; // v6.53.0: robots.txt AI-crawler policy (filterable allow/deny) + idempotent Sitemap pointer
require_once SNT_PATH . 'inc/security-headers.php';
require_once SNT_PATH . 'inc/rest-hardening.php'; // v9.83.0: anonymous REST surface — route removal (users/comments/batch), rendered-field stripping on posts/pages, TDM headers on every dispatch
require_once SNT_PATH . 'inc/cloudflare-purge.php';
require_once SNT_PATH . 'inc/cloudways-purge.php';    // v8.6.0: reliable Varnish clear via the Cloudways API (rides breeze_clear_varnish)
require_once SNT_PATH . 'inc/freshness-indicator.php'; // v8.5.1: dashboard cache-freshness dot (client-checked CSS-hash)
require_once SNT_PATH . 'inc/provenance-core.php'; // Notes provenance: commit-chain core (Plan 1)
require_once SNT_PATH . 'inc/provenance-webhook.php'; // Notes provenance: Worker glue (Plan 4)
require_once SNT_PATH . 'inc/provenance-genesis.php'; // Notes provenance: genesis snapshot (Plan 5)
require_once SNT_PATH . 'inc/provenance-render.php'; // Notes provenance: public surfaces (Plan 6)
require_once SNT_PATH . 'inc/provenance-admin.php'; // Notes provenance: admin surface (Plan 6)
require_once SNT_PATH . 'inc/provenance-did.php';        // v9.23.0: did:web DID document (verifiable provenance D1)
require_once SNT_PATH . 'inc/provenance-credential.php'; // v9.23.0: per-Note Verifiable Credential + REST route (D1)
require_once SNT_PATH . 'inc/provenance-verify.php';     // v9.73.0: human-facing /verify client-side verifier
// Scheduled-content subsystem (v6.40.0, Phase 1): hand-authored fragments and
// pages flipped on/off on a date, with a surgical Cloudflare purge at each
// window edge. Loaded right after cloudflare-purge.php so the purge-by-URL fn
// (sn_cf_purge_urls) the cache seam wraps already exists. Order is
// dependency-sensible: the engine defines the row accessors, UTC gate, fire +
// reconcile handlers, cron registration, and the fire/reconcile hook constants
// first; the cache seam, block, save_post sync, page adapter, and admin surface
// follow. (Cross-calls all happen at runtime inside hooks, so exact order is
// not load-critical, only sensible.)
require_once SNT_PATH . 'inc/schedule-engine.php';
require_once SNT_PATH . 'inc/schedule-cache.php';
require_once SNT_PATH . 'inc/schedule-swap.php';    // v8.0.0: version-swap pairing + atomic run
require_once SNT_PATH . 'inc/schedule-block.php';
require_once SNT_PATH . 'inc/schedule-sync.php';
require_once SNT_PATH . 'inc/schedule-pages.php';
require_once SNT_PATH . 'inc/schedule-admin.php';
require_once SNT_PATH . 'inc/analytics-panels.php'; // v8.5.0: the ONE panel-chrome primitive for the Analytics page
require_once SNT_PATH . 'inc/analytics-annotations.php'; // v9.4.0: rules-only panel-annotation resolvers
require_once SNT_PATH . 'inc/analytics-widget.php';
// First-party edge analytics (P2 data layer). analytics-api.php is the AE SQL
// read-client; analytics-rollup.php (its first consumer) must load after it.
require_once SNT_PATH . 'inc/analytics-api.php';
require_once SNT_PATH . 'inc/analytics-rollup.php';
require_once SNT_PATH . 'inc/analytics-derive.php'; // Phase A pure derive layer (spec §4) — zero WP calls; consumed by the read layer
require_once SNT_PATH . 'inc/analytics-realtime.php';
require_once SNT_PATH . 'inc/analytics-read.php';   // path read accessors (dashboard + widgets)
require_once SNT_PATH . 'inc/analytics-sessions.php';       // within-day session engine (pure)
require_once SNT_PATH . 'inc/analytics-view-sessions.php';  // Visits view
require_once SNT_PATH . 'inc/analytics-session-rollup.php'; // durable session-quality rollup + cron
require_once SNT_PATH . 'inc/analytics-movers.php'; // v8.5.0: landing "Movers" tile (views delta vs prior window)
require_once SNT_PATH . 'inc/analytics-header-region.php'; // v8.5.0: the shared header frame (Overview + rail + uptime detail)
require_once SNT_PATH . 'inc/analytics-view-content.php';  // v8.5.0: the regrouped Content view (default landing)
require_once SNT_PATH . 'inc/analytics-view-campaigns.php'; // v9.29.0: the dedicated UTM Campaigns view
require_once SNT_PATH . 'inc/analytics-recommendations.php'; // v9.6.0: deep-linked action cards rendered atop the Content view (annotations R3b)
require_once SNT_PATH . 'inc/analytics-view-technology.php'; // v8.5.0 extraction
require_once SNT_PATH . 'inc/analytics-view-geography.php';  // v8.5.0 extraction
require_once SNT_PATH . 'inc/analytics-view-engagement.php'; // v8.5.0 extraction
require_once SNT_PATH . 'inc/analytics-view-quality.php';    // v8.5.0 extraction
require_once SNT_PATH . 'inc/analytics-view-events.php';     // v8.5.0 extraction
require_once SNT_PATH . 'inc/analytics-view-overview.php';   // v9.68.0: the wired Overview landing (default tab — the v9.67.0 mock, graduated)
require_once SNT_PATH . 'inc/analytics-sources.php'; // v6.25.0: referrer host → canonical source fold (brand grouping + self-referral/www)
require_once SNT_PATH . 'inc/analytics-dims.php';   // referrer/country/device + edge dimension breakdowns
require_once SNT_PATH . 'inc/analytics-utm.php';    // v9.28.0: UTM campaign attribution (packed blob20 → Source/Medium + Campaign)
require_once SNT_PATH . 'inc/analytics-events.php'; // v6.2.0: custom-events table install + read accessors
require_once SNT_PATH . 'inc/analytics-events-rollup.php'; // v6.10.0: live ce/cp rollups feeding the events tables
require_once SNT_PATH . 'inc/analytics-buckets.php'; // derived: hour-of-day heatmap + scroll/time distributions
require_once SNT_PATH . 'inc/analytics-percentiles.php'; // on-demand scroll/time percentiles (p50/p75/p90)
require_once SNT_PATH . 'inc/analytics-drilldown.php'; // on-demand cross-tab dimension drill-down
require_once SNT_PATH . 'inc/analytics-pageroles.php'; // v6.10.0: durable entry/exit page-roles table + entry rollup
require_once SNT_PATH . 'inc/analytics-derived.php'; // PHP-only derived: referrer categories, deltas, bot breakdown
require_once SNT_PATH . 'inc/analytics-admin-render.php'; // page partials (loaded before the orchestrator)
require_once SNT_PATH . 'inc/analytics-posts.php';       // v6.39.0: post-lifecycle data layer (durable per-path rollup)
require_once SNT_PATH . 'inc/analytics-posts-admin.php'; // v6.39.0: Posts view render (reuses admin-render helpers)
require_once SNT_PATH . 'inc/analytics-posts-lifecycle.php';       // v8.11.0 (A4): catalogue-wide decay census + refresh candidates
require_once SNT_PATH . 'inc/analytics-posts-lifecycle-admin.php'; // v8.11.0 (A4): "Lifecycle at scale" render
require_once SNT_PATH . 'inc/analytics-admin.php';  // dashboard renderer + Monitoring → Analytics settings
require_once SNT_PATH . 'inc/analytics-signals.php';   // v9.30.0: predictive signal engine
require_once SNT_PATH . 'inc/ai-markdown-strip.php';   // v9.64.2: shared markdown stripper for AI prose (narrator + narration)
require_once SNT_PATH . 'inc/analytics-narrator.php';  // v9.30.0: diagnostic/prescriptive narrator
require_once SNT_PATH . 'inc/analytics-insights.php';  // v9.30.0: the Insights band
require_once SNT_PATH . 'inc/analytics-maturity-page.php'; // v9.35.0: [sn_analytics_maturity] public explainer (I6)
require_once SNT_PATH . 'inc/edge-analytics.php';  // v6.26.0: Cloudflare GraphQL zone-analytics client (edge traffic)
require_once SNT_PATH . 'inc/edge-rollup.php';     // v6.26.0: edge daily/dims tables + daily GraphQL rollup cron
require_once SNT_PATH . 'inc/edge-admin.php';      // v6.26.0: "Traffic & edge" Analytics view (presenter)
require_once SNT_PATH . 'inc/analytics-dashboard-page.php'; // WP Dashboard → Analytics read-only page
require_once SNT_PATH . 'inc/admin-bar.php';
require_once SNT_PATH . 'inc/admin-page.php';
// Admin UI — split out of the former 1,468-line inc/admin-page.php in v4.5.4.
// Order is irrelevant: every cross-call between these modules happens at
// runtime (inside admin_init / admin_menu / render hooks), never at load.
require_once SNT_PATH . 'inc/admin-tabs-data.php';
require_once SNT_PATH . 'inc/admin-tabs.php';
require_once SNT_PATH . 'inc/admin-render-sections.php'; // admin refactor Phase 1: named leaf render wrappers
require_once SNT_PATH . 'inc/admin-dispatch.php';        // admin refactor Phase 1: registry-driven render dispatcher
require_once SNT_PATH . 'inc/admin-shell.php';           // v6.42.0: two-column main+rail layout primitive
require_once SNT_PATH . 'inc/admin-glance.php';          // Phase 1 redesign: reusable first-glance stat-card grid
require_once SNT_PATH . 'inc/admin-legacy-redirect.php';
require_once SNT_PATH . 'inc/admin-menu.php';
require_once SNT_PATH . 'inc/admin-flash-messages.php';
require_once SNT_PATH . 'inc/admin-post-handler.php';
require_once SNT_PATH . 'inc/admin-post-actions.php';
require_once SNT_PATH . 'inc/admin-forms/identity-and-seo.php';
require_once SNT_PATH . 'inc/admin-forms/login.php';
require_once SNT_PATH . 'inc/admin-forms/links.php';
require_once SNT_PATH . 'inc/admin-forms/performance.php'; // v4.10.0: Tools → Performance (Speculation Rules toggle)
require_once SNT_PATH . 'inc/admin-forms/front-end.php';     // v4.12.0: Tools → Front-End (theme render knobs)
require_once SNT_PATH . 'inc/admin-forms/music.php';         // v4.13.0: Monitoring → Music (Spotify creds + Muso profile + Sync now)
require_once SNT_PATH . 'inc/admin-forms/indexnow.php';     // v5.1.0: Automation → IndexNow (enable toggle + key URL + backfill)
require_once SNT_PATH . 'inc/theme-filters.php';             // v4.12.0: supply configured theme.* values to theme/plugin filters (front-end)
require_once SNT_PATH . 'inc/now-page.php';                  // v7.5.0: /now content editor data layer + sn_now_sections/sn_now_updated feed
require_once SNT_PATH . 'inc/uses-page.php';                 // v7.6.0: /uses content editor data layer + sn_uses_groups feed (shares the /now section grammar)
require_once SNT_PATH . 'inc/admin-forms/now-page.php';      // v7.5.0: Content → Now Page editor form
require_once SNT_PATH . 'inc/admin-forms/uses-page.php';     // v7.6.0: Content → Uses Page editor form (prefills from the theme's live list)
require_once SNT_PATH . 'inc/rest-api.php';
require_once SNT_PATH . 'inc/analytics-rest.php'; // v6.1.0: read-only /analytics REST routes
require_once SNT_PATH . 'inc/analytics-refresh-rest.php'; // v9.27.0: token-gated rollup-refresh trigger (CF Cron worker → reliable freshness)

// v9.22.0: native MCP server — POST /wp-json/signal-noise/v1/mcp exposes a
// read-only allowlist of Abilities as MCP tools (machine-readability program,
// sub-project B). Required AFTER rest-api.php so SN_REST_NAMESPACE is defined.
// Load order = capabilities → tools → resources → prompts → server → endpoint
// (dependency order — mcp-server.php's router calls into all four).
require_once SNT_PATH . 'inc/mcp/mcp-capabilities.php';
require_once SNT_PATH . 'inc/mcp/mcp-tools.php';
require_once SNT_PATH . 'inc/mcp/mcp-rw-audit.php'; // v9.51.0: rw-door audit log + owner notification (lane SEC-B) — sn_mcp_call_tool()'s tail calls into this.
require_once SNT_PATH . 'inc/mcp/mcp-resources.php'; // v9.50.0: resources/list + resources/read (lane PROTO)
require_once SNT_PATH . 'inc/mcp/mcp-prompts.php';   // v9.50.0: prompts/list + prompts/get (lane PROTO)
require_once SNT_PATH . 'inc/mcp/mcp-server.php';
require_once SNT_PATH . 'inc/mcp/mcp-rw-guard.php'; // v9.51.0: rw-door credential split + kill switch (lane SEC-A) — before mcp-endpoint.php, which calls it.
require_once SNT_PATH . 'inc/mcp/mcp-endpoint.php';
require_once SNT_PATH . 'inc/admin-forms/mcp-connect.php'; // v9.47.0: Tools → Connect an MCP client (read-only doc leaf; needs sn_mcp_allowlist() + sn_mcp_namespace() above)

// Shared outbound SSRF host-guard (resolve-then-range-check; blocks encoded-IP
// metadata bypasses). Pure functions, no hooks — load it BEFORE every consumer:
// rss-feed-tracker (just below), webhooks, uptime-heartbeat, and
// health-external-links all call sn_ssrf_host_blocked(). (v6.13.2: moved up from
// the webhooks group so the earliest consumer, rss-feed-tracker, is covered.)
require_once SNT_PATH . 'inc/ssrf-guard.php';

// Machine Readers surface (v9.85.0, Session 3): the rights-signals sensor read
// (Bearer-token worker fetch + enum-allowlist normalization), the pure table
// renderers, and the Monitoring sub-tab registration + settings sub-form. The
// tab is preview-flagged (sn_machine_readers_preview, the v9.67.0 Overview
// pattern); the v10.0.0 GA flip makes it default. Loads AFTER ssrf-guard so
// the function_exists-guarded sn_ssrf_host_blocked() call in the fetch path is
// enforcing, never skipped. The companion drift probe
// (inc/health-check-rights-signals.php) rides the inc/health-checks.php
// orchestrator like every other health check.
require_once SNT_PATH . 'inc/machine-readers-api.php';
require_once SNT_PATH . 'inc/machine-readers-summary.php'; // v10.2.0: the one summary builder (tile route + ability).
require_once SNT_PATH . 'inc/machine-readers-render.php';
// The one-sentence summarizer, loaded AFTER the render module whose aggregate
// helpers it reads. No side effects, no hooks: a pure string builder narrator
// surfaces can call once they hold a payload.
require_once SNT_PATH . 'inc/machine-readers-narration.php';
// Crawler-family volume deltas as insight cards (R3). Loads AFTER the render
// module too: it reuses that lane's one "reads per family" aggregator. Pure
// detector plus one guarded fetch wrapper, no hooks, no side effects.
require_once SNT_PATH . 'inc/machine-readers-insights.php';
require_once SNT_PATH . 'inc/machine-readers-admin.php';

// RSS feed-request tracker. (v1.1.0–v6.20.0 this was inc/rss-plausible-tracker.php,
// migrated from a theme MU-plugin of the same name; the v1.1.0 MU-twin redeclare
// guard was removed in v6.20.1 — the migration completed at theme v8.2.1 and the
// plugin's own copy is demonstrably the live one.)
require_once SNT_PATH . 'inc/rss-feed-tracker.php';

// Analytics edge-Worker version readout (Monitoring → Analytics). Derives the
// Worker's /_sn/version URL from the collector base above (rss-feed-tracker's
// collector_url) and routes the probe through the shared SSRF guard, so it
// must load AFTER both — and before the Phase-3 guard, since its render
// consumer (inc/analytics-admin-render.php) is in this always-loaded prefix.
require_once SNT_PATH . 'inc/worker-version.php';

// Identity-salt window readout (v9.71.0): a passive date-window row in the
// analytics settings reference column, read from the SAME /_sn/version
// endpoint (worker v1.14.0+ adds the public "salt" object — dates + expiry
// times only, never salt values). Depends on worker-version's endpoint
// derivation + the shared SSRF guard, so it loads right after both.
require_once SNT_PATH . 'inc/analytics-salt-window.php';

// get-collector-status ability (v9.81.0): named invariants over the same
// /_sn/version payload (config bindings, salt window, version, cron freshness).
require_once SNT_PATH . 'inc/abilities-collector-status.php';

// Login defense panel: reads the sn_login_guard AE dataset + probes the
// sn-login-guard Worker status. Loads after analytics-api + ssrf-guard +
// worker-version (all its dependencies).
require_once SNT_PATH . 'inc/login-defense.php';
// Login defense analytics: the Analytics-dashboard Login defense view + renderers
// (reads the same query builders); and the dashboard widget (owner-requested glance).
require_once SNT_PATH . 'inc/login-defense-analytics.php';
require_once SNT_PATH . 'inc/login-defense-widget.php';
require_once SNT_PATH . 'inc/site-health-widget.php'; // v7.0.0: "S&N Health" home dashboard widget (owner-approved 2nd widget exception)

// ── Guard #3 (v1.3.0): function-redeclare defense ──────────────────
//
// Phase 3 moved og-image.php, reading-time.php, and notes-and-provenance.php
// from theme to plugin. If the theme still ships those files (i.e. user
// installed plugin v1.3.0 before theme v8.4.0), our require_once chain
// would PHP-fatal at parse time with "Cannot redeclare function." Bail
// with a clear admin notice instead of a white-screen-of-death.
$sn_phase3_theme_dir = get_template_directory();
$sn_phase3_retired   = array(
	$sn_phase3_theme_dir . '/inc/og-image.php',
	$sn_phase3_theme_dir . '/inc/reading-time.php',
	$sn_phase3_theme_dir . '/inc/notes-and-provenance.php',
);
foreach ( $sn_phase3_retired as $sn_phase3_legacy_file ) {
	if ( file_exists( $sn_phase3_legacy_file ) ) {
		add_action( 'admin_notices', function() use ( $sn_phase3_legacy_file ) {
			$rel = str_replace( ABSPATH, '', $sn_phase3_legacy_file );
			echo '<div class="notice notice-error"><p><strong>Signal &amp; Noise Tools v1.3.0:</strong> theme still ships <code>' . esc_html( $rel ) . '</code>. Update theme to v8.4.0+ first to avoid function-redeclare fatals. Plugin require chain skipped.</p></div>';
		} );
		return; // Skip the require_once chain entirely.
	}
}
unset( $sn_phase3_theme_dir, $sn_phase3_retired, $sn_phase3_legacy_file );

require_once __DIR__ . '/inc/content-rendering-helpers.php';
require_once __DIR__ . '/inc/content-surfaces.php';
require_once __DIR__ . '/inc/page-sync-engine.php';   // v9.81.0: LIVE Now/Uses per-save dossier sync engine (split out of content-migrations)
require_once __DIR__ . '/inc/content-migrations.php'; // spent one-shot seeds behind the master sentinel (sn_run_content_migrations)
require_once __DIR__ . '/inc/tag-consolidation.php';
require_once __DIR__ . '/inc/tag-consolidation-redirects.php'; // front end too (301 handler)
require_once __DIR__ . '/inc/tag-consolidation-admin.php';
// v8.10.0 Redirects arc (B1 + B2): general owner-authored redirect map + front-end
// 404 capture log. Load order is dependency-sensible — the store owns the shared
// path normalizer the 404 log reuses; the handler wires both onto template_redirect
// (front end too); the admin file renders the CRUD + 404 list.
require_once __DIR__ . '/inc/redirects-store.php';
require_once __DIR__ . '/inc/redirects-404-log.php';
require_once __DIR__ . '/inc/redirects-handler.php';
require_once __DIR__ . '/inc/redirects-admin.php';
require_once __DIR__ . '/inc/og-card-generator.php';
require_once __DIR__ . '/inc/og-card-provenance.php'; // v9.25.0: embed provenance in OG cards (machine-readability D2)
require_once __DIR__ . '/inc/reading-time.php';
require_once __DIR__ . '/inc/wp-update-integration.php';
require_once __DIR__ . '/inc/wp-update-git-preservation.php';
require_once __DIR__ . '/inc/github-actions-api.php';
require_once __DIR__ . '/inc/deploy-history.php';
require_once __DIR__ . '/inc/api-rate-monitor.php';
require_once __DIR__ . '/inc/admin-tab-dashboard.php';
require_once __DIR__ . '/inc/desktop-mode-integration.php';
require_once __DIR__ . '/inc/desktop-mode-attention.php';
require_once __DIR__ . '/inc/desktop-mode-dropzone.php';
require_once __DIR__ . '/inc/ai-bootstrap.php';
require_once __DIR__ . '/inc/ai-tool-invocation-log.php';
require_once __DIR__ . '/inc/ai-alt-text-suggest.php';   // primary: owns the shared SNT_AI_ALT_BASE_RULES — must load first
require_once __DIR__ . '/inc/ai-alt-inline-suggest.php'; // sibling: composes its prompt from that base
require_once __DIR__ . '/inc/ai-drift-phrase-suggest.php';
require_once __DIR__ . '/inc/ai-link-suggest.php'; // v7.4.0: unlinked-mention Suggest+Apply (mirrors drift machinery)
require_once __DIR__ . '/inc/ai-pair-suggest.php'; // v8.1.0: semantic-pair Suggest (link_opportunities; Apply rides ai-link-apply)
require_once __DIR__ . '/inc/ai-orphan-suggest.php';
require_once __DIR__ . '/inc/ai-excerpt.php';
require_once __DIR__ . '/inc/ai-tag-suggest.php';
require_once __DIR__ . '/inc/ai-meta-description.php';
require_once __DIR__ . '/inc/ai-og-card-title.php';
require_once __DIR__ . '/inc/ai-ai-dedupe.php';
require_once __DIR__ . '/inc/ai-prepopulate.php';
require_once __DIR__ . '/inc/ai-prepopulate-notice.php';
require_once __DIR__ . '/inc/block-fingerprint-engine.php'; // v7.7.1: shared fingerprint locate/replace/sanitize/apply engine behind both surfaces below.
require_once __DIR__ . '/inc/pattern-adoption-detect.php';
require_once __DIR__ . '/inc/pattern-adoption-suggest.php';
require_once __DIR__ . '/inc/pattern-adoption-apply.php';
require_once __DIR__ . '/inc/pattern-adoption-admin.php';
require_once __DIR__ . '/inc/block-migrations-detect.php';
require_once __DIR__ . '/inc/block-migrations-suggest.php';
require_once __DIR__ . '/inc/block-migrations-apply.php';
require_once __DIR__ . '/inc/block-migrations-admin.php';
require_once __DIR__ . '/inc/abilities-block-migrations.php';
require_once __DIR__ . '/inc/abilities-provenance.php'; // v9.78.0: anchor-status + anchor-sweep
require_once __DIR__ . '/inc/login-hide.php';
require_once __DIR__ . '/inc/seo-schema.php';
require_once __DIR__ . '/inc/discography-store.php';   // v4.13.0: Music Identity — normalized release store (cron is sole writer)
require_once __DIR__ . '/inc/muso-api.php';            // v4.13.0: Music Identity — Muso public credits client + album grouper
require_once __DIR__ . '/inc/spotify-api.php';         // v4.13.0: Music Identity — Spotify album resolver (track id → album)
require_once __DIR__ . '/inc/discography-sync.php';    // v4.13.0: Music Identity — cron sync orchestrator + sn_discography_entries filter
require_once __DIR__ . '/inc/seo-schema-music.php';    // v4.13.0: Music Identity — MusicAlbum/MusicRecording JSON-LD on /music
require_once __DIR__ . '/inc/music-featured.php';      // v4.14.0: settings-driven featured release (sn_music_featured filter)
require_once __DIR__ . '/inc/post-settings.php';
require_once __DIR__ . '/inc/pillar-meta-seed.php'; // v9.79.1: one-time flag+designation seed for the three known essays
require_once __DIR__ . '/inc/post-evergreen.php'; // v8.11.0 (B5): evergreen flag accessor + Posts list column
require_once __DIR__ . '/inc/sitemap.php';
require_once __DIR__ . '/inc/sitemap-redirect.php';
require_once __DIR__ . '/inc/indexnow.php';
require_once __DIR__ . '/inc/websub.php';            // v6.17.0 (D4): WebSub publisher ping (feed-reader push; counterpart to IndexNow)
require_once __DIR__ . '/inc/ability-run-client.php';   // v7.7.2: annotation-derived verb map + shared run-path JS client.
require_once __DIR__ . '/inc/abilities-registration.php';
require_once SNT_PATH . 'inc/abilities-analytics.php';  // v6.1.0: read-only analytics Abilities
require_once __DIR__ . '/inc/migrate-orphan-options.php';  // v5.0.0: one-time orphan-option cleanup
require_once __DIR__ . '/inc/command-palette.php';
require_once __DIR__ . '/inc/pre-publish-gate.php';      // v4.11.0: editor pre-publish advisory gate (client-side, no AI)
require_once SNT_PATH . 'inc/cron-dashboard.php';
require_once SNT_PATH . 'inc/cron-history.php';
require_once SNT_PATH . 'inc/cron-dashboard-admin.php';
require_once SNT_PATH . 'inc/webhooks.php';
require_once SNT_PATH . 'inc/webhooks-admin.php';
require_once SNT_PATH . 'inc/uptime-heartbeat.php';
require_once SNT_PATH . 'inc/uptime-status.php';        // v8.2.0: Better Stack status data layer + ability + field/mount helpers (v8.3.0: + 30d availability)
require_once SNT_PATH . 'inc/uptime-status-widget.php'; // v8.3.0: Uptime section of the S&N Health widget (standalone widget consolidated away) + panel assets
require_once SNT_PATH . 'inc/admin-heartbeat.php';
require_once SNT_PATH . 'inc/insights.php';
require_once SNT_PATH . 'inc/insights-narration.php';
require_once SNT_PATH . 'inc/narration-cron-cleanup.php'; // v9.5.0: one-time clear of the weekly-digest cron orphaned when R2 retired the scheduler
require_once SNT_PATH . 'inc/insights-admin.php';
require_once SNT_PATH . 'inc/health-probe-classify.php'; // shared bot-challenge classifier (used by both health probes below)
require_once SNT_PATH . 'inc/health-checks.php';
require_once SNT_PATH . 'inc/health-summary.php'; // v7.0.0: shared scan-summary accessors (finding total + ranked flagged checks) — glance card, attention strip, S&N Health widget
require_once SNT_PATH . 'inc/health-external-links.php'; // D1 (v6.13.0): 7th check — external link-rot (off-host cited sources)
require_once SNT_PATH . 'inc/health-link-opportunities.php'; // v8.1.0: advisory check — semantic pairs that should link (C2 approach C)
require_once SNT_PATH . 'inc/health-edge-workers.php'; // 8th check (v6.49.0): owned-Worker reachability + login-guard denylist freshness
require_once SNT_PATH . 'inc/health-analytics-integrity.php'; // 12th check (v9.65.0): reader of the never-invert sn_analytics_integrity_alert (Phase A P0.4 closed for real)
require_once SNT_PATH . 'inc/provenance-integrity.php'; // 13th check (v9.80.0): server-side provenance integrity sweep (triangle self-check over the anchored-Note fleet) + readonly status ability
require_once SNT_PATH . 'inc/health-checks-admin.php';
require_once SNT_PATH . 'inc/plugin-footprint.php'; // plugin-directory footprint diagnostic (Site Health) + the one-time legacy-deploy-file janitor (admin_init, once per SNT_VERSION)
require_once SNT_PATH . 'inc/http-diagnostics.php'; // admin-request HTTP-call diagnosis (Site Health) — names the outbound wp_remote_* calls behind slow wp-admin page loads
require_once SNT_PATH . 'inc/scheduled-actions-health.php'; // Action Scheduler backlog diagnostic (Site Health) — observes the third-party queue table whose dispatch-gate COUNT taxes every page load
require_once SNT_PATH . 'inc/audit-log.php';
require_once SNT_PATH . 'inc/audit-log-admin.php';
require_once SNT_PATH . 'inc/audit-log-export.php';  // v4.10.0: CSV/JSON export (download + ability impl)
require_once SNT_PATH . 'inc/security-digest.php';   // v7.2.0: weekly security-digest email (LLAR A2) — deterministic, opt-in default OFF
require_once SNT_PATH . 'inc/privacy-exporters.php'; // v4.10.0: GDPR exporter/eraser + suggested privacy policy text
require_once SNT_PATH . 'inc/speculation-rules.php'; // v4.10.0: opt-in Speculation Rules tuning (prerender/moderate)

// Settings migration: seed legacy values once per environment.
// register_activation_hook fires only on WP-upgrader-driven activations;
// the admin_init handler covers SSH-based git-checkout deploys.
register_activation_hook( __FILE__, 'sn_settings_seed_legacy_values' );
add_action( 'admin_init', 'sn_settings_lazy_migration_check' );
