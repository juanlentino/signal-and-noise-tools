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
eq( array( 'tab' => 'content', 'sub' => 'music', 'anchor' => null ), sn_admin_canonical_destination( 'monitoring', 'music' ), 'monitoring/music → content/music' );
eq( array( 'tab' => 'content', 'sub' => 'rss', 'anchor' => null ), sn_admin_canonical_destination( 'monitoring', 'rss' ), 'monitoring/rss → content/rss' );
eq( array( 'tab' => 'content', 'sub' => 'performance', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'performance' ), 'tools/performance → content/performance' );
eq( array( 'tab' => 'content', 'sub' => 'front-end', 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'front-end' ), 'tools/front-end → content/front-end' );
eq( array( 'tab' => 'content', 'sub' => null, 'anchor' => null ), sn_admin_canonical_destination( 'tools', 'reading-time' ), 'v10.24.0: tools/reading-time → Content tab DEFAULT (the cleanup tool retired in v10.0.0 — no ghost sub)' );
eq( array( 'tab' => 'connections', 'sub' => 'webhooks', 'anchor' => null ), sn_admin_canonical_destination( 'automation', 'webhooks' ), 'automation/webhooks → connections/webhooks' );
eq( array( 'tab' => 'connections', 'sub' => 'indexnow', 'anchor' => null ), sn_admin_canonical_destination( 'automation', 'indexnow' ), 'automation/indexnow → connections/indexnow' );

// ── Already canonical → null (no redirect, no loop) ──
eq( null, sn_admin_canonical_destination( 'content', 'music' ), 'content/music already canonical' );
eq( null, sn_admin_canonical_destination( 'connections', 'cloudflare' ), 'connections/cloudflare canonical' );
eq( null, sn_admin_canonical_destination( 'monitoring', 'health' ), 'monitoring/health canonical' );
eq( null, sn_admin_canonical_destination( 'site', 'identity-and-seo' ), 'site/identity-and-seo canonical' );
eq( null, sn_admin_canonical_destination( 'tools', 'links' ), 'tools/links canonical' );

// ── Legacy flat slugs + retired tab ──
$d = sn_admin_canonical_destination( 'cloudflare', '' );
eq( 'connections', $d['tab'] ?? null, 'legacy ?tab=cloudflare → connections' );
$d = sn_admin_canonical_destination( 'reading-time', '' );
eq( 'content', $d['tab'] ?? null, 'legacy ?tab=reading-time → content' );
$d = sn_admin_canonical_destination( 'automation', '' );
eq( 'connections', $d['tab'] ?? null, 'bare ?tab=automation → connections' );

// ── Retired page slug resolves to a redirectable tab name ──
eq( 'automation', sn_admin_page_tab_for_slug( 'sn-automation' ), 'sn-automation slug → automation (→ connections via map)' );
eq( 'site', sn_admin_page_tab_for_slug( 'sn-site' ), 'sn-site slug → site (unchanged)' );

// ── POST PRG target (the glue mapped into $redirect_args by sn_handle_admin_post) ──
eq( array( 'tab' => 'content', 'sub' => 'music', 'anchor' => null ), sn_admin_post_redirect_target( 'content', 'music' ), 'POST content/music → passthrough (sub preserved)' );
eq( array( 'tab' => 'content', 'sub' => 'music', 'anchor' => null ), sn_admin_post_redirect_target( 'monitoring', 'music' ), 'POST stale monitoring/music → rewritten to content/music (defense-in-depth)' );
eq( array( 'tab' => 'connections', 'sub' => 'cloudflare', 'anchor' => null ), sn_admin_post_redirect_target( 'site', 'cloudflare' ), 'POST stale site/cloudflare → connections/cloudflare' );
eq( array( 'tab' => 'connections', 'sub' => 'webhooks', 'anchor' => null ), sn_admin_post_redirect_target( 'connections', 'webhooks' ), 'POST connections/webhooks → passthrough' );
eq( array( 'tab' => 'site', 'sub' => 'identity-and-seo', 'anchor' => 'identity' ), sn_admin_post_redirect_target( 'identity', '' ), 'POST legacy identity → site/identity-and-seo#identity (anchor preserved)' );
eq( array( 'tab' => 'dashboard', 'sub' => null, 'anchor' => null ), sn_admin_post_redirect_target( 'bogus-tab', '' ), 'POST unknown tab → dashboard fallback' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
