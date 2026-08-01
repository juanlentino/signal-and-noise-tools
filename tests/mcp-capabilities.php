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
// Registry-aware apply_filters stub: with no filter registered it returns $v
// unchanged (identical to the old pass-through, so every existing filter-less
// assertion is unaffected); add_test_filter() lets the serverInfo-label test
// exercise the real override path. Extra args (e.g. the $door passed alongside
// the base) are ignored by the stub, matching WP's tolerant calling convention.
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) {
		foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); }
		return $v;
	}
}
function add_test_filter( $h, $cb ) { $GLOBALS['__filters'][ $h ][] = $cb; }
function clear_test_filters() { $GLOBALS['__filters'] = array(); }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP capabilities — plugin v9.22.0\n\n";

$list = sn_mcp_allowlist();
ok( is_array( $list ) && count( $list ) === 35, 'read-door allowlist has exactly 35 slugs (15 -> 23 in v9.50.0, -> 25 in v9.82.0, -> 28 in v10.6.0, -> 29 in v10.16.0, -> 31 in v10.17.0, -> 32 in v10.21.0, -> 33 in v10.22.0 cadence-flags, -> 35 in v10.26.0: sn-posts + sn-site-facts, the first two CONSOLIDATED tools)' );
ok( in_array( 'signal-noise/topic-clusters', $list, true ), 'v10.21.0: topic-clusters is allowlisted on the read door' );
ok( in_array( 'signal-noise/cadence-flags', $list, true ), 'v10.22.0: cadence-flags is allowlisted on the read door' );
ok( in_array( 'signal-noise/sn-posts', $list, true ), 'v10.26.0: sn-posts is allowlisted on the read door' );
ok( in_array( 'signal-noise/sn-site-facts', $list, true ), 'v10.26.0: sn-site-facts is allowlisted on the read door' );
ok( in_array( 'signal-noise/list-posts', $list, true ) && in_array( 'signal-noise/get-post-content', $list, true ), 'v10.26.0: sn-posts is NEW ALONGSIDE OLD — list-posts and get-post-content stay allowlisted, neither removed' );
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

// --- v9.82.0: the two operational-status reads doored this release ---
$v9820_read_slugs = array(
	'signal-noise/anchor-status',
	'signal-noise/provenance-integrity-status',
);
foreach ( $v9820_read_slugs as $slug ) {
	ok( in_array( $slug, $list, true ), "v9.82.0 new read-door slug present: $slug" );
	ok( sn_mcp_is_allowed( $slug, SN_MCP_DOOR_READ ) === true, "v9.82.0 read-door slug passes the call gate: $slug" );
}

// --- v10.16.0 (2026-07-30): the near-duplicate cousin scan doors read-only ---
ok( in_array( 'signal-noise/near-duplicate-scan', $list, true ), 'v10.16.0 read-door slug present: near-duplicate-scan' );
ok( sn_mcp_is_allowed( 'signal-noise/near-duplicate-scan', SN_MCP_DOOR_READ ) === true, 'v10.16.0 read-door slug passes the call gate: near-duplicate-scan' );

// --- v10.17.0 (2026-07-30): the two candidate generators door read-only ---
foreach ( array( 'signal-noise/keyword-candidates', 'signal-noise/link-candidates' ) as $slug ) {
	ok( in_array( $slug, $list, true ), "v10.17.0 read-door slug present: $slug" );
	ok( sn_mcp_is_allowed( $slug, SN_MCP_DOOR_READ ) === true, "v10.17.0 read-door slug passes the call gate: $slug" );
}

// The read door splits 15 plugin + 10 theme. Pinning the split (not just the
// total) means a slug added to the wrong namespace block can't hide inside a
// still-correct count.
$read_plugin = array_filter( $list, function ( $s ) { return strpos( $s, 'signal-noise/' ) === 0; } );
$read_theme  = array_filter( $list, function ( $s ) { return strpos( $s, 'signal-and-noise/' ) === 0; } );
ok( count( $read_plugin ) === 25, 'read door carries exactly 25 plugin slugs (13 -> 15 in v9.82.0, -> 18 in v10.6.0, -> 19 in v10.16.0, -> 21 in v10.17.0, -> 22 in v10.21.0, -> 23 in v10.22.0, -> 25 in v10.26.0: sn-posts + sn-site-facts, both plugin-namespace)' );
ok( count( $read_theme ) === 10, 'read door carries exactly 10 theme slugs (unchanged — sn-site-facts DISPATCHES to theme abilities but is itself a plugin-namespace slug)' );
ok( count( array_unique( $list ) ) === count( $list ), 'read allowlist has no duplicate slugs' );

