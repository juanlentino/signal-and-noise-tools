<?php
/**
 * Plugin Name: Signal & Noise Tools
 * Plugin URI:  https://github.com/juanlentino/signal-and-noise-tools
 * Description: Companion plugin for the Signal & Noise theme. The site's operational layer: first-party edge analytics with insights and narration, content health scans, SEO + OG cards, Note provenance and anchoring, AI editor assists exposed as WP Abilities (no bespoke REST routes), cron/uptime monitoring, and GitHub-driven self-updates. Security headers are delegated to the Cloudflare edge (drift-probed here).
 * Version:     13.87.2
 * Requires at least: 7.0
 * Tested up to: 7.1
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
require_once SNT_PATH . 'inc/word-count.php'; // v10.24.0: pure Unicode word counter (reading time, schema wordCount, AI gates)
// v10.43.0: WordPress/openstation rename compat (PR #475, not yet in any
// release). Zero deps of its own; every desktop-mode-* / mcp-telemetry-agents
// consumer below calls into it, so it loads FIRST among them.
require_once SNT_PATH . 'inc/openstation-compat.php';
require_once SNT_PATH . 'inc/openstation-station-home-card.php';
require_once SNT_PATH . 'inc/openstation-agent-output-budget.php'; // WordPress/openstation#517 seam: inject adaptive thinking + effort (Claude 5) and ceiling headroom on agent-run generations; inert while the agents feature is off; remove per the conditions in its docblock (openstation#530/#531)
require_once SNT_PATH . 'inc/settings.php';
require_once SNT_PATH . 'inc/config-drift.php'; // R6a: durable effective-settings baseline + unexplained-drift diff
require_once SNT_PATH . 'inc/beacon-owner-exclusion.php'; // v6.23.0: Plausible-style owner/role analytics exclusion (sn_beacon_enabled filter)
require_once SNT_PATH . 'inc/seo.php';
require_once SNT_PATH . 'inc/robots-txt.php'; // v6.53.0: robots.txt AI-crawler policy (filterable allow/deny) + idempotent Sitemap pointer
require_once SNT_PATH . 'inc/security-headers.php';
require_once SNT_PATH . 'inc/rest-hardening.php'; // v9.83.0: anonymous REST surface — route removal (users/comments/batch), rendered-field stripping on posts/pages, TDM headers on every dispatch
// v11.10.0: purge VERIFICATION must load before cloudflare-purge.php, which
// reads SN_CF_PROBE_HOOK/SN_CF_PROBE_DELAY when scheduling its probe.
require_once SNT_PATH . 'inc/cloudflare-purge-verify.php'; // pure: render normalization + staleness decision
require_once SNT_PATH . 'inc/cloudflare-purge-probe.php';  // scheduled probe + bounded zone-purge escalation
require_once SNT_PATH . 'inc/cloudflare-purge.php';
require_once SNT_PATH . 'inc/cloudways-purge.php';    // v8.6.0: reliable Varnish clear via the Cloudways API (rides breeze_clear_varnish)
require_once SNT_PATH . 'inc/admin-forms/cloudways.php'; // v12.17.0: Connections → Cloudways status glance (display-only; reads SNT_CW_LAST_PURGE_OPT written by the purge above)
require_once SNT_PATH . 'inc/freshness-indicator.php'; // v8.5.1: dashboard cache-freshness dot (client-checked CSS-hash)
require_once SNT_PATH . 'inc/provenance-settle.php'; // v11.10.0: settle window — one editing pass, one signed version (pure; loaded before core, which calls its predicate)
require_once SNT_PATH . 'inc/provenance-core.php'; // Notes provenance: commit-chain core (Plan 1)
require_once SNT_PATH . 'inc/provenance-webhook.php'; // Notes provenance: Worker glue (Plan 4)
require_once SNT_PATH . 'inc/provenance-genesis.php'; // Notes provenance: genesis snapshot (Plan 5)
require_once SNT_PATH . 'inc/provenance-render.php'; // Notes provenance: public surfaces (Plan 6)
require_once SNT_PATH . 'inc/provenance-admin.php'; // Notes provenance: admin surface (Plan 6)
require_once SNT_PATH . 'inc/provenance-chain-backfill.php'; // v10.3.0: one-shot WP chain import for ledger-backfilled Notes
require_once SNT_PATH . 'inc/provenance-freshness-backfill.php'; // v11.11.8: one-shot stamp of _sn_prov_last_commit_gmt onto existing chains (Check 4's clock)
require_once SNT_PATH . 'inc/tag-descriptions-seed.php'; // v13.23.0 one-shot: the 23 owner-approved tag descriptions (own flag + hook — the registry's master sentinel would skip it; see the file header).
require_once SNT_PATH . 'inc/provenance-did.php';        // v9.23.0: did:web DID document (verifiable provenance D1)
require_once SNT_PATH . 'inc/provenance-rotation.php';   // v13.39.0: the key-rotation PRODUCER (needs provenance-did.php above)
require_once SNT_PATH . 'inc/provenance-webfinger.php'; // v11.27.0: WebFinger (RFC 7033) resolving to the SAME did:web identity — coherence, not federation (needs provenance-did.php above)
require_once SNT_PATH . 'inc/provenance-credential.php'; // v9.23.0: per-Note Verifiable Credential + REST route (D1)
require_once SNT_PATH . 'inc/provenance-verify.php';     // v9.73.0: human-facing /verify client-side verifier
require_once SNT_PATH . 'inc/provenance-machine-pointers.php'; // v11.7.0: R5 — the in-page verification manifest + schema identifier (needs provenance-verify's endpoint producer above)
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
require_once SNT_PATH . 'inc/public-stats.php'; // v10.65.0: [sn_public_stats] — the public stats page, rollups read-only (roadmap Analytics planned row)
require_once SNT_PATH . 'inc/analytics-derive.php'; // Phase A pure derive layer (spec §4) — zero WP calls; consumed by the read layer
require_once SNT_PATH . 'inc/analytics-realtime.php';
require_once SNT_PATH . 'inc/analytics-read.php';   // path read accessors (dashboard + widgets)
require_once SNT_PATH . 'inc/analytics-topics.php'; // v10.21.0: topic-level aggregation (ML partition × path rollups)
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
require_once SNT_PATH . 'inc/provenance-maturity-page.php'; // v10.5.0: [sn_provenance_maturity] public explainer (analytics-maturity sibling)
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
require_once SNT_PATH . 'inc/dash-zones.php';            // v11.28.0: zone contract, state, renderer
require_once SNT_PATH . 'inc/dash-pins.php';             // v11.28.0: per-user zone pins + REST toggle
require_once SNT_PATH . 'inc/dash-zone-attention.php';   // v11.28.0: is anything wrong?
require_once SNT_PATH . 'inc/dash-zone-fleet.php';       // v11.28.0: did it ship?
require_once SNT_PATH . 'inc/dash-zone-measurement.php'; // v11.28.0: how is the site doing?
require_once SNT_PATH . 'inc/dash-briefing.php';         // v11.29.1: the briefing band — fixed chrome, cannot be hidden
require_once SNT_PATH . 'inc/dash-trend.php';            // v11.30.0: the 30-day chart
require_once SNT_PATH . 'inc/dash-ops-render.php';       // v11.30.0: the detail columns
require_once SNT_PATH . 'inc/dash-console.php';          // v11.29.1: direction B — band + systems rail + stage
require_once SNT_PATH . 'inc/dash-signals.php';          // v11.30.0: the five signals, each with a comparison
require_once SNT_PATH . 'inc/dash-systems.php';          // v11.30.0: the systems grid (monochrome when healthy)
require_once SNT_PATH . 'inc/dash-freshness.php';        // v11.32.0: how old is the screen — the oldest reading behind the verdict
require_once SNT_PATH . 'inc/dash-verdict.php';          // v11.30.0: one verdict, shared by the widget and the screen
require_once SNT_PATH . 'inc/dash-widget.php';           // v11.30.0: the consolidated index.php widget (folds four boxes)
require_once SNT_PATH . 'inc/dash-widgets.php';          // v13.30.0: four subject boxes beside it, the Classic Admin fallback while OpenStation is severed
require_once SNT_PATH . 'inc/dash-ops-panels.php';       // v11.29.2: the ops wall's panels — a projection of existing accessors
require_once SNT_PATH . 'inc/dash-deploy-rows.php';      // v11.28.1: one deploy run's glyph, repo, duration, relative time
require_once SNT_PATH . 'inc/dash-api-summary.php';      // v11.28.1: the rate-limit line + whether it earns its space
require_once SNT_PATH . 'inc/script-package-origin.php'; // v12.24.0: names the plugin SERVING core's wp-* JS handles (the Gutenberg-override class of breakage)
require_once SNT_PATH . 'inc/dash-debug-info.php';       // v11.28.1: the Site Health > Info panel (not a Dashboard surface)
require_once SNT_PATH . 'inc/admin-legacy-redirect.php';
require_once SNT_PATH . 'inc/admin-menu.php';
require_once SNT_PATH . 'inc/admin-flash-messages.php';
require_once SNT_PATH . 'inc/admin-post-handler.php';
require_once SNT_PATH . 'inc/admin-post-actions.php';
require_once SNT_PATH . 'inc/admin-forms/identity-and-seo.php';
require_once SNT_PATH . 'inc/admin-forms/login.php';
require_once SNT_PATH . 'inc/admin-forms/links.php';
require_once SNT_PATH . 'inc/admin-forms/performance.php'; // v4.10.0: Tools → Performance (Speculation Rules toggle)
require_once SNT_PATH . 'inc/admin-forms/front-end.php';     // v4.12.0: Site → Front-End (theme render knobs)
require_once SNT_PATH . 'inc/admin-forms/ai-settings.php';   // v10.46.0: AI → Models & Budget (extracted out of front-end.php)
require_once SNT_PATH . 'inc/admin-forms/music.php';         // v4.13.0: Connections → Discography (Spotify creds + Muso profile + Sync now)
require_once SNT_PATH . 'inc/admin-forms/indexnow.php';     // v5.1.0: Automation → IndexNow (enable toggle + key URL + backfill)
require_once SNT_PATH . 'inc/theme-filters.php';             // v4.12.0: supply configured theme.* values to theme/plugin filters (front-end)
require_once SNT_PATH . 'inc/now-page.php';                  // v7.5.0: /now content editor data layer + sn_now_sections/sn_now_updated feed
require_once SNT_PATH . 'inc/uses-page.php';                 // v7.6.0: /uses content editor data layer + sn_uses_groups feed (shares the /now section grammar)
require_once SNT_PATH . 'inc/admin-forms/now-page.php';      // v7.5.0: Content → Now Page editor form
require_once SNT_PATH . 'inc/admin-forms/uses-page.php';     // v7.6.0: Content → Uses Page editor form (prefills from the theme's live list)
require_once SNT_PATH . 'inc/resume-page.php';               // v10.33.0: /resume structured editor data layer (document option + normalize/refuse)
require_once SNT_PATH . 'inc/admin-forms/resume-page.php';   // v10.33.0: Content → Resume Page structured editor form (repeatable rows, not a text box)
require_once SNT_PATH . 'inc/rest-api.php';
require_once SNT_PATH . 'inc/analytics-rest.php'; // v6.1.0: read-only /analytics REST routes
require_once SNT_PATH . 'inc/analytics-refresh-rest.php'; // v9.27.0: token-gated rollup-refresh trigger (CF Cron worker → reliable freshness)

// v9.22.0: native MCP server — POST /wp-json/signal-noise/v1/mcp exposes a
// read-only allowlist of Abilities as MCP tools (machine-readability program,
// sub-project B). Required AFTER rest-api.php so SN_REST_NAMESPACE is defined.
// Load order = capabilities → tools → resources → prompts → server → endpoint
// (dependency order — mcp-server.php's router calls into all four).
require_once SNT_PATH . 'inc/mcp/mcp-capabilities.php';
require_once SNT_PATH . 'inc/mcp/mcp-telemetry.php'; // v10.25.0: MCP Layer B telemetry — sn_mcp_call_tool()'s every return point calls into this, both doors. Loaded before mcp-tools.php so the function_exists guards there resolve true.
require_once SNT_PATH . 'inc/mcp/mcp-tools.php';
require_once SNT_PATH . 'inc/mcp/mcp-telemetry-agents.php'; // v10.31.0: bridges Desktop Mode's per-tool agent filter into the same sn_tool_call table, door='agent'. Loaded after mcp-tools.php (reuses its slug→tool_name projection) and after mcp-telemetry.php (reuses its row builder/insert/classifier). Desktop Mode absent = the filter it hooks never fires.
require_once SNT_PATH . 'inc/mcp/mcp-telemetry-read.php'; // the SELECT side of sn_tool_call, which had none until now — install/insert/prune and no reader, so the retirement gate ("nothing retires until usage justifies it") was unmeetable. Loaded after mcp-capabilities.php (allowlists) and mcp-tools.php (slug→tool_name projection + sn_mcp_project_tool for the reachability split); deliberately NOT an ability — a tool that reads the call log writes to the call log.
require_once SNT_PATH . 'inc/mcp/mcp-rw-audit.php'; // v9.51.0: rw-door audit log + owner notification (lane SEC-B) — sn_mcp_call_tool()'s tail calls into this.
require_once SNT_PATH . 'inc/mcp/mcp-resources.php'; // v9.50.0: resources/list + resources/read (lane PROTO)
require_once SNT_PATH . 'inc/mcp/mcp-prompts.php';   // v9.50.0: prompts/list + prompts/get (lane PROTO)
require_once SNT_PATH . 'inc/mcp/mcp-server.php';
require_once SNT_PATH . 'inc/mcp/mcp-rw-guard.php'; // v9.51.0: rw-door credential split + kill switch (lane SEC-A) — before mcp-endpoint.php, which calls it.
require_once SNT_PATH . 'inc/mcp/mcp-read-guard.php'; // v10.9.0: read-door kill switch (isolated from the rw guard by design) — before mcp-endpoint.php, which calls it.
require_once SNT_PATH . 'inc/mcp/mcp-remote-guard.php'; // R3 §3D Increment 1: remote analytics kill switch (fail-CLOSED on absence) — isolated from the read/rw guards by design.
require_once SNT_PATH . 'inc/mcp/mcp-bridge-route.php'; // R3 §3D Increment 1 bridge half: Worker→origin channel, registered only when the switch is on AND SN_BRIDGE_TOKEN is defined.
require_once SNT_PATH . 'inc/mcp/mcp-remote-contract.php'; // versioned-contract phase 2: the payload-shape contract mirror (version constant + hash pin; worker holds phase 1).
require_once SNT_PATH . 'inc/mcp/mcp-remote-observability.php'; // R3 §3D Increment 4: remote-door observability — the bridge feeds this behind function_exists(), so the door works byte-identically without it.
require_once SNT_PATH . 'inc/mcp/mcp-endpoint.php';
require_once SNT_PATH . 'inc/agent-discovery.php'; // v12.14.0: MCP Server Card (SEP-1649) + RFC 9727 API catalog at their STANDARD .well-known paths. Needs sn_mcp_namespace()/sn_mcp_server_info()/sn_mcp_capabilities_map() from the MCP block above. Restates what agents.json already says, at addresses the ecosystem reads; adds no capability and no door.
require_once SNT_PATH . 'inc/agent-ard.php'; // v12.15.0: ARD capability manifest (/.well-known/ai-catalog.json). Loads AFTER agent-discovery.php — reuses its sn_agent_normalize_path()/sn_agent_send_document() and its SN_AGENT_CARD_PATH/SN_AGENT_CATALOG_PATH constants.
require_once SNT_PATH . 'inc/agent-a2a.php'; // v12.20.0: A2A Agent Card (/.well-known/agent-card.json). Loads AFTER agent-discovery.php — reuses its sn_agent_normalize_path()/sn_agent_send_document()/sn_agent_mcp_endpoint_url(). Declares an MCP transport, NOT an A2A JSON-RPC binding: the site does not speak A2A, and a conformant-looking card over an endpoint that rejects message/send is a trap, not discovery.
require_once SNT_PATH . 'inc/agent-skills.php'; // v12.20.0: Agent Skills Discovery RFC v0.2.0 index (/.well-known/agent-skills/index.json) + its SKILL.md artifacts. Loads AFTER agent-discovery.php — reuses sn_agent_normalize_path()/sn_agent_send_document(). Digests are computed from the SAME file bytes served, at request time, so an edited skill cannot ship a stale digest.
require_once SNT_PATH . 'inc/agent-auth-md.php'; // v12.21.0: /auth.md — how an agent obtains a credential for the MCP read door. Loads AFTER agent-discovery.php (reuses sn_agent_normalize_path()/sn_agent_mcp_endpoint_url()/SN_AGENT_CARD_PATH). Deliberately does NOT satisfy the authMd readiness check: that also wants oauth-authorization-server metadata with a register_uri, and this site is not an OAuth AS and operates no registration endpoint.
require_once SNT_PATH . 'inc/admin-forms/mcp-connect-status.php'; // IA M3: the status glance (pure cards + live gatherer)
require_once SNT_PATH . 'inc/admin-forms/mcp-connect.php'; // v9.47.0: Tools → Connect an MCP client (read-only doc leaf; needs sn_mcp_allowlist() + sn_mcp_namespace() above)
require_once SNT_PATH . 'inc/admin-forms/mcp-usage-block.php'; // the READOUT half of mcp-telemetry-read.php, folded into the MCP Clients tab. Shipping the accessor without a surface would have reproduced the very defect it fixes — a measurement that exists and a readout that does not.

// Shared outbound SSRF host-guard (resolve-then-range-check; blocks encoded-IP
// metadata bypasses). Pure functions, no hooks — load it BEFORE every consumer:
// rss-feed-tracker (just below), webhooks, uptime-heartbeat, and
// health-external-links all call sn_ssrf_host_blocked(). (v6.13.2: moved up from
// the webhooks group so the earliest consumer, rss-feed-tracker, is covered.)
// v13.55.0 — Phase 0 of the measurement weave: ONE join key for cross-instrument
// joins (AE paths <-> GSC pages <-> permalinks). It does not replace the four
// local path normalizers, whose jobs differ; it exists so a join across them
// stops dropping rows silently.
require_once SNT_PATH . 'inc/path-join-key.php';
// v13.56.0 — batch reschedule, a wp-admin bulk action (surface decision D2).
// Deliberately NOT an sn-apply change type: that would weaken the flat
// "post_date never moves" invariant protecting MCP writes.
require_once SNT_PATH . 'inc/batch-schedule.php';
require_once SNT_PATH . 'inc/abilities-search-console.php';
require_once SNT_PATH . 'inc/abilities-family-drift.php'; // v13.62.0: the family_drift sn-status source (stored report only). // v13.57.0: measurement weave Phase 1 — Search Console on the read door (sn-status sections).
require_once SNT_PATH . 'inc/abilities-inbound-pass.php'; // v13.68.0: the inbound_pass sn-status source (stored report only).
require_once SNT_PATH . 'inc/ssrf-guard.php';
// v13.54.0 — Phase 0 of the breached-credential arc: the HIBP k-anonymity
// client only. It registers NO hooks and cannot reject or warn about anything;
// Mode A (set-time, fail-closed) and Mode B (login-time, advisory) are later
// phases, and a test asserts this file stays hookless until then.
require_once SNT_PATH . 'inc/breached-credentials.php';
require_once SNT_PATH . 'inc/breached-credentials-set.php'; // v13.58.0: Mode A — set-time, blocking, FAIL-CLOSED (user_profile_update_errors + validate_password_reset).
require_once SNT_PATH . 'inc/breached-credentials-login.php'; // v13.59.0: Mode B — login-time, advisory, fail-OPEN, memoized against the stored hash (authenticate @30).
require_once SNT_PATH . 'inc/breached-credentials-surface.php'; // v13.60.0: Phase 3 — Site Health row + security-digest section over Mode A's counts and Mode B's memos.
// v11.27.0: the verified citation graph. Loads AFTER ssrf-guard — the verifier
// re-validates every redirect hop through it, because wp_http_validate_url()
// does not cover the link-local range that guard exists to close.
require_once SNT_PATH . 'inc/citations-core.php';     // pure: URL normalising, link + identity detection, the tier ladder
require_once SNT_PATH . 'inc/citations-store.php';    // the table; last_checked_gmt is NULLable on purpose (never-measured is its own answer)
require_once SNT_PATH . 'inc/citations-verify.php';   // the adjudicator + hourly cron
require_once SNT_PATH . 'inc/citations-endpoint.php'; // the public inbox AND its discovery advertisement
require_once SNT_PATH . 'inc/citations-admin.php';    // Integrity -> Citations leaf: a three-way readout, never a fraction
require_once SNT_PATH . 'inc/citations-render.php';   // v11.28.0: the public "Cited by" aside — publishable tiers only, silent when empty

// Machine Readers surface (v9.85.0, Session 3): the rights-signals sensor read
// (Bearer-token worker fetch + enum-allowlist normalization), the pure table
// renderers, and the Monitoring sub-tab registration + settings sub-form. The
// tab is preview-flagged (sn_machine_readers_preview, the v9.67.0 Overview
// pattern); the v10.0.0 GA flip makes it default. Loads AFTER ssrf-guard so
// the function_exists-guarded sn_ssrf_host_blocked() call in the fetch path is
// enforcing, never skipped. The companion drift probe
// (inc/health-check-rights-signals.php) rides the inc/health-checks.php
// orchestrator like every other health check.
require_once SNT_PATH . 'inc/machine-readers-taxonomy.php'; // v10.79.0: vendor/purpose enums + normalizers (api.php uses them).
require_once SNT_PATH . 'inc/machine-readers-api.php';
require_once SNT_PATH . 'inc/shape-ledger.php';       // v13.84.0: generic payload-structure ledger (no WP calls beyond options).
require_once SNT_PATH . 'inc/mr-series.php';           // v13.76.0: pure daily-series reshape over the sensor's day grain (no WP calls).
require_once SNT_PATH . 'inc/family-drift.php'; // v13.62.0: weave Phase 5 — weekly enum-drift check (plugin enum vs deployed worker vs two pinned corpora), fail-closed.
require_once SNT_PATH . 'inc/inbound-pass.php'; // v13.68.0: daily inbound-link pass — judges older→new pairs for notes born with zero inbound links; fail-closed on an unavailable provider.
// R3 gate 3A: the durable crawler snapshot. Loads right after the fetch layer it
// wraps, because it is the ONLY caller allowed to fetch on a schedule — every
// reader-facing count is meant to come from its option, so a render never waits
// on the sensor. Owns one hourly cron event; no output sinks.
require_once SNT_PATH . 'inc/machine-readers-snapshot.php';
// R3 gate 3B: the rights-read count as a public claim. Pure reader of the
// snapshot above — hand it a record, get a sentence. No sensor call on this
// path by construction, which is what lets it render on a front-end page.
require_once SNT_PATH . 'inc/machine-readers-rights-reads.php';
// The operator map: the one place that says which crawler families and which
// referrer hosts are the same company. Pure data + lookups, no hooks, no output.
// It is the NAMED GATE on the give-back ratio — the crawler taxonomy and the
// AI-referrer list are different vocabularies and must never be joined by name.
require_once SNT_PATH . 'inc/machine-readers-operators.php';
// The give-back ratio: readers returned per crawl, per operator. Pure — handed a
// snapshot and a referral map, fetches nothing, so it is safe on a render path.
// Loads after the map it divides across.
require_once SNT_PATH . 'inc/machine-readers-giveback.php';
require_once SNT_PATH . 'inc/machine-readers-summary.php'; // v10.2.0: the one summary builder (tile route + ability).
require_once SNT_PATH . 'inc/machine-readers-render.php';
require_once SNT_PATH . 'inc/machine-readers-render-taxonomy.php'; // v10.79.0: purpose/vendor tables + the unknown-agent review.
require_once SNT_PATH . 'inc/machine-readers-compose.php'; // v12.22.0: the leaf's arrangement, pure — see docs/proposals/admin-leaf-composition-2026-08-23.md
// The one-sentence summarizer, loaded AFTER the render module whose aggregate
// helpers it reads. No side effects, no hooks: a pure string builder narrator
// surfaces can call once they hold a payload.
require_once SNT_PATH . 'inc/machine-readers-narration.php';
// Crawler-family volume deltas as insight cards (R3). Loads AFTER the render
// module too: it reuses that lane's one "reads per family" aggregator. Pure
// detector plus one guarded fetch wrapper, no hooks, no side effects.
require_once SNT_PATH . 'inc/machine-readers-insights.php';
require_once SNT_PATH . 'inc/machine-readers-admin.php';
require_once SNT_PATH . 'inc/search-console-credential.php'; // R6b: the GSC service-account credential (storage + validation).
require_once SNT_PATH . 'inc/search-console-client.php';     // R6b: JWT grant + Search Console API reads.
require_once SNT_PATH . 'inc/search-console-store.php';
require_once SNT_PATH . 'inc/search-console-sync.php';      // R6b close: the scheduled daily sync (one producer with the button).
require_once SNT_PATH . 'inc/search-console-derive.php';    // Items 2+3: position drift + search interest by topic (derived, read-only).
require_once SNT_PATH . 'inc/search-console-crossexam.php';
require_once SNT_PATH . 'inc/search-console-coverage.php';  // v13.63.0: weekly URL Inspection per post — the indexed-vs-unseen discriminator; stored, never live.  // R6b: GSC x crawler-ledger agreement check.
require_once SNT_PATH . 'inc/analytics-view-search.php';     // R6b: the Search analytics view (its own tab).      // R6b: the path-keyed rolling window the analytics views join against.
require_once SNT_PATH . 'inc/search-console-admin.php';      // R6b: Measurement -> Search Console leaf.

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
require_once SNT_PATH . 'inc/login-defense-gauges.php';
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
require_once __DIR__ . '/inc/generated-page-contract.php';  // v10.44.0: structural contract for engine-built page bodies, enforced at the write boundary
require_once __DIR__ . '/inc/page-sync-engine.php';   // v9.81.0: LIVE Now/Uses per-save dossier sync engine (split out of content-migrations)
require_once __DIR__ . '/inc/resume-sync-engine.php'; // v10.33.0: LIVE /resume per-save sync engine (structured doc → wp:html body, drift-proof)
require_once __DIR__ . '/inc/content-migrations.php'; // spent one-shot seeds behind the master sentinel (sn_run_content_migrations)
require_once __DIR__ . '/inc/split-hero-migration.php'; // v10.36.0: split-hero one-shot (own hook — master sentinel is spent on live)
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
require_once __DIR__ . '/inc/deploy-workers.php'; // Deploy Status: five Cloudflare workers beside theme/plugin
require_once __DIR__ . '/inc/api-rate-monitor.php';
require_once __DIR__ . '/inc/admin-tab-dashboard.php';
require_once __DIR__ . '/inc/desktop-mode-integration.php';
require_once __DIR__ . '/inc/desktop-mode-attention.php';
require_once __DIR__ . '/inc/desktop-mode-dropzone.php';
require_once __DIR__ . '/inc/ai-bootstrap.php';
require_once __DIR__ . '/inc/ai-cache-probe.php'; // v10.50.0: read-only http_response probe — the cache-token split the AI Client's TokenUsage DTO flattens away
require_once __DIR__ . '/inc/ai-tool-invocation-log.php';
require_once __DIR__ . '/inc/ai-alt-text-suggest.php';   // primary: owns the shared SNT_AI_ALT_BASE_RULES — must load first
require_once __DIR__ . '/inc/ai-alt-inline-suggest.php'; // sibling: composes its prompt from that base
require_once __DIR__ . '/inc/emdash-scan.php'; // v10.50.0: prose-vs-structural em-dash classifier; feeds sn-scan 'emdash' + sn-apply 'emdash_replace'.
require_once __DIR__ . '/inc/ai-drift-phrase-suggest.php';
require_once __DIR__ . '/inc/ai-link-suggest.php'; // v7.4.0: unlinked-mention Suggest+Apply (mirrors drift machinery)
require_once __DIR__ . '/inc/ai-pair-suggest.php'; // v8.1.0: semantic-pair Suggest (link_opportunities; Apply rides ai-link-apply)
require_once __DIR__ . '/inc/ai-orphan-suggest.php';
require_once __DIR__ . '/inc/ai-excerpt.php';
require_once __DIR__ . '/inc/ai-tag-suggest.php';
require_once __DIR__ . '/inc/ai-tag-describe.php'; // v13.25.0: draft the one-sentence tag description in the house voice (few-shot from the v13.23.0 seed map); apply is separate + never clobbers
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
require_once __DIR__ . '/inc/integrity-trust-admin.php';   // v10.47.0: Integrity → Trust checks
require_once __DIR__ . '/inc/block-migrations-detect.php';
require_once __DIR__ . '/inc/block-migrations-suggest.php';
require_once __DIR__ . '/inc/block-migrations-apply.php';
require_once __DIR__ . '/inc/block-migrations-admin.php';
require_once __DIR__ . '/inc/abilities-block-migrations.php';
require_once __DIR__ . '/inc/corpus-integrity-scan.php';       // v11.4.0: three deterministic content-integrity checks (duplication / splice artifacts / date coherence) born from the 2026-08-14 hand audit.
require_once __DIR__ . '/inc/settle-cron-cleanup.php';  // v13.87.2: clears the retired snt_cf_settle_manual_purge events left by v13.87.1, which would otherwise surface as orphaned cron.
require_once __DIR__ . '/inc/abilities-purge-verification-log.php';  // v13.86.0: the purge-verification trail IN ROWS. The log had two render surfaces and no agent reader, so "the stale count is climbing — what do those probes share?" needed a screenshot and a guess.
require_once __DIR__ . '/inc/abilities-corpus-integrity.php';  // v11.4.0: the readonly corpus-integrity-scan ability over the module above.
require_once __DIR__ . '/inc/corpus-inspect.php';      // v10.6.0: read-only corpus inspection impls (duplicate scan, listing, bounded content fetch)
require_once __DIR__ . '/inc/abilities-corpus.php';    // v10.6.0: 3 abilities (duplicate-body-scan, list-posts, get-post-content) — sn read door only
require_once __DIR__ . '/inc/abilities-sn-posts.php';      // v10.26.0: MCP consolidation — sn_posts, absorbs list-posts + get-post-content (both stay live)
require_once __DIR__ . '/inc/abilities-sn-site-facts.php'; // v10.26.0: MCP consolidation — sn_site_facts, absorbs 10 of 11 site-facts reads (get-design-system-summary retired, not absorbed)
require_once __DIR__ . '/inc/abilities-sn-status.php';  // v13.1.0: read-door coherence — sectioned batch over the ten narrow status reads (new-alongside-old; needs sn-site-facts' dispatcher above)
require_once __DIR__ . '/inc/abilities-sn-metrics.php'; // v13.1.0: read-door coherence — sectioned batch over the three readership reads (same pattern, same dispatcher)
require_once __DIR__ . '/inc/sn-scan-adapters.php';    // v10.29.0: MCP consolidation session 4 — six per-scan_type adapters behind sn_scan (needs corpus-inspect.php, ml-cousins.php, ml-candidates.php, health-checks.php — all required below; constants/functions resolve at call time, not require time)
require_once __DIR__ . '/inc/sn-scan-anchor-violations.php'; // v10.58.0: scan_type "anchor_violations" — two binary link rules (anchor==sentence, link-in-heading); detector + adapter, own file per the emdash-scanner precedent
require_once __DIR__ . '/inc/sn-scan-search-disagreement.php'; // v13.57.0: scan_type "search_disagreement" — TF-IDF <-> Search Console (weave Phase 3), own file per the anchor-violations precedent
require_once __DIR__ . '/inc/sn-scan-detectors.php';  // v13.6.0: sn_scan detector registry — names the rules behind each scan_type so an empty candidates[] is readable (pattern_adoption's zero means the corpus has no quote/list blocks, not that nothing is registered)
require_once __DIR__ . '/inc/sn-scan-telemetry.php'; // v10.60.0: per-scan_type run telemetry — metrics builder (pure) + sn_scan_completed listener + sn_scan_run table; the ability itself stays zero-writes (observer split)
require_once __DIR__ . '/inc/abilities-sn-scan.php';   // v10.29.0: MCP consolidation session 4 — sn_scan, absorbs block-migrations-scan + pattern-adoption-scan + duplicate-body-scan + near-duplicate-scan + link-candidates, plus new orphan_media
require_once __DIR__ . '/inc/abilities-update-post-surfaces.php'; // v10.7.0: reviewed-text write for excerpt/meta-desc/OG title — rw door
require_once __DIR__ . '/inc/ml-kernel.php';           // v10.15.0: pure ML primitives (tokenizer, tf-idf, cosine, bm25, graph signals) — zero WP calls
require_once __DIR__ . '/inc/ml-pipelines.php';        // v10.15.0: filterable slug=>callable pipeline registry + dispatcher over the kernel
require_once __DIR__ . '/inc/ml-artifacts.php';        // v10.15.0: corpus build (per-post related meta + cron/publish triggers) + the contract reader (needs corpus-inspect.php above)
require_once __DIR__ . '/inc/ml-related-render.php';   // v10.15.0: reader-facing "Related notes" aside (the_content @20, zero JS, render-time stylesheet)
require_once __DIR__ . '/inc/ml-embeddings.php';        // item 8 slice 1: SHADOW semantic vectors — nothing the site serves changes
require_once __DIR__ . '/inc/ml-embeddings-compare.php'; // item 8 slice 1: the TF-IDF vs embeddings instrument (measure before adopting)
require_once __DIR__ . '/inc/ml-cousins.php';          // v10.16.0: near-duplicate cousin pairs (kernel cosine over the corpus walk, needs ml-pipelines + corpus-inspect above) — ML pipeline #2
require_once __DIR__ . '/inc/ml-draft-echoes.php';     // v10.77.0: draft-time echoes — one draft vs the corpus, editor-side read only (needs ml-cousins above for the shared threshold bounds)
require_once __DIR__ . '/inc/ml-link-isolation.php';   // v10.83.0: link isolation — published notes nothing links to (corpus link graph; needs corpus-inspect above) — ML pipeline #8
require_once __DIR__ . '/inc/ml-drift.php';            // v11.2.0: corpus drift — per-term vocabulary movement across years (needs ml-kernel + corpus-inspect above) — ML pipeline #9, writer-facing only
require_once __DIR__ . '/inc/ml-drift-admin.php';      // v11.2.0: the Vocabulary leaf (Content tab) — the drift mirror's ONLY surface
require_once __DIR__ . '/inc/abilities-reader-anomalies.php'; // v13.76.0: read-door ability for the pipeline below.
require_once __DIR__ . '/inc/ml-reader-anomalies.php';
require_once __DIR__ . '/inc/ml-reader-anomalies-health.php'; // v13.76.0: its Site Health surface. // v13.76.0: reader anomalies — machine-reader volume/shape deviations through the analytics signal engine — ML pipeline #11
require_once __DIR__ . '/inc/ml-paths.php';            // v11.3.0: reading paths — the chain one post sits on (reads ml-artifacts' additive path field) — ML pipeline #10
require_once __DIR__ . '/inc/ml-paths-render.php';     // v11.3.0: [sn_reading_path] — plugin owns the renderer, the THEME places it (single.html)
require_once __DIR__ . '/inc/ml-candidates.php';       // v10.17.0: keyword + link candidate generators (needs ml-pipelines, ml-artifacts + corpus-inspect above) — ML pipeline #3
require_once __DIR__ . '/inc/ai-maturity-page.php'; // v10.10.0: [sn_ai_maturity] public explainer (third maturity sibling; leak-proof by test contract)
require_once __DIR__ . '/inc/maturity-roadmap-merge.php'; // roadmap board: the three-way merge that lets code and MCP edits both land
require_once __DIR__ . '/inc/maturity-roadmap-shortcode.php'; // [sn_maturity_roadmap]: the HUB-WIDE roadmap (done/planned/considering across every maturity family), same static-data + filter-seam + leak-proof pattern, own front stylesheet
require_once __DIR__ . '/inc/machine-maturity-page.php'; // v10.11.0: [sn_machine_maturity] — how machines read the site
require_once __DIR__ . '/inc/ops-maturity-page.php';     // v10.11.0: [sn_ops_maturity] — how the site runs itself
require_once __DIR__ . '/inc/a11y-maturity-page.php';    // v10.11.0: [sn_a11y_maturity] — the /accessibility/ claims in the family skeleton
require_once __DIR__ . '/inc/maturity-index-page.php';   // v10.11.0: [sn_maturity_index] — the family hub for /maturity/
require_once __DIR__ . '/inc/maturity-legacy-redirects.php'; // v10.12.0: narrow 301 map for the family's dead top-level URLs (post re-parenting)
require_once __DIR__ . '/inc/ml-maturity-page.php';       // v10.18.0: [sn_ml_maturity] — the ML-kernel explainer (three never badges; leak-proof by test contract)
require_once __DIR__ . '/inc/ml-candidates-ui.php';       // v10.19.0: editor buttons for keyword/link candidates (pure kernel — no AI gate; posts only)
require_once __DIR__ . '/inc/ml-cadence.php';             // v10.22.0: cadence flags (publish + cron rhythm deviations — ML pipeline #5)
require_once __DIR__ . '/inc/colophon-page.php';             // v10.13.0: [sn_colophon] — the colophon moves from theme template to CMS (theme stays frozen)
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
require_once __DIR__ . '/inc/abilities-rate-gate.php';  // v10.34.0: per-user courtesy throttle for expensive abilities (native run-route has no rate limit of its own).
require_once __DIR__ . '/inc/abilities-lifecycle-guard.php'; // v10.38.0: WP 7.1 forward-compat — rw kill switch + telemetry/audit on core's ability lifecycle hooks (inert pre-7.1).
require_once __DIR__ . '/inc/abilities-registration.php';
require_once SNT_PATH . 'inc/abilities-analytics.php';  // v6.1.0: read-only analytics Abilities
require_once SNT_PATH . 'inc/abilities-login-defense.php';  // v12.11.0: read-only IPv6-criterion gauge (wp-admin only until now)
require_once SNT_PATH . 'inc/abilities-remote-analytics.php'; // R3 §3D Increment 1: remote-scoped analytics ability, off the MCP allowlists by design.
require_once SNT_PATH . 'inc/abilities-remote-set.php'; // R3 §3D Increment 2: the remote set widens 1 -> 8, same isolation pattern applied to seven more twins.
require_once __DIR__ . '/inc/migrate-orphan-options.php';  // v5.0.0: one-time orphan-option cleanup
require_once __DIR__ . '/inc/command-palette.php';
require_once __DIR__ . '/inc/pre-publish-gate.php';      // v4.11.0: editor pre-publish advisory gate (client-side, no AI)
require_once SNT_PATH . 'inc/cron-dashboard.php';
require_once SNT_PATH . 'inc/cron-history.php';
require_once SNT_PATH . 'inc/cron-dashboard-admin.php';
require_once SNT_PATH . 'inc/webhooks.php';
require_once SNT_PATH . 'inc/webhooks-admin.php';
require_once SNT_PATH . 'inc/uptime-heartbeat-removal.php'; // v12.19.0: one-shot janitor for the REMOVED push heartbeat — unschedules the live sn_uptime_kuma_heartbeat event and drops its two settings keys. Delete once every install has upgraded past 12.19.0.
require_once SNT_PATH . 'inc/uptime-status.php';
require_once SNT_PATH . 'inc/spend-watch.php'; // v10.75.0: Actions minutes + AI spend as owner-only health signals (never estimated)        // v8.2.0: Better Stack status data layer + ability + field/mount helpers (v8.3.0: + 30d availability)
require_once SNT_PATH . 'inc/uptime-status-widget.php'; // v8.3.0: Uptime section of the S&N Health widget (standalone widget consolidated away) + panel assets
require_once SNT_PATH . 'inc/admin-heartbeat.php';
require_once SNT_PATH . 'inc/insights-generation-budget.php'; // v13.20.6: http_request_args seam giving the Insights generation adaptive thinking + an effort level (Claude 5) so thinking is DEMAND-bounded, plus wire ceiling + timeout headroom; armed only around snt_insights_call_ai()
require_once SNT_PATH . 'inc/insights.php';
require_once SNT_PATH . 'inc/insights-narration.php';
require_once SNT_PATH . 'inc/narration-cron-cleanup.php'; // v9.5.0: one-time clear of the weekly-digest cron orphaned when R2 retired the scheduler
require_once SNT_PATH . 'inc/insights-admin.php';
require_once SNT_PATH . 'inc/health-probe-classify.php'; // shared bot-challenge classifier (used by both health probes below)
require_once SNT_PATH . 'inc/health-check-surfaces.php'; // v11.13.0: which surface owns each check — Health is defects only
require_once SNT_PATH . 'inc/health-checks.php';
require_once SNT_PATH . 'inc/health-drift-verdict-cache.php'; // v12.23.1: drift verdicts survive the plugin-update cache flush that was re-paying them.
require_once SNT_PATH . 'inc/health-scan-cron.php'; // v12.23.0: the scan finally has a schedule — daily 08:00 UTC, derived in that file's docblock.
require_once SNT_PATH . 'inc/health-scan-history.php'; // v12.23.0: a daily verdict with no memory cannot say whether it is getting better.
require_once SNT_PATH . 'inc/sn-validate-checks.php';       // v10.30.0: MCP consolidation session 5 — sn_validate deterministic checks, part 1 (excerpt/meta_description/og_card_title/note_summary/tags); needs SNT_SURFACES_FIELD_CAPS + SNT_AI_*_SYSTEM constants + word-count.php, all loaded above — functions resolve at call time, not require time
require_once SNT_PATH . 'inc/sn-validate-checks-media.php'; // v10.30.0: MCP consolidation session 5 — sn_validate deterministic checks, part 2 (alt_text/links/body/brand_voice); needs health-checks.php's sn_health_drift_time_patterns() + sn_health_contains_note_link(), both loaded above
require_once SNT_PATH . 'inc/abilities-sn-validate.php';    // v10.30.0: MCP consolidation session 5 — signal-noise/sn-validate, the consolidated read-door validation tool
require_once SNT_PATH . 'inc/sn-apply-revision.php';        // v10.40.0: MCP consolidation session 6a — revision-mode write primitive for sn_apply (session 6b). No ability/tool registered yet; nothing calls this file in production.
require_once SNT_PATH . 'inc/sn-apply-gates.php';            // v10.40.0: MCP consolidation session 6b — gates 3 (mode capability) + 4 (idempotency), ability-level, on top of the rw door's existing hardening.
require_once SNT_PATH . 'inc/sn-apply-validation.php';       // v10.40.0: MCP consolidation session 6b — gates 1 (fingerprint) + 2 (server-side validation), per change type.
require_once SNT_PATH . 'inc/sn-apply-create-draft.php';     // v10.40.0: MCP consolidation session 6c (arc finale) — change.type "create_draft": gate 2 assembly, block-delimiter validator, and the write primitive. Split out purely for the 450-line file budget, same convention as sn-apply-validation.php's split from sn-apply-executors.php.
require_once SNT_PATH . 'inc/sn-apply-delete-draft.php';     // v10.58.0 (audit item 6): change.type "delete_draft" — makes create_draft's advertised rollback method real. Trash-only, draft-only, fingerprint-gated; gate 2 + write primitive + dry-run preview.
require_once SNT_PATH . 'inc/sn-apply-link-reshape.php';     // v10.58.0 (audit item 5, owner-confirmed): change.type "link_reshape" — move an <a>'s boundaries within one text node; pair validator, locator, identity-asserting splice, write impl.
require_once SNT_PATH . 'inc/sn-apply-block-edit.php';      // v13.2.0: change.types "block_insert" + "block_replace" — the caller-composed block edit family: span scanner, anchor locator, round-trip/registry markup gate, prose-delta helper, scheduled-post-guarded write impl.
require_once SNT_PATH . 'inc/sn-apply-restore-revision.php'; // v10.42.0: MCP consolidation session 7 — change.type "restore_revision", the acceptance path: structural pre-check, gate 2 assembly against the revision's own fields, rollback-snapshot guarantee, and the first application path for the staged-meta queue.
require_once SNT_PATH . 'inc/sn-apply-sentence-replace.php'; // change.type "sentence_replace": the agent-composed body edit — whole-post content_hash fingerprint (restore_revision's binding), plain-prose splice via the drift locate/splice contract. See the file docblock for why candidate fingerprints can't serve composing callers.
require_once SNT_PATH . 'inc/sn-apply-batch-edits.php';      // change.payload.edits: N prose splices in ONE post in ONE write. Closes the gap where two scan candidates in one Note produced two anchored ledger versions for a single logical edit.
require_once SNT_PATH . 'inc/sn-apply-roadmap-board.php';    // change.type "roadmap_board": board-as-data for the maturity roadmap — option-backed override behind the shortcode's filter seam, effective-board fingerprint, gate-2 banned-token sweep. Publish-only (an option has no revision); the owner's "content goes straight live" rule made mechanical.
require_once SNT_PATH . 'inc/sn-apply-executors.php';        // v10.40.0: MCP consolidation session 6b (+6c: create_draft's target resolution + mode-support entry; +session 7: restore_revision) — target resolution, mode-support matrix, and per-change-type write dispatch, delegating to the absorbed apply impls.
require_once SNT_PATH . 'inc/abilities-sn-apply.php';        // v10.40.0: MCP consolidation session 6b (+6c: create_draft change type + target.new_post) — signal-noise/sn-apply, the consolidated write tool. Registered NEW alongside every ability it absorbs (nothing below was touched).
require_once SNT_PATH . 'inc/health-summary.php'; // v7.0.0: shared scan-summary accessors (finding total + ranked flagged checks) — glance card, attention strip, S&N Health widget
require_once SNT_PATH . 'inc/health-external-links.php'; // D1 (v6.13.0): 7th check — external link-rot (off-host cited sources)
require_once SNT_PATH . 'inc/health-link-opportunities.php'; // v8.1.0: advisory check — semantic pairs that should link (C2 approach C)
require_once SNT_PATH . 'inc/health-edge-workers.php'; // 8th check (v6.49.0): owned-Worker reachability + login-guard denylist freshness
require_once SNT_PATH . 'inc/health-analytics-integrity.php'; // 12th check (v9.65.0): reader of the never-invert sn_analytics_integrity_alert (Phase A P0.4 closed for real)
require_once SNT_PATH . 'inc/health-check-roadmap-drift.php'; // 21st check (v12.6.0): roadmap board merge conflicts between code and sn_apply's override
require_once SNT_PATH . 'inc/health-check-tag-hygiene.php'; // 22nd check (v13.24.0): tag hygiene — undescribed + zero-post tags (advisory, worklist)
require_once SNT_PATH . 'inc/provenance-integrity.php'; // 13th check (v9.80.0): server-side provenance integrity sweep (triangle self-check over the anchored-Note fleet) + readonly status ability
// v10.83.0: the Health tab's IA split into three render modules — family
// grouping data, the collapsed passing disclosure, and report-only payloads
// (contrast_tokens' pair table, previously invisible in admin). Required
// BEFORE the tab that calls them.
require_once SNT_PATH . 'inc/health-check-families.php';
require_once SNT_PATH . 'inc/health-render-findings.php';
require_once SNT_PATH . 'inc/health-render-passing.php';
require_once SNT_PATH . 'inc/health-render-contrast.php';
require_once SNT_PATH . 'inc/health-render-motion.php';
require_once SNT_PATH . 'inc/health-render-reports.php';
require_once SNT_PATH . 'inc/health-checks-admin.php';
require_once SNT_PATH . 'inc/plugin-footprint.php'; // plugin-directory footprint diagnostic (Site Health) + the one-time legacy-deploy-file janitor (admin_init, once per SNT_VERSION)
require_once SNT_PATH . 'inc/http-diagnostics.php'; // admin-request HTTP-call diagnosis (Site Health) — names the outbound wp_remote_* calls behind slow wp-admin page loads
require_once SNT_PATH . 'inc/scheduled-actions-health.php'; // Action Scheduler backlog diagnostic (Site Health) — observes the third-party queue table whose dispatch-gate COUNT taxes every page load
require_once SNT_PATH . 'inc/audit-log.php';
require_once SNT_PATH . 'inc/audit-log-admin.php';
require_once SNT_PATH . 'inc/audit-log-export.php';  // v4.10.0: CSV/JSON export (download + ability impl)
require_once SNT_PATH . 'inc/security-digest.php';   // v7.2.0: weekly security-digest email (LLAR A2) — deterministic, opt-in default OFF
require_once SNT_PATH . 'inc/morning-brief.php';     // R6a: daily Operations brief over health, cron, uptime, deploys + drift
require_once SNT_PATH . 'inc/scheduled-reads.php';   // R6a: daily read-door-only ability runs with a capped outcome history
require_once SNT_PATH . 'inc/privacy-exporters.php'; // v4.10.0: GDPR exporter/eraser + suggested privacy policy text
require_once SNT_PATH . 'inc/speculation-rules.php'; // v4.10.0: opt-in Speculation Rules tuning (prerender/moderate)

// Settings migration: seed legacy values once per environment.
// register_activation_hook fires only on WP-upgrader-driven activations;
// the admin_init handler covers SSH-based git-checkout deploys.
register_activation_hook( __FILE__, 'sn_settings_seed_legacy_values' );
add_action( 'admin_init', 'sn_settings_lazy_migration_check' );
