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
ok( is_array( $list ) && count( $list ) === 28, 'read-door allowlist has exactly 28 slugs (v13.68.0 ADDED inbound-pass; v13.63.0 ADDED search-coverage; v13.62.0 ADDED family-drift; v13.57.0 ADDED search-performance/search-drift/search-crossexam, the weave Phase 1 sources; v13.45.0 ADDED draft-echoes, a single with no consolidated home and no prior verdict; v13.1.0 ADDED sn-status + sn-metrics, the sectioned-batch coherence readouts, new-alongside-old; v13.0.0 wave 2 retired 5: pattern-adoption-scan to sn-scan, list-posts + get-post-content to sn-posts, and the get-insights/get-narration pair; v12.11.0 had ADDED login-defense-ipv6-criterion; wave 1 (v12.0.0) retired 15)' );
ok( in_array( 'signal-noise/sn-validate', $list, true ), 'v10.30.0: sn-validate is allowlisted on the read door' );
ok( in_array( 'signal-noise/ai-cache-probe-status', $list, true ), 'v10.69.0: ai-cache-probe-status is allowlisted on the read door — registering the ability alone would leave it invisible to MCP' );
ok( in_array( 'signal-noise/topic-clusters', $list, true ), 'v10.21.0: topic-clusters is allowlisted on the read door' );
// v13.45.0. draft-echoes had NO recorded verdict anywhere — not retired, not
// absorbed, never accounted for. It joins the door as a SINGLE, beside its two
// read_corpus siblings above.
//
// AND NOT AS AN sn-scan TYPE, which is where the plan first put it. sn-scan
// takes a SCOPE and walks the corpus; draft-echoes REQUIRES post_id and scores
// ONE draft against everything else — its own description calls it "the same
// kernel as near-duplicate-scan, asked from the other direction: one document
// against many, rather than all pairs". near_duplicate already IS the
// corpus-walk direction. Forcing a single-target query into a scope-based tool
// would misreport what it does and give it a scope it cannot honour.
ok( in_array( 'signal-noise/draft-echoes', $list, true ), 'v13.45.0: draft-echoes is allowlisted on the read door' );
ok( in_array( 'signal-noise/cadence-flags', $list, true ), 'v10.22.0: cadence-flags is allowlisted on the read door' );
ok( in_array( 'signal-noise/sn-posts', $list, true ), 'v10.26.0: sn-posts is allowlisted on the read door' );
ok( in_array( 'signal-noise/sn-site-facts', $list, true ), 'v10.26.0: sn-site-facts is allowlisted on the read door' );
ok( in_array( 'signal-noise/sn-status', $list, true ) && in_array( 'signal-noise/sn-metrics', $list, true ), 'v13.1.0: the two sectioned-batch coherence readouts are doored' );
// v13.1.0 — NEW ALONGSIDE OLD holds for the coherence pair: every absorbed
// single stays doored until a telemetry window justifies wave 4 (the exact
// contract waves 1-2 ran under before their retirements).
foreach ( array( 'uptime-status', 'get-deploy-status', 'get-health-scan', 'anchor-status', 'provenance-integrity-status', 'login-defense-ipv6-criterion', 'ai-cache-probe-status', 'cadence-flags', 'list-cron-events', 'get-cron-history', 'get-analytics-summary', 'get-analytics-events', 'get-rss-stats' ) as $single ) {
	ok( in_array( "signal-noise/$single", $list, true ), "v13.1.0 new-alongside-old: $single stays doored" );
}
// v10.26.0 pinned "NEW ALONGSIDE OLD — list-posts and get-post-content stay
// allowlisted". That invariant was correct while sn-posts was unproven; wave 2
// replaces it with the retirement contract at the bottom of this file (absent
// from BOTH doors AND the absorber present).
ok( ! in_array( 'signal-noise/list-posts', $list, true ) && ! in_array( 'signal-noise/get-post-content', $list, true ), 'v13.0.0 wave 2: list-posts and get-post-content are RETIRED from the read door — sn-posts absorbs both (content is an opt-in field)' );
ok( in_array( 'signal-noise/sn-scan', $list, true ), 'v10.29.0: sn-scan is allowlisted on the read door' );
ok( in_array( 'signal-noise/get-health-scan', $list, true ), 'plugin read is allowlisted' );
// v11.34.0 — THE DOORS NO LONGER SPAN THE THEME NAMESPACE. Every
// signal-and-noise/* slug was either absorbed by sn-site-facts (the ten facts)
// or retired in tier C (the five theme AI tools). The theme's DATA is still
// reachable — sn-site-facts dispatches to those abilities via
// wp_get_ability()->execute(), which this list does not gate — but no theme
// slug is directly callable as an MCP tool any more. Pinned as a deliberate
// property so it cannot happen again by accident.
ok( array() === array_filter( $list, static fn( $s ) => str_starts_with( $s, 'signal-and-noise/' ) ), 'v11.34.0: NO theme slug is on the read door — every one is absorbed or retired' );
ok( in_array( 'signal-noise/sn-site-facts', $list, true ), 'and the tool that absorbed them IS doored — the theme facts stay reachable through it' );
ok( ! in_array( 'signal-noise/purge-all-caches', $list, true ), 'a write ability is NOT allowlisted on the read door' );
// v9.50.0: get-llms-txt is RE-ADDED to the read door by D1 (it was previously
// cut as redundant). This intentionally reverses the old v9.22.0 assertion.
ok( ! in_array( 'signal-and-noise/get-llms-txt', $list, true ), 'v11.34.0: get-llms-txt is RETIRED from the read door — sn-site-facts{llms_txt} serves it (its 18 agent-door calls are not gated by this list)' );

