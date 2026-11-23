<?php
/**
 * Behavioral test for the Phase-2 IA redirect resolver.
 *
 * Pure-function assertions over sn_admin_canonical_destination() (GET 301) and
 * sn_admin_post_redirect_target() (POST PRG): every leaf that changed parent tab
 * resolves to its new {tab, sub}; a current canonical (tab, sub) resolves to null /
 * passes through (no redirect loop); legacy flat slugs + the retired sn-automation
 * page slug route to the new homes. Guards the "no bookmark mis-routes" contract of
 * the v6.18.0 IA reshuffle. CLI-only.
 *
 * @since plugin v6.18.0 (admin refactor Phase 2)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }

require __DIR__ . '/../inc/admin-tabs-data.php';
require __DIR__ . '/../inc/admin-legacy-redirect.php';

$pass = 0; $fail = 0;
function eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "PASS: $m\n"; }
	else { $fail++; echo "FAIL: $m\n  exp=" . var_export( $e, true ) . "\n  got=" . var_export( $a, true ) . "\n"; }
}

echo "Phase-2 IA redirect resolver suite\n\n";

// ── Moved leaves → new {tab, sub} (post-v3.8 canonical bookmarks) ──
eq( array( 'tab' => 'connections', 'sub' => 'cloudflare', 'anchor' => null ), sn_admin_canonical_destination( 'site', 'cloudflare' ), 'site/cloudflare → connections/cloudflare' );
eq( array( 'tab' => 'content', 'sub' => null, 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'reading-time' ), 'v10.24.0: tools/reading-time → Content tab DEFAULT (the cleanup tool retired in v10.0.0 — no ghost sub)' );
eq( array( 'tab' => 'connections', 'sub' => 'webhooks', 'anchor' => null ), sn_admin_canonical_destination( 'automation', 'webhooks' ), 'automation/webhooks → connections/webhooks' );
eq( array( 'tab' => 'connections', 'sub' => 'indexnow', 'anchor' => null ), sn_admin_canonical_destination( 'automation', 'indexnow' ), 'automation/indexnow → connections/indexnow' );

// ── v10.46.0 Phase-3 IA: the leaves that moved in the regroup ──
eq( array( 'tab' => 'settings', 'sub' => 'front-end', 'anchor' => null ), sn_admin_canonical_destination( 'content', 'front-end' ), 'content/front-end → settings/front-end (2026-11: the leaf moved again, whole; named directly, not via site)' );
eq( array( 'tab' => 'site', 'sub' => 'performance', 'anchor' => null ), sn_admin_canonical_destination( 'content', 'performance' ), 'content/performance → site/performance' );
eq( array( 'tab' => 'site', 'sub' => 'redirects', 'anchor' => null ), sn_admin_canonical_destination( 'connections', 'redirects' ), 'connections/redirects → site/redirects' );
eq( array( 'tab' => 'connections', 'sub' => 'music', 'anchor' => null ), sn_admin_canonical_destination( 'content', 'music' ), 'content/music → connections/music' );
eq( array( 'tab' => 'monitoring', 'sub' => 'rss', 'anchor' => null ), sn_admin_canonical_destination( 'content', 'rss' ), 'content/rss → monitoring/rss (RSS is feed-request analytics)' );
eq( array( 'tab' => 'content', 'sub' => 'block-migrations', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'block-migrations' ), 'tools/block-migrations → content/block-migrations' );
eq( array( 'tab' => 'ai', 'sub' => 'mcp-connect', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'mcp-connect' ), 'tools/mcp-connect → ai/mcp-connect' );
eq( array( 'tab' => 'ai', 'sub' => 'agent-tools', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'copilot-usage' ), 'tools/copilot-usage → ai/agent-tools (15.6.0 folded Copilot Usage into Agent tools; ai/copilot-usage is not a leaf)' );
eq( array( 'tab' => 'ai', 'sub' => 'agent-tools', 'anchor' => null ), sn_admin_canonical_destination( 'ai', 'copilot-usage' ), 'ai/copilot-usage → ai/agent-tools' );
// Every move and every legacy slug lands on a tab, and a leaf, that exists: a
// target missing from the tab data is a dead bookmark.
$sn_leaves = array();
foreach ( sn_admin_top_tabs() as $t ) { $sn_leaves[ $t['tab'] ] = array_keys( $t['sub_tabs'] ?? array() ); }
foreach ( array( 'move' => sn_admin_subtab_moves(), 'legacy slug' => sn_admin_legacy_redirect_map() ) as $kind => $map ) {
	foreach ( $map as $from => $to ) {
		$sub = $to['sub'] ?? null;
		eq( true, isset( $sn_leaves[ $to['tab'] ] ) && ( null === $sub || in_array( $sub, $sn_leaves[ $to['tab'] ], true ) ), "$kind $from lands on a registered tab and leaf ({$to['tab']}/" . ( $sub ?? '' ) . ')' );
	}
}

// ── Two-hop legacy: entries that pointed at a destination which has since moved
// again. A stale intermediate lands the bookmark on a tab that no longer owns
// the leaf, so these must be repointed at the FINAL home, not the old one. ──
eq( array( 'tab' => 'settings', 'sub' => 'front-end', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'front-end' ), 'tools/front-end → settings/front-end (was content, then site: repointed each time, never two-hopped)' );
eq( array( 'tab' => 'site', 'sub' => 'performance', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'performance' ), 'tools/performance → site/performance (was content)' );
eq( array( 'tab' => 'connections', 'sub' => 'music', 'anchor' => null ), sn_admin_canonical_destination( 'monitoring', 'music' ), 'monitoring/music → connections/music (was content)' );

// ── Already canonical → null (no redirect, no loop) ──
eq( null, sn_admin_canonical_destination( 'connections', 'music' ), 'connections/music now canonical' );
eq( null, sn_admin_canonical_destination( 'monitoring', 'rss' ), 'monitoring/rss now canonical (the old move entry must be GONE, or RSS bounces out of its new home)' );
eq( null, sn_admin_canonical_destination( 'connections', 'cloudflare' ), 'connections/cloudflare canonical' );
eq( null, sn_admin_canonical_destination( 'monitoring', 'health' ), 'monitoring/health canonical' );
eq( null, sn_admin_canonical_destination( 'settings', 'general' ), 'settings/general canonical (2026-11: Site > Identity & SEO moved there whole)' );
eq( null, sn_admin_canonical_destination( 'site', 'redirects' ), 'site/redirects canonical' );
eq( null, sn_admin_canonical_destination( 'content', 'pattern-adoption' ), 'content/pattern-adoption canonical (new leaf, never had a prior home)' );
eq( null, sn_admin_canonical_destination( 'ai', 'models-budget' ), 'ai/models-budget canonical' );
eq( null, sn_admin_canonical_destination( 'tools', 'links' ), 'tools/links canonical' );
// Settings > Notifications and reports took three switches, not a leaf: the
// pane is canonical, and the two leaves the switches came from must NOT
// redirect (a move entry would send every Cron and Login defense bookmark,
// and every save from them, to Settings).
eq( null, sn_admin_canonical_destination( 'settings', 'notifications' ), 'settings/notifications canonical (new pane)' );
eq( null, sn_admin_canonical_destination( 'connections', 'cron' ), 'connections/cron still canonical: switches left it, the leaf did not move' );
eq( null, sn_admin_canonical_destination( 'security', 'login-defense' ), 'security/login-defense still canonical' );
eq( array( 'tab' => 'settings', 'sub' => 'notifications', 'anchor' => null ), sn_admin_post_redirect_target( 'settings', 'notifications' ), 'POST PRG: a save from the pane returns to the pane' );
// Settings > Security took a WHOLE leaf (Security > Login URL) and one field
// (audit log retention). The leaf redirects; the Audit log leaf does not.
$sec = array( 'tab' => 'settings', 'sub' => 'security', 'anchor' => null );
eq( $sec, sn_admin_canonical_destination( 'security', 'login' ), 'security/login -> settings/security (the leaf moved whole)' );
eq( $sec, sn_admin_post_redirect_target( 'security', 'login' ), 'POST PRG: an in-flight save from the old Login URL leaf lands on the pane' );
eq( $sec, sn_admin_canonical_destination( 'login', '' ), 'legacy flat slug login -> settings/security in ONE hop (not via security/login)' );
eq( null, sn_admin_canonical_destination( 'settings', 'security' ), 'settings/security canonical' );
eq( $sec, sn_admin_post_redirect_target( 'settings', 'security' ), 'POST PRG: a save from Settings > Security returns to it' );
eq( null, sn_admin_canonical_destination( 'security', 'audit-log' ), 'security/audit-log still canonical: the retention field left it, the leaf did not move' );

// Settings > AI took FIELDS from three leaves and no leaf whole, so none of
// the three redirects, and a save from the pane returns to the pane. The sub
// slug `ai` repeats the AI tab's own slug; the resolver keys on the pair.
$ai_pane = array( 'tab' => 'settings', 'sub' => 'ai', 'anchor' => null );
eq( null, sn_admin_canonical_destination( 'settings', 'ai' ), 'settings/ai canonical' );
eq( $ai_pane, sn_admin_post_redirect_target( 'settings', 'ai' ), 'POST PRG: a save from Settings > AI returns to it, not to the AI tab' );
eq( null, sn_admin_canonical_destination( 'ai', 'models-budget' ), 'ai/models-budget still canonical: its settings left it, the leaf did not move' );
eq( null, sn_admin_canonical_destination( 'ai', 'mcp-connect' ), 'ai/mcp-connect still canonical: its three controls left it, the leaf did not move' );
eq( null, sn_admin_canonical_destination( 'connections', 'cron' ), 'connections/cron still canonical: the daily run switch left it, the leaf did not move' );
eq( array( 'tab' => 'connections', 'sub' => 'cron', 'anchor' => null ), sn_admin_post_redirect_target( 'connections', 'cron' ), 'POST PRG: Run now, posted from Cron, returns to Cron' );
eq( 'ai', sn_admin_post_redirect_target( 'ai', '' )['tab'], 'POST PRG: a bare tab=ai is still the AI tab, never the pane' );
// Settings > Front end took a WHOLE leaf (Site > Front-End, nothing but the
// one save_theme form) and one switch (speculative loading, from Site >
// Performance). The leaf redirects; the Performance leaf does not.
$fe = array( 'tab' => 'settings', 'sub' => 'front-end', 'anchor' => null );
eq( $fe, sn_admin_canonical_destination( 'site', 'front-end' ), 'site/front-end -> settings/front-end (the leaf moved whole)' );
eq( $fe, sn_admin_post_redirect_target( 'site', 'front-end' ), 'POST PRG: an in-flight save from the old Front-End leaf lands on the pane' );
eq( null, sn_admin_canonical_destination( 'settings', 'front-end' ), 'settings/front-end canonical' );
eq( $fe, sn_admin_post_redirect_target( 'settings', 'front-end' ), 'POST PRG: a save from Settings > Front end returns to it' );
eq( null, sn_admin_canonical_destination( 'site', 'performance' ), 'site/performance still canonical: the switch left it, the leaf did not move' );
// Settings > General took a WHOLE leaf (Site > Identity & SEO, the one
// save_identity form). The leaf redirects; Site's other three do not. A bare
// tab=site carries no sub, so nothing can redirect it: it opens on Performance.
$gen = array( 'tab' => 'settings', 'sub' => 'general', 'anchor' => null );
eq( $gen, sn_admin_canonical_destination( 'site', 'identity-and-seo' ), 'site/identity-and-seo -> settings/general (the leaf moved whole)' );
eq( $gen, sn_admin_post_redirect_target( 'site', 'identity-and-seo' ), 'POST PRG: an in-flight save from the old Identity & SEO leaf lands on the pane' );
eq( $gen, sn_admin_post_redirect_target( 'settings', 'general' ), 'POST PRG: a save from Settings > General returns to it' );
eq( array( 'tab' => 'settings', 'sub' => null, 'anchor' => null ), sn_admin_post_redirect_target( 'settings', '' ), 'POST PRG: a save from the bare Settings tab (General, its first pane) returns to the bare tab' );
eq( null, sn_admin_canonical_destination( 'site', '' ), 'a bare tab=site is not redirected: no sub names the leaf that left' );
eq( array( 'tab' => 'site', 'sub' => null, 'anchor' => null ), sn_admin_post_redirect_target( 'site', '' ), 'POST PRG: an identity save in flight from a bare tab=site page is applied and answered on the Site tab (its first leaf, Performance), the one in-flight case with no sub to redirect' );
// Settings > Accounts and connections took a WHOLE leaf (Connections >
// Credentials, the account list with its state) and the settings of three
// leaves that kept their readings. The leaf redirects; the three do not.
$acc = array( 'tab' => 'settings', 'sub' => 'accounts', 'anchor' => null );
eq( $acc, sn_admin_canonical_destination( 'connections', 'credentials' ), 'connections/credentials -> settings/accounts (the leaf moved whole)' );
eq( $acc, sn_admin_post_redirect_target( 'connections', 'credentials' ), 'POST PRG: an in-flight save from the old Credentials leaf lands on the pane' );
eq( null, sn_admin_canonical_destination( 'settings', 'accounts' ), 'settings/accounts canonical' );
eq( $acc, sn_admin_post_redirect_target( 'settings', 'accounts' ), 'POST PRG: a save from Settings > Accounts and connections returns to it' );
foreach ( array( 'indexnow', 'zenodo', 'music' ) as $kept ) {
	eq( null, sn_admin_canonical_destination( 'connections', $kept ), "connections/$kept still canonical: its settings left it, the leaf did not move" );
}
eq( array( 'tab' => 'site', 'sub' => 'performance', 'anchor' => null ), sn_admin_post_redirect_target( 'site', 'performance' ), 'POST PRG: an in-flight switch save from Site > Performance returns to that leaf with its notice' );
eq( array( 'tab' => 'connections', 'sub' => 'cron', 'anchor' => null ), sn_admin_post_redirect_target( 'connections', 'cron' ), 'POST PRG: Acknowledge and the reads form still return to Cron' );

// ── Legacy flat slugs + retired tab ──
$d = sn_admin_canonical_destination( 'cloudflare', '' );
eq( 'connections', $d['tab'] ?? null, 'legacy ?tab=cloudflare → connections' );
$d = sn_admin_canonical_destination( 'reading-time', '' );
eq( 'content', $d['tab'] ?? null, 'legacy ?tab=reading-time → content' );
$d = sn_admin_canonical_destination( 'automation', '' );
eq( 'connections', $d['tab'] ?? null, 'bare ?tab=automation → connections' );
$d = sn_admin_canonical_destination( 'rss', '' );
eq( 'monitoring', $d['tab'] ?? null, 'legacy flat ?tab=rss → monitoring (the pre-v3.8 map entry moves with the leaf)' );
$d = sn_admin_canonical_destination( 'health', '' );
eq( 'monitoring', $d['tab'] ?? null, 'legacy flat ?tab=health → monitoring (unchanged)' );

// ── Retired page slug resolves to a redirectable tab name ──
eq( 'automation', sn_admin_page_tab_for_slug( 'sn-automation' ), 'sn-automation slug → automation (→ connections via map)' );
eq( 'site', sn_admin_page_tab_for_slug( 'sn-site' ), 'sn-site slug → site (unchanged)' );

// ── POST PRG target (the glue mapped into $redirect_args by sn_handle_admin_post) ──
eq( array( 'tab' => 'connections', 'sub' => 'music', 'anchor' => null ), sn_admin_post_redirect_target( 'connections', 'music' ), 'POST connections/music → passthrough (sub preserved)' );
eq( array( 'tab' => 'connections', 'sub' => 'music', 'anchor' => null ), sn_admin_post_redirect_target( 'monitoring', 'music' ), 'POST stale monitoring/music → rewritten to connections/music (defense-in-depth)' );
// A save posted from a leaf that moved in THIS release must land on the new
// home, not 404 back into the old tab. The forms self-post to the current URL,
// so tab/sub arrive via $_REQUEST and flow through this same resolver.
eq( array( 'tab' => 'settings', 'sub' => 'front-end', 'anchor' => null ), sn_admin_post_redirect_target( 'content', 'front-end' ), 'POST stale content/front-end → settings/front-end' );
eq( array( 'tab' => 'monitoring', 'sub' => 'rss', 'anchor' => null ), sn_admin_post_redirect_target( 'content', 'rss' ), 'POST stale content/rss → monitoring/rss' );
eq( array( 'tab' => 'ai', 'sub' => 'models-budget', 'anchor' => null ), sn_admin_post_redirect_target( 'ai', 'models-budget' ), 'POST ai/models-budget → passthrough (Run comparison, the one form the leaf keeps, answers in place)' );
eq( array( 'tab' => 'connections', 'sub' => 'cloudflare', 'anchor' => null ), sn_admin_post_redirect_target( 'site', 'cloudflare' ), 'POST stale site/cloudflare → connections/cloudflare' );
eq( array( 'tab' => 'connections', 'sub' => 'webhooks', 'anchor' => null ), sn_admin_post_redirect_target( 'connections', 'webhooks' ), 'POST connections/webhooks → passthrough' );
eq( array( 'tab' => 'settings', 'sub' => 'general', 'anchor' => 'identity' ), sn_admin_post_redirect_target( 'identity', '' ), 'POST legacy identity → settings/general#identity (anchor preserved; named directly, not via site)' );
foreach ( array( 'social', 'open-graph', 'seo-copy' ) as $flat ) {
	eq( array( 'tab' => 'settings', 'sub' => 'general', 'anchor' => $flat ), sn_admin_canonical_destination( $flat, '' ), "GET legacy $flat → settings/general#$flat" );
}
eq( array( 'tab' => 'dashboard', 'sub' => null, 'anchor' => null ), sn_admin_post_redirect_target( 'bogus-tab', '' ), 'POST unknown tab → dashboard fallback' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
