<?php
/**
 * Standalone tests for the MCP capabilities module (allowlist SoT, protocol
 * negotiation, server info). Sub-project B of the machine-readability program.
 *
 * @since plugin v9.22.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '9.22.0' ); }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'name' === $k ? 'Signal & Noise' : ''; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP capabilities — plugin v9.22.0\n\n";

$list = sn_mcp_allowlist();
ok( is_array( $list ) && count( $list ) === 23, 'read-door allowlist has exactly 23 slugs (widened 15 -> 23 in v9.50.0)' );
ok( in_array( 'signal-noise/get-health-scan', $list, true ), 'plugin read is allowlisted' );
ok( in_array( 'signal-and-noise/get-design-tokens', $list, true ), 'theme read is allowlisted (cross-namespace)' );
ok( ! in_array( 'signal-noise/purge-all-caches', $list, true ), 'a write ability is NOT allowlisted on the read door' );
// v9.50.0: get-llms-txt is RE-ADDED to the read door by D1 (it was previously
// cut as redundant). This intentionally reverses the old v9.22.0 assertion.
ok( in_array( 'signal-and-noise/get-llms-txt', $list, true ), 'get-llms-txt is (re-)allowlisted on the read door (D1)' );

// --- D1: the exact 8 new read-door slugs, pinned individually ---
$new_read_slugs = array(
	'signal-noise/get-analytics-events',
	'signal-noise/block-migrations-scan',
	'signal-noise/pattern-adoption-scan',
	'signal-noise/list-template-overrides',
	'signal-and-noise/get-seo-route-meta',
	'signal-and-noise/get-llms-txt',
	'signal-and-noise/get-page-notes-pillars',
	'signal-and-noise/get-reading-time-for-slug',
);
foreach ( $new_read_slugs as $slug ) {
	ok( in_array( $slug, $list, true ), "D1 new read-door slug present: $slug" );
}

ok( sn_mcp_is_allowed( 'signal-noise/get-narration' ) === true, 'is_allowed true for an allowlisted slug' );
ok( sn_mcp_is_allowed( 'signal-noise/run-narration' ) === false, 'is_allowed false for a non-allowlisted slug' );

$info = sn_mcp_server_info();
ok( ( $info['name'] ?? '' ) === 'Signal & Noise', 'server_info carries the site name' );
ok( ( $info['version'] ?? '' ) === '9.22.0', 'server_info carries SNT_VERSION' );

ok( sn_mcp_negotiate_version( '2025-06-18' ) === '2025-06-18', 'negotiate echoes a supported client version' );
ok( sn_mcp_negotiate_version( '1999-01-01' ) === SN_MCP_PROTOCOL_VERSION, 'negotiate falls back to our default for an unknown version' );

// ============================================================
// v9.50.0 — the rw door (POST /mcp-rw)
// ============================================================
echo "\nMCP rw-door allowlist (v9.50.0)\n\n";

$rw = sn_mcp_rw_allowlist();
ok( is_array( $rw ) && count( $rw ) === 34, 'rw allowlist is exactly 34 slugs (recounted against the audit; the spec draft\'s own arithmetic totals 34, not the Architecture summary\'s stale 33)' );

// --- exact membership: the 29 plugin + 5 theme slugs, pinned individually ---
$rw_plugin = array(
	'signal-noise/ai-alt-suggest', 'signal-noise/ai-alt-apply',
	'signal-noise/ai-drift-suggest', 'signal-noise/ai-drift-apply',
	'signal-noise/ai-alt-inline-suggest', 'signal-noise/ai-orphan-suggest',
	'signal-noise/ai-link-suggest', 'signal-noise/ai-link-apply',
	'signal-noise/ai-pair-suggest', 'signal-noise/pattern-adoption-suggest',
	'signal-noise/pattern-adoption-apply', 'signal-noise/ai-generate-excerpt',
	'signal-noise/ai-generate-meta-description', 'signal-noise/ai-generate-og-card-title',
	'signal-noise/run-audit-prune', 'signal-noise/get-audit-log',
	'signal-noise/export-audit-log', 'signal-noise/block-migrations-apply',
	'signal-noise/block-migrations-suggest', 'signal-noise/suggest-tags',
	'signal-noise/prune-unused-tags', 'signal-noise/regenerate-og-card',
	'signal-noise/unschedule-cron-event', 'signal-noise/dismiss-candidate',
	'signal-noise/run-insights-scan', 'signal-noise/run-narration',
	'signal-noise/prepop-dismiss', 'signal-noise/draft-release-notes',
	'signal-noise/purge-all-caches',
);
ok( count( $rw_plugin ) === 29, 'sanity: the pinned plugin rw list itself is 29' );
foreach ( $rw_plugin as $slug ) {
	ok( in_array( $slug, $rw, true ), "rw-door plugin slug present: $slug" );
}
$rw_theme = array(
	'signal-and-noise/ai-generate-page-note-summary',
	'signal-and-noise/ai-suggest-block-pattern',
	'signal-and-noise/ai-validate-brand-alignment',
	'signal-and-noise/ai-generate-pattern-content',
	'signal-and-noise/ai-rewrite-in-brand-voice',
);
ok( count( $rw_theme ) === 5, 'sanity: the pinned theme rw list itself is 5' );
foreach ( $rw_theme as $slug ) {
	ok( in_array( $slug, $rw, true ), "rw-door theme slug present: $slug" );
}
ok( count( array_unique( $rw ) ) === count( $rw ), 'rw allowlist has no duplicate slugs' );
ok( count( array_intersect( $rw, sn_mcp_allowlist() ) ) === 0, 'rw allowlist never duplicates a read-door slug' );

// --- explicit ABSENCE of the excluded four, from the rw door ---
$excluded = array(
	'signal-noise/run-cron-event',
	'signal-noise/ai-orphan-apply',
	'signal-noise/merge-tags',
	'signal-noise/clear-template-overrides',
);
foreach ( $excluded as $slug ) {
	ok( ! in_array( $slug, $rw, true ), "excluded slug absent from rw door: $slug" );
	ok( ! in_array( $slug, sn_mcp_allowlist(), true ), "excluded slug absent from read door too: $slug" );
}

// --- sn_mcp_allowlist_for_door: the one door -> allowlist resolver ---
ok( sn_mcp_allowlist_for_door( SN_MCP_DOOR_READ ) === sn_mcp_allowlist(), 'allowlist_for_door(read) resolves to the read allowlist' );
ok( sn_mcp_allowlist_for_door( SN_MCP_DOOR_RW ) === sn_mcp_rw_allowlist(), 'allowlist_for_door(rw) resolves to the rw allowlist' );
ok( sn_mcp_allowlist_for_door( 'read' ) === sn_mcp_allowlist(), 'allowlist_for_door accepts the plain "read" string' );

// --- sn_mcp_is_allowed is per-door: the CALL gate, not just the list ---
ok( sn_mcp_is_allowed( 'signal-noise/ai-alt-suggest' ) === false, 'an rw-only slug is NOT allowed on the read door (default)' );
ok( sn_mcp_is_allowed( 'signal-noise/ai-alt-suggest', SN_MCP_DOOR_READ ) === false, 'an rw-only slug is NOT allowed on the read door (explicit)' );
ok( sn_mcp_is_allowed( 'signal-noise/ai-alt-suggest', SN_MCP_DOOR_RW ) === true, 'an rw-only slug IS allowed on the rw door' );
ok( sn_mcp_is_allowed( 'signal-noise/get-health-scan', SN_MCP_DOOR_RW ) === false, 'a read-only slug is NOT allowed on the rw door (no duplication)' );
foreach ( $excluded as $slug ) {
	ok( sn_mcp_is_allowed( $slug, SN_MCP_DOOR_READ ) === false, "excluded slug rejected on read door: $slug" );
	ok( sn_mcp_is_allowed( $slug, SN_MCP_DOOR_RW ) === false, "excluded slug rejected on rw door: $slug" );
}

// --- serverInfo distinguishes the rw door ---
$read_info = sn_mcp_server_info( SN_MCP_DOOR_READ );
$rw_info   = sn_mcp_server_info( SN_MCP_DOOR_RW );
ok( $read_info['name'] === 'Signal & Noise', 'read-door server_info name is unchanged (default door)' );
ok( $rw_info['name'] !== $read_info['name'], 'rw-door server_info name differs from the read door' );
ok( strpos( $rw_info['name'], 'Signal & Noise' ) === 0, 'rw-door server_info name still carries the site name' );
ok( stripos( $rw_info['name'], 'read-write' ) !== false, 'rw-door server_info name is distinguished as read-write' );
ok( $rw_info['version'] === $read_info['version'], 'both doors report the same plugin version' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