// --- D1: the surviving D1 read-door slug (pattern-adoption-scan moved to the
// wave-2 retirement contract below in v13.0.0 — sn-scan{pattern_adoption}) ---
$new_read_slugs = array(
	'signal-noise/get-analytics-events',
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

// --- v10.17.0 (2026-07-30): the two candidate generators door read-only ---
foreach ( array( 'signal-noise/keyword-candidates' ) as $slug ) {
	ok( in_array( $slug, $list, true ), "v10.17.0 read-door slug present: $slug" );
	ok( sn_mcp_is_allowed( $slug, SN_MCP_DOOR_READ ) === true, "v10.17.0 read-door slug passes the call gate: $slug" );
}

// The read door splits 15 plugin + 10 theme. Pinning the split (not just the
// total) means a slug added to the wrong namespace block can't hide inside a
// still-correct count.
$read_plugin = array_filter( $list, function ( $s ) { return strpos( $s, 'signal-noise/' ) === 0; } );
$read_theme  = array_filter( $list, function ( $s ) { return strpos( $s, 'signal-and-noise/' ) === 0; } );
ok( count( $read_plugin ) === 28, 'read door carries exactly 28 plugin slugs (-> 28 in v13.68.0: inbound-pass; -> 27 in v13.63.0: search-coverage; -> 26 in v13.62.0: family-drift; -> 25 in v13.57.0: three search-console reads added; -> 22 in v13.45.0: draft-echoes added; -> 21 in v13.1.0: sn-status + sn-metrics added; -> 19 in v13.0.0 wave 2; -> 24 in v12.11.0; 23 in v11.34.0; was 28 before wave 1, plugin-namespace)' );
ok( count( $read_theme ) === 0, 'read door carries ZERO theme slugs (v11.34.0 — sn-site-facts DISPATCHES to them and is itself a plugin-namespace slug)' );
ok( count( array_unique( $list ) ) === count( $list ), 'read allowlist has no duplicate slugs' );

ok( sn_mcp_is_allowed( 'signal-noise/get-health-scan' ) === true, 'is_allowed true for an allowlisted slug' );
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
ok( is_array( $rw ) && count( $rw ) === 8, 'rw allowlist is exactly 8 slugs (v13.25.0 WIDENED by 2, owner-directed: the tag-vocabulary pair describe-tags + apply-tag-description beside prune-unused-tags; v13.0.0 wave 2 had retired 4, wave 1 (v12.0.0) 26)' );

// --- exact membership: the 8 plugin slugs, pinned individually ---
// v13.0.0: down to sn-apply, the deliberately-kept AI link pair (see the KEPT
// pin below), and the three no-absorber operations tools.
// v13.25.0: + the tag-vocabulary pair (AI-billed returns-only drafting, and
// an only-if-empty write that never clobbers an owner edit).
$rw_plugin = array(
	'signal-noise/sn-apply',
	'signal-noise/purge-all-caches',
	'signal-noise/prune-unused-tags',
	'signal-noise/unschedule-cron-event',
	'signal-noise/ai-link-apply',
	'signal-noise/ai-pair-suggest',
	'signal-noise/describe-tags',
	'signal-noise/apply-tag-description',
);
ok( count( $rw_plugin ) === 8, 'sanity: the pinned plugin rw list itself is 8 (v13.25.0)' );
foreach ( $rw_plugin as $slug ) {
	ok( in_array( $slug, $rw, true ), "rw-door plugin slug present: $slug" );
}
ok( array() === array_filter( $rw, static fn( $s ) => str_starts_with( $s, 'signal-and-noise/' ) ), 'v11.34.0: NO theme slug is on the rw door either — the five theme AI tools retired with tier C' );
ok( count( $rw_plugin ) === count( $rw ), 'the pinned list IS the whole door — nothing doored that this suite does not name' );

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

// anchor-sweep was the one non-readonly slug doored in v9.82.0. v11.34.0
// retired it — sn-apply{change.type:anchor_sweep} performs the same sweep, and
// it had zero MCP calls in the 30-day window.
ok( sn_mcp_is_allowed( 'signal-noise/anchor-sweep', SN_MCP_DOOR_RW ) === false, 'v11.34.0: anchor-sweep is RETIRED from the rw door — sn-apply{anchor_sweep} absorbs it' );
ok( sn_mcp_is_allowed( 'signal-noise/anchor-sweep', SN_MCP_DOOR_READ ) === false, 'anchor-sweep is NOT allowed on the read door (it acts, it does not read)' );

// --- sn_mcp_allowlist_for_door: the one door -> allowlist resolver ---
ok( sn_mcp_allowlist_for_door( SN_MCP_DOOR_READ ) === sn_mcp_allowlist(), 'allowlist_for_door(read) resolves to the read allowlist' );
ok( sn_mcp_allowlist_for_door( SN_MCP_DOOR_RW ) === sn_mcp_rw_allowlist(), 'allowlist_for_door(rw) resolves to the rw allowlist' );
ok( sn_mcp_allowlist_for_door( 'read' ) === sn_mcp_allowlist(), 'allowlist_for_door accepts the plain "read" string' );

// --- sn_mcp_is_allowed is per-door: the CALL gate, not just the list ---
// The per-door gate, exercised on a slug that is STILL rw-only (ai-alt-suggest
// retired in v11.34.0; the property is about the DOOR, not that tool).
ok( sn_mcp_is_allowed( 'signal-noise/sn-apply' ) === false, 'an rw-only slug is NOT allowed on the read door (default)' );
ok( sn_mcp_is_allowed( 'signal-noise/sn-apply', SN_MCP_DOOR_READ ) === false, 'an rw-only slug is NOT allowed on the read door (explicit)' );
ok( sn_mcp_is_allowed( 'signal-noise/sn-apply', SN_MCP_DOOR_RW ) === true, 'an rw-only slug IS allowed on the rw door' );
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

echo "Group: the remote analytics slugs never join the laptop door's lists\n";
// Pinned HERE, in the allowlist's own suite, and not only in the remote suite:
// whoever widens sn_mcp_allowlist() next will be running this file.
// No filter is registered at this point, so the apply_filters stub is an
// identity pass-through and these two assertions pin the STATIC list only —
// they are not coverage of the sn_mcp_allowlist filter path.
require_once __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
foreach ( sn_mcp_remote_slugs() as $remote_slug ) {
	ok( ! in_array( $remote_slug, sn_mcp_allowlist(), true ), "$remote_slug is absent from the READ allowlist" );
	ok( ! in_array( $remote_slug, sn_mcp_rw_allowlist(), true ), "$remote_slug is absent from the WRITE allowlist" );
}

// ── v11.34.0 — THE RETIREMENT CONTRACT ────────────────────────────────────
// The old invariant ("new alongside old — nothing retires until usage data
// justifies it") is spent: the data arrived. What replaces it is stronger than
// a count, because a count cannot stop a slug drifting back in.
//
// For every retired slug: it is absent from BOTH doors, AND the consolidated
// tool that absorbed it is present. The second half is the one that matters —
// retiring an absorbed tool while its absorber is also gone would delete the
// capability outright, which is the failure this pin exists to make impossible.
echo "\nv11.34.0 retirement contract\n\n";
$retired_to_absorber = array(
	'signal-and-noise/get-theme-version'             => 'signal-noise/sn-site-facts',
	'signal-and-noise/get-latest-theme-tag'          => 'signal-noise/sn-site-facts',
	'signal-and-noise/get-design-tokens'             => 'signal-noise/sn-site-facts',
	'signal-and-noise/list-block-patterns'           => 'signal-noise/sn-site-facts',
	'signal-and-noise/get-active-template-structure' => 'signal-noise/sn-site-facts',
	'signal-and-noise/get-llms-txt'                  => 'signal-noise/sn-site-facts',
	'signal-and-noise/get-seo-route-meta'            => 'signal-noise/sn-site-facts',
	'signal-and-noise/get-page-notes-pillars'        => 'signal-noise/sn-site-facts',
	'signal-and-noise/get-reading-time-for-slug'     => 'signal-noise/sn-site-facts',
	'signal-noise/list-template-overrides'           => 'signal-noise/sn-site-facts',
	'signal-noise/link-candidates'                   => 'signal-noise/sn-scan',
	'signal-noise/duplicate-body-scan'               => 'signal-noise/sn-scan',
	'signal-noise/near-duplicate-scan'               => 'signal-noise/sn-scan',
	'signal-noise/block-migrations-scan'             => 'signal-noise/sn-scan',
	'signal-noise/pattern-adoption-suggest'          => 'signal-noise/sn-scan',
	'signal-noise/pattern-adoption-apply'            => 'signal-noise/sn-apply',
	'signal-noise/block-migrations-apply'            => 'signal-noise/sn-apply',
	'signal-noise/anchor-sweep'                      => 'signal-noise/sn-apply',
	'signal-noise/regenerate-og-card'                => 'signal-noise/sn-apply',
	'signal-noise/ai-alt-apply'                      => 'signal-noise/sn-apply',
	'signal-noise/ai-drift-apply'                    => 'signal-noise/sn-apply',
	// ── v13.0.0 — wave 2 (verdicts: docs/mcp-consolidation/retirement-verdicts-2026-08-25.md) ──
	'signal-noise/pattern-adoption-scan'             => 'signal-noise/sn-scan',
	'signal-noise/list-posts'                        => 'signal-noise/sn-posts',
	'signal-noise/get-post-content'                  => 'signal-noise/sn-posts',
	'signal-noise/block-migrations-suggest'          => 'signal-noise/sn-scan',
	'signal-noise/update-post-surfaces'              => 'signal-noise/sn-apply',
);
$all_doors = array_merge( sn_mcp_allowlist(), sn_mcp_rw_allowlist() );
foreach ( $retired_to_absorber as $retired => $absorber ) {
	ok( ! in_array( $retired, $all_doors, true ), "retired from BOTH doors: $retired" );
	ok( in_array( $absorber, $all_doors, true ), "  ...and its absorber $absorber is still doored" );
}

// Tier C had NO absorber. Retiring these was an owner decision taken with the
// consequence stated: they lose their MCP path and gain no equivalent. They
// stay registered, internally callable and REST-reachable — this list is the
// MCP door only. Pinned so the absence is a decision on the record.
$retired_without_absorber = array(
	'signal-noise/ai-alt-suggest', 'signal-noise/ai-alt-inline-suggest',
	'signal-noise/ai-drift-suggest', 'signal-noise/ai-orphan-suggest',
	'signal-noise/ai-link-suggest', 'signal-noise/ai-generate-excerpt',
	'signal-noise/ai-generate-meta-description', 'signal-noise/ai-generate-og-card-title',
	'signal-noise/suggest-tags', 'signal-noise/run-audit-prune',
	'signal-noise/get-audit-log', 'signal-noise/export-audit-log',
	'signal-noise/dismiss-candidate', 'signal-noise/prepop-dismiss',
	'signal-and-noise/ai-generate-page-note-summary', 'signal-and-noise/ai-suggest-block-pattern',
	'signal-and-noise/ai-validate-brand-alignment', 'signal-and-noise/ai-generate-pattern-content',
	'signal-and-noise/ai-rewrite-in-brand-voice',
);
foreach ( $retired_without_absorber as $slug ) {
	ok( ! in_array( $slug, $all_doors, true ), "tier C, no absorber, retired by decision: $slug" );
}
ok( 19 === count( $retired_without_absorber ), 'tier C is NINETEEN tools — if this number grows, someone retired capability without saying so' );

// ── v13.0.0 — wave 2: the insights/narration quartet, retired outright ─────
// Spec'd "retired, not absorbed" since day one. The weekly-report prompt was
// rewritten in the same release; the prompt-text pins live in
// tests/mcp-prompts.php (that harness loads the prompts module, this one
// deliberately does not).
$wave2_retired_outright = array(
	'signal-noise/get-insights', 'signal-noise/get-narration',
	'signal-noise/run-insights-scan', 'signal-noise/run-narration',
);
foreach ( $wave2_retired_outright as $slug ) {
	ok( ! in_array( $slug, $all_doors, true ), "v13.0.0 wave 2, retired outright: $slug" );
}

// ── v13.0.0 — THE KEPT PAIR, pinned as a pair ──────────────────────────────
// The spec's mapping calls ai-pair-suggest and ai-link-apply absorbed; the
// shipped code disproves it — sn-scan deliberately emits apply_hint:null for
// link_candidates because ai-link-apply validates a positional fingerprint
// only the AI-mediated suggest can produce. No sn-apply bridge exists.
// Retiring either half alone would strand the other; retiring both would
// DELETE the AI link flow over MCP. Whoever builds the fingerprint bridge
// gets to retire them — until then this pin holds the pair on the door.
ok( in_array( 'signal-noise/ai-pair-suggest', $all_doors, true ) && in_array( 'signal-noise/ai-link-apply', $all_doors, true ), 'v13.0.0: the AI link pair stays doored TOGETHER — no sn-apply bridge exists for its fingerprint contract' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
