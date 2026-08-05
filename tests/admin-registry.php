<?php
/**
 * Contract + routing tests for the data-driven admin render registry (Phase 1).
 * Standalone CLI fixture: stubs the WP admin layer, loads the registry data +
 * dispatcher, and asserts every leaf names a real render function, the
 * dispatcher routes (tab, sub) to the right leaf, and the POST allowlist is
 * registry-derived. Behaviour-preserving: this guards the switch→registry move.
 *
 * @since plugin v6.17.x (admin refactor Phase 1)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// Record render invocations instead of producing real admin HTML.
$GLOBALS['__calls'] = array();
function sn_admin_render_section( $slug, $cb ) { $GLOBALS['__calls'][] = "section:$slug"; call_user_func( $cb ); }
function sn_admin_render_sub_tabs( $tab, $sub ) { $GLOBALS['__calls'][] = "subtabs:$tab"; }
function sn_admin_render_section_tabs( $tab, $sub ) { $GLOBALS['__calls'][] = "sectiontabs:$tab/$sub"; }
function do_action( $tag ) { $GLOBALS['__calls'][] = "action:$tag"; }
function has_action( $tag ) { return true; }
function sn_admin_render_identity_and_seo_form() { $GLOBALS['__calls'][] = 'form:identity'; }
function esc_html( $s ) { return (string) $s; }
function esc_html__( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function __( $s ) { return (string) $s; }
function add_action() {} // admin-post-handler.php registers on admin_init at load — no-op it.

// Function-backed section renderers referenced by the registry (stubbed present,
// each records its own call so routing assertions can see which fired).
function sn_admin_render_login_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_login_section'; }
function sn_login_defense_render() { $GLOBALS['__calls'][] = 'fn:sn_login_defense_render'; }
function sn_login_defense_view_render() { $GLOBALS['__calls'][] = 'fn:sn_login_defense_view_render'; }
function snt_audit_log_render_tab() { $GLOBALS['__calls'][] = 'fn:snt_audit_log_render_tab'; }
function sn_admin_render_indexnow_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_indexnow_section'; }
function snt_analytics_render_settings_section() { $GLOBALS['__calls'][] = 'fn:snt_analytics_render_settings_section'; }
function sn_admin_render_music_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_music_section'; }
function sn_admin_render_links_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_links_section'; }
function sn_admin_render_mcp_connect_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_mcp_connect_section'; } // v9.47.0: Tools → Connect an MCP client
function snt_ai_tool_invocations_render() { $GLOBALS['__calls'][] = 'fn:snt_ai_tool_invocations_render'; } // v9.62.2: Tools → Copilot Usage (real fn lives in inc/ai-tool-invocation-log.php)
function sn_admin_render_performance_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_performance_section'; }
function sn_admin_render_release_notes_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_release_notes_section'; }
function sn_admin_render_provenance_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_provenance_section'; } // v9.8.0: Tools → Provenance (real fn lives in inc/provenance-admin.php)
function sn_admin_render_front_end_form() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_front_end_form'; }
function sn_admin_render_tag_cleanup_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_tag_cleanup_section'; }
function sn_admin_render_scheduled_content_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_scheduled_content_section'; }
function sn_admin_render_now_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_now_section'; } // v7.5.0: Content → Now Page
function sn_admin_render_uses_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_uses_section'; } // v7.6.0: Content → Uses Page
function sn_admin_render_resume_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_resume_section'; } // v10.33.0: Content → Resume Page (real fn lives in inc/admin-forms/resume-page.php)
function sn_admin_render_ai_settings_form() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_ai_settings_form'; } // v10.46.0: AI → Models & Budget (real fn lives in inc/admin-forms/ai-settings.php)
// NB: sn_admin_render_pattern_adoption_section() is NOT stubbed — it is a real
// do_action delegator in inc/admin-render-sections.php (required below), same as
// its sibling scanners. Stubbing it here would redeclare-fatal, and would also
// stop this suite from proving the delegator exists at all.

// NB: we stub sn_admin_render_section/sub_tabs/toc above to record routing, so we
// must NOT require inc/admin-tabs.php (it defines them for real → redeclare fatal).
// The dispatcher lives in its own inc/admin-dispatch.php (required from Task 3 on).
require __DIR__ . '/../inc/admin-tabs-data.php';
require __DIR__ . '/../inc/admin-render-sections.php';
require __DIR__ . '/../inc/admin-dispatch.php'; // dispatcher (own file so the helper stubs above don't collide with admin-tabs.php)

echo "Admin registry suite — Phase 1\n\n";

// ── Wrappers exist (Task 1) ──
foreach ( array(
	'sn_admin_render_dashboard', 'sn_admin_render_cloudflare_section', 'sn_admin_render_cron_section',
	'sn_admin_render_webhooks_section', 'sn_admin_render_health_section', 'sn_admin_render_insights_section',
	'sn_admin_render_block_migrations_section', 'sn_admin_render_rss_section',
	'sn_admin_render_redirects_section', // v8.10.0 Redirects arc
) as $fn ) {
	ok( function_exists( $fn ), "wrapper $fn() is defined" );
}

// ── Every leaf names an existing render function (Task 2) ──
$tabs = sn_admin_top_tabs();
foreach ( $tabs as $top ) {
	$subs = is_array( $top['sub_tabs'] ?? null ) ? $top['sub_tabs'] : array();
	if ( empty( $subs ) ) {
		ok( isset( $top['render'] ) && function_exists( $top['render'] ),
			"tab '{$top['tab']}' (no sub-tabs) names an existing render fn" );
		continue;
	}
	foreach ( $subs as $slug => $sub ) {
		ok( isset( $sub['render'] ) && function_exists( $sub['render'] ),
			"leaf '{$top['tab']}/$slug' names an existing render fn: " . ( $sub['render'] ?? '(missing)' ) );
	}
}

// ── Phase-3 IA structure (v10.46.0) ──
// The v6.18.0 seven-tab IA carried 11 leaves that landed after it was designed.
// This block is the SPEC for the regroup: 8 tabs, AI promoted out of the
// render-knobs form, the three content scanners reunited, Measurement holding
// every read-and-tune surface. LABELS changed, KEYS did not — `tab=monitoring`
// is hardcoded in five call sites and stays valid.
$by_tab = array();
foreach ( sn_admin_top_tabs() as $t ) { $by_tab[ $t['tab'] ] = $t; }
ok( array_keys( $by_tab ) === array( 'dashboard', 'site', 'content', 'connections', 'monitoring', 'ai', 'security', 'tools' ),
	'8 top tabs in IA order (ai is new, between monitoring and security)' );

// Site: was the single-leaf Identity & SEO tab; now the whole "how the site
// itself behaves" group, so its label loses the leaf's name.
ok( ( $by_tab['site']['label'] ?? '' ) === 'Site', "site relabelled 'Site' (was 'Identity & SEO' — now more than that one leaf)" );
ok( array_keys( $by_tab['site']['sub_tabs'] ) === array( 'identity-and-seo', 'front-end', 'performance', 'redirects' ),
	'site leaves: identity-and-seo, front-end, performance, redirects' );

// Content: page editors + the three sibling content scanners (finding 5 —
// Tags / Pattern Adoption / Block Migrations were in three different tabs).
ok( array_keys( $by_tab['content']['sub_tabs'] ) === array( 'now', 'uses', 'resume', 'tags', 'pattern-adoption', 'block-migrations' ),
	'content leaves: now, uses, resume, tags, pattern-adoption, block-migrations (the three scanners reunited)' );
// All three scanners own their own .sn-fieldset card, so all three must be
// 'wide' — a capped leaf would wrap that card in a second one. block-migrations
// had exactly that defect until v10.46.0; putting the siblings side by side is
// what made it visible.
foreach ( array( 'tags', 'pattern-adoption', 'block-migrations' ) as $__scanner ) {
	ok( ! empty( $by_tab['content']['sub_tabs'][ $__scanner ]['wide'] ),
		"content scanner '$__scanner' is wide (owns its own card — capped would nest a card in a card)" );
}
ok( ( $by_tab['content']['sub_tabs']['pattern-adoption']['render'] ?? '' ) === 'sn_admin_render_pattern_adoption_section',
	'pattern-adoption names its own leaf render fn (not the Health-tab section fn)' );

ok( array_keys( $by_tab['connections']['sub_tabs'] ) === array( 'cloudflare', 'webhooks', 'indexnow', 'music', 'cron', 'scheduled-content' ),
	'connections leaves: cloudflare, webhooks, indexnow, music, cron, scheduled-content (Music is an external API credential form, Redirects left for Site)' );

// Measurement: every surface that reads the site and tunes what is measured.
// RSS joins it because the RSS leaf is feed-request *analytics*.
ok( ( $by_tab['monitoring']['label'] ?? '' ) === 'Measurement', "monitoring relabelled 'Measurement' — KEY still 'monitoring'" );
ok( array_keys( $by_tab['monitoring']['sub_tabs'] ) === array( 'analytics', 'insights', 'health', 'rss' ),
	'monitoring leaves: analytics, insights, health, rss (machine-readers appends via snt_mr_admin_register when the module loads)' );

// AI: the surface that had no home — budget was field 10 of a render-knobs form.
ok( ( $by_tab['ai']['slug'] ?? '' ) === 'sn-ai', "ai tab slug is 'sn-ai' (allow-lists itself — sn_admin_post_allowed_pages derives from this registry)" );
ok( array_keys( $by_tab['ai']['sub_tabs'] ) === array( 'models-budget', 'copilot-usage', 'mcp-connect' ),
	'ai leaves: models-budget, copilot-usage, mcp-connect' );
ok( ( $by_tab['ai']['sub_tabs']['models-budget']['render'] ?? '' ) === 'sn_admin_render_ai_settings_form',
	'models-budget names the extracted AI settings form' );
ok( ! empty( $by_tab['ai']['sub_tabs']['copilot-usage']['wide'] ),
	'copilot-usage leaf keeps its wide flag across the move (bare .sn-section — no card-in-a-card around the fn’s own .sn-card)' );

ok( array_keys( $by_tab['tools']['sub_tabs'] ) === array( 'provenance', 'links' ),
	'tools leaves: provenance, links (the junk drawer empties — block-migrations to Content, mcp/copilot to AI)' );
ok( array_keys( $by_tab['security']['sub_tabs'] ) === array( 'login', 'login-defense', 'audit-log' ),
	'security leaves: login, login-defense, audit-log (unchanged)' );

// Moved leaves must carry their layout flags across verbatim — a dropped 'wide'
// silently re-caps a two-column surface at 820px.
foreach ( array(
	'site/front-end', 'site/performance', 'site/redirects',
	'connections/music', 'monitoring/rss', 'content/tags',
) as $pair ) {
	list( $t, $s ) = explode( '/', $pair );
	ok( ! empty( $by_tab[ $t ]['sub_tabs'][ $s ]['wide'] ), "moved leaf $pair keeps its 'wide' flag" );
}

// ── Dispatcher routing (Task 3) ──
// identity-and-seo: sub-tab nav + in-form section tabs + the form, NO section wrapper.
$GLOBALS['__calls'] = array();
sn_admin_render_active_tab( 'site', 'identity-and-seo' );
ok( $GLOBALS['__calls'] === array( 'subtabs:site', 'sectiontabs:site/identity-and-seo', 'form:identity' ),
	'route site/identity-and-seo → nav + section tabs + form (no section wrapper)' );

// connections/cloudflare: sub-tab nav + section-wrapped do_action.
$GLOBALS['__calls'] = array();
sn_admin_render_active_tab( 'connections', 'cloudflare' );
ok( $GLOBALS['__calls'] === array( 'subtabs:connections', 'section:cloudflare', 'action:sn_admin_cloudflare_tab' ),
	'route connections/cloudflare → nav + section(cloudflare) + hook' );

// connections/music: function-backed leaf — section-wrapped, then the renderer
// fires. (v10.46.0: moved from content; the routing shape is unchanged.)
$GLOBALS['__calls'] = array();
sn_admin_render_active_tab( 'connections', 'music' );
ok( $GLOBALS['__calls'] === array( 'subtabs:connections', 'section:music', 'fn:sn_admin_render_music_section' ),
	'route connections/music → nav + section(music) + music renderer' );

// ai/copilot-usage: function-backed leaf (v9.62.2, wide) — section-wrapped, then
// the renderer fires. (v10.46.0: moved from tools.)
$GLOBALS['__calls'] = array();
sn_admin_render_active_tab( 'ai', 'copilot-usage' );
ok( $GLOBALS['__calls'] === array( 'subtabs:ai', 'section:copilot-usage', 'fn:snt_ai_tool_invocations_render' ),
	'route ai/copilot-usage → nav + section(copilot-usage) + usage renderer' );

// content/pattern-adoption: the extracted leaf routes like any other
// function-backed leaf — proving the promotion is a real leaf, not a section
// still borrowing the Health tab's render pass.
// It delegates via do_action like its sibling scanners, rather than being called
// inline the way the Health tab used to call it.
$GLOBALS['__calls'] = array();
sn_admin_render_active_tab( 'content', 'pattern-adoption' );
ok( $GLOBALS['__calls'] === array( 'subtabs:content', 'section:pattern-adoption', 'action:sn_admin_pattern_adoption_tab' ),
	'route content/pattern-adoption → nav + section + its own sn_admin_pattern_adoption_tab delegator' );

// dashboard: no sub-tab nav, tab-level render only.
$GLOBALS['__calls'] = array();
sn_admin_render_active_tab( 'dashboard', '' );
ok( $GLOBALS['__calls'] === array( 'action:sn_admin_dashboard_extras' ),
	'route dashboard → tab render only (no sub-tab nav)' );

// unknown sub falls back to the first leaf — monitoring's first leaf is now analytics.
$GLOBALS['__calls'] = array();
sn_admin_render_active_tab( 'monitoring', 'nope' );
ok( $GLOBALS['__calls'] === array( 'subtabs:monitoring', 'section:analytics', 'fn:snt_analytics_render_settings_section' ),
	'route monitoring/<unknown> → first leaf (analytics)' );

// ── POST page allowlist is registry-derived (Task 5) ──
// sn_admin_pages() (legacy slugs) stubbed minimal; admin-post-handler.php
// registers on admin_init at load — add_action() is already no-op'd above.
if ( ! function_exists( 'sn_admin_pages' ) ) {
	function sn_admin_pages() { return array( array( 'slug' => 'sn-theme-options' ) ); }
}
require __DIR__ . '/../inc/admin-post-handler.php';
$allow = sn_admin_post_allowed_pages();
ok( in_array( 'sn-theme-options', $allow, true ), 'allowlist includes the canonical slug' );
ok( in_array( 'sn-monitoring', $allow, true ), 'allowlist includes a registry top-tab slug (sn-monitoring)' );
ok( in_array( 'sn-content', $allow, true ), 'allowlist includes the new sn-content slug' );
ok( in_array( 'sn-connections', $allow, true ), 'allowlist includes the new sn-connections slug' );
ok( ! in_array( 'sn-not-a-page', $allow, true ), 'allowlist rejects an unknown slug' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
