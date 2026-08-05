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
eq( array( 'tab' => 'site', 'sub' => 'front-end', 'anchor' => null ), sn_admin_canonical_destination( 'content', 'front-end' ), 'content/front-end → site/front-end' );
eq( array( 'tab' => 'site', 'sub' => 'performance', 'anchor' => null ), sn_admin_canonical_destination( 'content', 'performance' ), 'content/performance → site/performance' );
eq( array( 'tab' => 'site', 'sub' => 'redirects', 'anchor' => null ), sn_admin_canonical_destination( 'connections', 'redirects' ), 'connections/redirects → site/redirects' );
eq( array( 'tab' => 'connections', 'sub' => 'music', 'anchor' => null ), sn_admin_canonical_destination( 'content', 'music' ), 'content/music → connections/music' );
eq( array( 'tab' => 'monitoring', 'sub' => 'rss', 'anchor' => null ), sn_admin_canonical_destination( 'content', 'rss' ), 'content/rss → monitoring/rss (RSS is feed-request analytics)' );
eq( array( 'tab' => 'content', 'sub' => 'block-migrations', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'block-migrations' ), 'tools/block-migrations → content/block-migrations' );
eq( array( 'tab' => 'ai', 'sub' => 'mcp-connect', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'mcp-connect' ), 'tools/mcp-connect → ai/mcp-connect' );
eq( array( 'tab' => 'ai', 'sub' => 'copilot-usage', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'copilot-usage' ), 'tools/copilot-usage → ai/copilot-usage' );

// ── Two-hop legacy: entries that pointed at a destination which has since moved
// again. A stale intermediate lands the bookmark on a tab that no longer owns
// the leaf, so these must be repointed at the FINAL home, not the old one. ──
eq( array( 'tab' => 'site', 'sub' => 'front-end', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'front-end' ), 'tools/front-end → site/front-end (was content — repointed, not two-hopped)' );
eq( array( 'tab' => 'site', 'sub' => 'performance', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'performance' ), 'tools/performance → site/performance (was content)' );
eq( array( 'tab' => 'connections', 'sub' => 'music', 'anchor' => null ), sn_admin_canonical_destination( 'monitoring', 'music' ), 'monitoring/music → connections/music (was content)' );

// ── Already canonical → null (no redirect, no loop) ──
eq( null, sn_admin_canonical_destination( 'connections', 'music' ), 'connections/music now canonical' );
eq( null, sn_admin_canonical_destination( 'monitoring', 'rss' ), 'monitoring/rss now canonical (the old move entry must be GONE, or RSS bounces out of its new home)' );
eq( null, sn_admin_canonical_destination( 'connections', 'cloudflare' ), 'connections/cloudflare canonical' );
eq( null, sn_admin_canonical_destination( 'monitoring', 'health' ), 'monitoring/health canonical' );
eq( null, sn_admin_canonical_destination( 'site', 'identity-and-seo' ), 'site/identity-and-seo canonical' );
eq( null, sn_admin_canonical_destination( 'site', 'front-end' ), 'site/front-end canonical' );
eq( null, sn_admin_canonical_destination( 'content', 'pattern-adoption' ), 'content/pattern-adoption canonical (new leaf, never had a prior home)' );
eq( null, sn_admin_canonical_destination( 'ai', 'models-budget' ), 'ai/models-budget canonical' );
eq( null, sn_admin_canonical_destination( 'tools', 'links' ), 'tools/links canonical' );

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
eq( array( 'tab' => 'site', 'sub' => 'front-end', 'anchor' => null ), sn_admin_post_redirect_target( 'content', 'front-end' ), 'POST stale content/front-end → site/front-end' );
eq( array( 'tab' => 'monitoring', 'sub' => 'rss', 'anchor' => null ), sn_admin_post_redirect_target( 'content', 'rss' ), 'POST stale content/rss → monitoring/rss' );
eq( array( 'tab' => 'ai', 'sub' => 'models-budget', 'anchor' => null ), sn_admin_post_redirect_target( 'ai', 'models-budget' ), 'POST ai/models-budget → passthrough (the extracted AI form saves in place)' );
eq( array( 'tab' => 'connections', 'sub' => 'cloudflare', 'anchor' => null ), sn_admin_post_redirect_target( 'site', 'cloudflare' ), 'POST stale site/cloudflare → connections/cloudflare' );
eq( array( 'tab' => 'connections', 'sub' => 'webhooks', 'anchor' => null ), sn_admin_post_redirect_target( 'connections', 'webhooks' ), 'POST connections/webhooks → passthrough' );
eq( array( 'tab' => 'site', 'sub' => 'identity-and-seo', 'anchor' => 'identity' ), sn_admin_post_redirect_target( 'identity', '' ), 'POST legacy identity → site/identity-and-seo#identity (anchor preserved)' );
eq( array( 'tab' => 'dashboard', 'sub' => null, 'anchor' => null ), sn_admin_post_redirect_target( 'bogus-tab', '' ), 'POST unknown tab → dashboard fallback' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