ok( sn_mcp_is_allowed( 'signal-noise/get-narration' ) === true, 'is_allowed true for an allowlisted slug' );
ok( sn_mcp_is_allowed( 'signal-noise/run-narration' ) === false, 'is_allowed false for a non-allowlisted slug' );

$info = sn_mcp_server_info();
ok( ( $info['name'] ?? '' ) === 'Signal & Noise (Read)', 'read-door server_info name = branded base + door label' );
ok( ( $info['version'] ?? '' ) === '9.22.0', 'server_info carries SNT_VERSION' );

ok( sn_mcp_negotiate_version( '2025-06-18' ) === '2025-06-18', 'negotiate echoes a supported client version' );
ok( sn_mcp_negotiate_version( '1999-01-01' ) === SN_MCP_PROTOCOL_VERSION, 'negotiate falls back to our default for an unknown version' );

// ============================================================
// v9.50.0 — the rw door (POST /mcp-rw)
// ============================================================
echo "\nMCP rw-door allowlist (v9.50.0)\n\n";

$rw = sn_mcp_rw_allowlist();
ok( is_array( $rw ) && count( $rw ) === 35, 'rw allowlist is exactly 35 slugs (v10.0.0 retired draft-release-notes; v10.7.0 added update-post-surfaces)' );

// --- exact membership: the 30 plugin + 5 theme slugs, pinned individually ---
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
	'signal-noise/purge-all-caches',
	'signal-noise/anchor-sweep',
	'signal-noise/update-post-surfaces',
);
ok( count( $rw_plugin ) === 29, 'sanity: the pinned plugin rw list itself is 29 (v10.7.0 added update-post-surfaces)' );
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

// --- v9.82.0: run-health-scan stays off BOTH doors, on purpose ---
// The MCP layer dispatches synchronously with no timeout or execution budget;
// the scan is ~35s today and up to ~105s during an outage, behind Cloudflare's
// ~100s edge cap. Pinned here so the exclusion is a decision, not an oversight
// — if someone doors it, this suite goes red and they have to read the WHY
// comment on sn_mcp_rw_allowlist() before overriding it.
ok( ! in_array( 'signal-noise/run-health-scan', $rw, true ), 'run-health-scan absent from the rw door (sync dispatch vs the ~100s edge cap)' );
ok( ! in_array( 'signal-noise/run-health-scan', sn_mcp_allowlist(), true ), 'run-health-scan absent from the read door too (it is not a read)' );
ok( sn_mcp_is_allowed( 'signal-noise/run-health-scan', SN_MCP_DOOR_READ ) === false, 'run-health-scan rejected by the read-door call gate' );
ok( sn_mcp_is_allowed( 'signal-noise/run-health-scan', SN_MCP_DOOR_RW ) === false, 'run-health-scan rejected by the rw-door call gate' );
// Its results stay reachable: the doored read ability serves the cached scan.
ok( in_array( 'signal-noise/get-health-scan', sn_mcp_allowlist(), true ), 'get-health-scan IS doored — run-health-scan results stay agent-reachable without the long call' );

// anchor-sweep is the one non-readonly slug doored in v9.82.0: rw only.
ok( sn_mcp_is_allowed( 'signal-noise/anchor-sweep', SN_MCP_DOOR_RW ) === true, 'anchor-sweep IS allowed on the rw door (v9.82.0)' );
ok( sn_mcp_is_allowed( 'signal-noise/anchor-sweep', SN_MCP_DOOR_READ ) === false, 'anchor-sweep is NOT allowed on the read door (it acts, it does not read)' );

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
ok( $read_info['name'] === 'Signal & Noise (Read)', 'read-door server_info name = base + (Read)' );
ok( $rw_info['name'] === 'Signal & Noise (Write)', 'rw-door server_info name = base + (Write)' );
ok( $rw_info['name'] !== $read_info['name'], 'rw-door server_info name differs from the read door' );
ok( strpos( $rw_info['name'], 'Signal & Noise' ) === 0, 'rw-door server_info name still carries the branded base' );
// The branded base is filterable so an owner can rename both doors at once.
add_test_filter( 'sn_mcp_server_label', function () { return 'Juanlentino'; } );
ok( sn_mcp_server_info( SN_MCP_DOOR_READ )['name'] === 'Juanlentino (Read)', 'sn_mcp_server_label filter renames the base on both doors' );
clear_test_filters();
ok( $rw_info['version'] === $read_info['version'], 'both doors report the same plugin version' );

ok( ! in_array( 'signal-noise/draft-release-notes', sn_mcp_rw_allowlist(), true ), 'v10.0.0: draft-release-notes is off the rw door (ability retired)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
