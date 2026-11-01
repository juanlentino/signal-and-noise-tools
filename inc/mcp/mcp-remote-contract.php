<?php
/**
 * Signal & Noise — Remote MCP payload contract mirror (phase 2 of the
 * versioned contract).
 *
 * Phase 1 lives in the WORKER repo (sn-remote-mcp-worker v0.5.0): the door
 * pins the response ENVELOPE it authors and exposes CONTRACT_VERSION on
 * `initialize` (`_meta["sn/contractVersion"]`) and on `/status`
 * (`contract_version`). This file is the origin's half — the worker is a
 * pass-through and cannot see payload shapes, so the shapes are pinned where
 * they are authored: every remote twin ability's `output_schema` (tests/mcp-remote-guard.php pins the count), which the
 * parity suite already holds byte-identical to their admin registrations.
 *
 * THE COUPLING (design doc: sn-remote-mcp-worker
 * docs/plans/2026-08-27-versioned-contract-design.md, open question 2):
 *
 * - CI direction: SN_REMOTE_CONTRACT_VERSION_HASHES pins (version → sha256
 *   over the canonical JSON of the twins' output_schemas). A change to any remote
 *   ability's PAYLOAD shape fails tests/remote-contract-shapes.php unless the
 *   version moves with it, and a version bump without a shape change fails
 *   the same pin. Keys and TYPES only, never values — pinning values trains
 *   fixture-updating reflexes that turn a guard into a rubber stamp.
 * - INSTALL direction: cross-repo skew in this estate lands at INSTALL time,
 *   not at CI (the two repos ship independently), so no CI job reads the
 *   other repo. Instead the deploy-workers probe compares the worker's live
 *   `/status.contract_version` against SN_REMOTE_CONTRACT_VERSION and carries
 *   the comparison in its result — skew becomes an observable exactly where
 *   it lands.
 *
 * A DECLARATION, never a gate: nothing refuses on a mismatch, mirroring the
 * worker's own always-advertise decision.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mirrors CONTRACT_VERSION in sn-remote-mcp-worker src/mcp.mjs. Bump BOTH
// (worker constant + this mirror + the hash map below) when any remote
// payload shape or the door's envelope changes.
// v13.52.0: '1' -> '2' — three ratified twins joined the map
// (provenance-integrity, machine-readers, cron-health). The worker's
// CONTRACT_VERSION bumps in the same arc; until its deploy lands, the deploy
// probe reads contract_match:false, which is the observed-not-refused design.
// 18.0.0: '5' -> '6' — remote-edge-errors-summary joined the map (15 twins).
// 18.2.0: '6' -> '7' — edge-errors-summary gained days[] + asked_by; the
// uptime row's availability description stopped saying "null on the light tier".
// 18.3.0: '7' -> '8' — each days[] row of the 5xx twin gained `read`
// (read / failed / pending / untracked), so a 0 is never ambiguous.
// 18.4.0: '8' -> '9' — the health-scan twin gained skipped[] (check, label,
// reason), so checks_skipped names what did not run and why.
// 18.8.4: '9' -> '10' — the 5xx twin gained paths_by_status (#1006),
// "<edge> <cache> <path>" per failing request, so a 520 names its URL.
// 19.5.1: '10' -> '11' — the rss-stats twin gained feed_opens (7d / 30d
// totals + top notes, labelled a floor). Additive.
// 19.6.0: '11' -> '12' — the rss-stats twin gained subscribers (the
// counts aggregators report in their fetcher UA, labelled a ceiling). Additive.
// 19.7.0: '12' -> '13' (2026-09-29) — the deploy-status twin gained core
// (current, latest, state, auto_updates, reason) after CVE-2026-87902. Additive.
// The admin ability also gained runtime; the twin does NOT carry it.
// 19.8.0: '13' -> '14' (2026-09-29, owner ruling) — two twins joined (17):
// remote-bot-signals (byte-identical, observe-only cohort rates) and
// remote-machine-readers-networks (the crosstab's agent_networks alone).
// Deploy runtime and the recompute status stay local.
// Contract 15 (owner brief 2026-10-10): remote-analytics-rows, counted rows by
// path, referrer, country, device or day (inc/analytics-rows.php).
// Contract 16 (owner brief 2026-10-11, one bump for the arc): every attributed
// count carries its evidence basis. The machine-readers summary twin gains
// impersonations, verification, basis and reader_families, families[].first_party_hits, and
// descriptions on ai_training (legacy), purposes (unknown is unmapped),
// first_party and identity (signed is not verified); the analytics summary
// twin gains likely_automated and basis. Additive. The worker's own
// CONTRACT_VERSION bump is a separate PR after this one.
// Contract 17 (owner-approved 2026-10-13, "Yes, show the median"): each uptime
// row gains median_ms, max_ms, max_region and max_at, and response_ms is
// documented as the median (it was the mean). Additive keys; on the remote
// twin (the light tier) every one is null. The worker's own CONTRACT_VERSION
// bump is a separate PR.
// Contract 18 (owner-approved 2026-10-14, "add it to tomorrow's cut"): each
// flagged row of the health-scan twin gains partial (why part of the check was
// not judged, naming the posts) and failed[] (post_id, title, reason). Additive.
// The worker's own CONTRACT_VERSION bump is a separate PR.
// Contract 19 (owner brief 2026-10-18, "Keep the number, but filter it"): the
// machine-readers summary twin gains impostors, reads that claimed an agent
// Cloudflare verifies when genuine and failed its check, credited to no one
// (basis inferred). Additive. The worker's own CONTRACT_VERSION bump is a
// separate PR.
// Contract 20 (owner-approved 2026-10-18, "If you recommend this, go for
// it"): each impostors[] entry gains reasons (hits per reason: cf_directory,
// vendor_ranges, off_home_network, rotator_network), and the verification
// split gains vendor_verified, the part of verified that rests on the
// vendor's published IP ranges (basis vendor_verified). Additive. The worker's
// own CONTRACT_VERSION bump is a separate PR, merged after this cut installs.
// Contract 21 (2026-10-19): the 5xx twin gains probes{total, paths}, scanner
// requests to core library files counted apart, and total (window and per
// day) leaves them out; each days[] row gains probes. Additive keys; total's
// meaning narrows. The worker's own CONTRACT_VERSION bump is a separate PR.
// Contract 22 (owner-approved 2026-10-22, "so I can ask for it from my
// phone"): a nineteenth twin, remote-indexing-requests, the byte-identical twin
// of signal-noise/indexing-requests (the request list, the 30-day stuck flag,
// the publish check). The broad search-coverage map stays local. Additive: no
// existing twin's shape moves. The worker's own CONTRACT_VERSION bump, its
// BRIDGE_TOOLS entry and its plugin-slugs fixture are a separate PR.
// Contract 23 (2026-10-22): the indexing-requests twin reads the site chrome.
// Request and stuck rows gain chrome (boolean) and template[]; publish_check
// gains chrome_paths, template_paths and graph_render, and each failure an
// inbound_source (chrome | template | body) and template[]. Additive keys. The worker's own CONTRACT_VERSION bump is a separate PR.
// Contract 24 (2026-10-26, docs/audits/2026-10-26-mcp-doors.md F2): the Worker
// declares every bridge tool's outputSchema, so structuredContent changes shape:
// the five twins whose root is not exactly an object (analytics-events' list,
// the four nullable reports) arrive wrapped as {result: …}, the laptop doors'
// own convention (sn_mcp_project_output_schema). The raw schemas did not
// change, so from 24 the hash covers the WIRE schemas
// (sn_remote_contract_wire_schemas), which is what a client validates. The
// Worker carries tests/fixtures/remote-output-schemas.json and re-checks this
// hash. The worker's own CONTRACT_VERSION bump ships with it.
// 2026.11 (coded résumé links, 2026-10-31): '24' -> '25'. Each analytics-rows
// row may carry link_code, the code of a /r/CODE link read out of the stored
// path (inc/link-codes.php). Additive.
// 2026-11-01 (owner brief "MCP full analytics access"): '25' -> '26'. The
// fields read joins the map (signal-noise/remote-analytics-fields). Additive.
// 2026-11-01, part 2: '26' -> '27'. Analytics rows widen: region, city and
// hour, the window (from, to) and the source in the envelope, the fields read's
// custom window and events source. Additive.
// 2026-11-01, part 3: '27' -> '28'. The sessions read and its twin
// (remote-analytics-sessions: sessions one by one, the Sessions tab's list),
// and the rows read's session dimensions (entry, exit, returning; a sessions
// metric on every row, null outside source sessions).
const SN_REMOTE_CONTRACT_VERSION = '28';

// version → sha256 over sn_remote_contract_shape_hash()'s canonical JSON of
// the remote twins' output_schemas. Every version maps to a DISTINCT hash:
// a version bump without a shape change is a lie the test refuses.
const SN_REMOTE_CONTRACT_VERSION_HASHES = array(
	'1' => '90f2ce6597120d1dc2dd46f28b38916fac08783a3da5937fb032417ba3a32c20',
	// RED-then-pin, 2026-09-01: the failing test computed this over the
	// 11-twin map. Never hand-derived.
	'2' => '5232aaa9c622fcbefcb93e3599521ab30007549fb30e3881d889f093ceb7743e',
	// RED-then-pin, 2026-09-01 (v13.61.0): 13-twin map, two Search Console twins.
	'3' => '6c5a0bc62def0f21de9ffa6ff0e099909077329fd0ecbcffea531612dd1f89be',
	// RED-then-pin, 2026-09-01 (v13.67.0): 14-twin map, the cross-exam joined.
	'4' => 'e5469f17e28b1e046ce1a66489edd39e4ec7fde8d3c5440cc74d8a15e13fd880',
	// RED-then-pin, 2026-09-10: remote-get-narration's payload gains
	// state / reason / message / failed_at — a dead provider is reported as
	// state:unavailable instead of the null that also meant "cold". Additive:
	// every prior key keeps its type. Same 14 twins.
	'5' => '438296d026e2a69a98ca56973fe9a90cc011af787cae8cefe73f5b486e58d5d8',
	// RED-then-pin, 2026-09-23 (18.0.0): 15-twin map, the 5xx rollup joined.
	'6' => '97a07c3ac9b980101e09c253b401fda4cbfa5882fae2931d6ab17b7c2a83939b',
	// RED-then-pin, 2026-09-24 (v18.2.0): days[] + asked_by on the 5xx twin.
	'7' => '628acbb113849c41d127f70abc30585907f46715e9d256d4ecec3544ecce89b8',
	// RED-then-pin, 2026-09-24 (v18.3.0): days[].read on the 5xx twin.
	'8' => 'bced0a5ade3a205e83b3e386783de0213118b19463d4a497bb8a5034d7b36843',
	// RED-then-pin, 2026-09-24 (v18.4.0): skipped[] on the health-scan twin.
	'9' => 'ed9407989fdf8489c7bc3575e869187d310cb16206b8b8005b7d633a50911e85',
	// RED-then-pin, 2026-09-26 (#1006): paths_by_status on the 5xx twin.
	'10' => 'ad45bba357b8da9e01a9f10a8590b58df01480694b704fa991bc30f11f503138',
	// RED-then-pin, 2026-09-27: feed_opens on the rss-stats twin.
	'11' => '60c50a1daa0050be6b0f5b4a72330222282a3dd77720f6d90acfef884cf13968',
	// RED-then-pin, 2026-09-27: subscribers on the rss-stats twin.
	'12' => '0dd9868fa197f8c4272bb1923145376538d43bfa59bbec81ee0e76b30c9fedc3',
	// RED-then-pin, 2026-09-29: core (state ok|behind|unknown + offer point|major) on the deploy-status twin (runtime stays local).
	'13' => 'ece40b3b4b4ef3e4d192300cf6e32f1ad0d69cf56808438120c5fe993a032524',
	// RED-then-pin, 2026-09-29: 17-twin map, bot-signals + agent_networks joined.
	'14' => '8af49ec631820d9127a97e6271de37eaf80d28129bdf4b38db98f9bbdeb8b046',
	// RED-then-pin, 2026-10-10: 18-twin map, remote-analytics-rows joined.
	'15' => '3158c41d6236a25c6e9f1a0c87904b5e03096b3c6e86b701fd3421be2856e2ec',
	// RED-then-pin, 2026-10-11: evidence basis on the machine-readers and analytics summary twins.
	'16' => '14565a3580c11d62d93822a5bf6c0cf5eaeacdd6d49c97c17846b205dc663be8',
	// RED-then-pin, 2026-10-13: the uptime row's median and slowest-check keys.
	'17' => '9a44d64aa779d567e1fe8746d526107dafa18fd93117a6a783ccdd047def2a47',
	// RED-then-pin, 2026-10-14: partial + failed[] on the health-scan twin's flagged rows.
	'18' => 'ec15e0e6b2544300a3d65a481f94648070a36f19f19420d6bb7fe3a3dc28c50a',
	// RED-then-pin, 2026-10-18: impostors on the machine-readers summary twin.
	'19' => 'ddce832f49228f7710fb770c71fc3f9ff53fa72d97669b4304c1915e30e117b7',
	// RED-then-pin, 2026-10-18: impostor reasons and vendor_verified on the machine-readers summary twin.
	'20' => 'b33030216b11642e3dd162992bd90f01e056e437b3bfaff7f9e783a6f5b91d6b',
	// RED-then-pin, 2026-10-19: probes on the 5xx twin (window and per day).
	'21' => '19f08c2ab0d0c3474707820e1ec0a4450728e147d54aeb918bda1228e99af860',
	// RED-then-pin, 2026-10-22: 19-twin map, remote-indexing-requests joined.
	'22' => '46f5cd540427bc4bf8289ef44ba01c6e2cd610f2cbd46ef54a6da0bddecc9a9a',
	// RED-then-pin, 2026-10-22: chrome, template, chrome_paths, template_paths, graph_render and inbound_source on the indexing-requests twin.
	'23' => '974633d777835c34a1a7b1a7c9fec9040ac8769fab9cd3ec0b804bf09193dd80',
	// RED-then-pin, 2026-10-26: from 24 the hash is over the wire (projected) schemas.
	'24' => 'ed3e4ee3fd4ec7bda98127da1480887538b712009a871154c50d9d87cbf3d714',
	// RED-then-pin, 2026-10-31: analytics-rows rows gained link_code.
	'25' => '0e07b989fdeba08e7f6bb37b27c00eb958ef14bf8f29a5de034ba7b936bdf8b4',
	// RED-then-pin, 2026-11-01: the analytics fields twin joined the map.
	'26' => 'c75b1a5bc086fd429f8e52eff13300bf1dcb9e165d8865fe5fbf92ada9aba6a1',
	// RED-then-pin, 2026-11-01: rows gained region, city, hour, from, to, source.
	'27' => 'e6e74803f1384469f32c22fa342f2e316604a636d8bade817d749e98cd66098d',
	'28' => '534f2b91068746076f6d41c5b1a5db81d94a50a1cbd52974f10819ee4c1c8089',
);

/**
 * The twins' output schemas as a client sees them, contract 24 on: each one
 * through sn_mcp_project_output_schema(), so the remote door wraps exactly as
 * the laptop doors do ({result: …} for any root that is not exactly an
 * object). Pure function of its input. Never simplify the wrap away: a
 * declared outputSchema makes structuredContent required and an object, and
 * five twins can answer a list or null.
 *
 * @param array<string,array> $schemas Map of ability slug => raw output_schema.
 * @return array<string,array> Map of ability slug => projected outputSchema.
 */
function sn_remote_contract_wire_schemas( $schemas ) {
	$out = array();
	foreach ( (array) $schemas as $slug => $schema ) {
		$out[ $slug ] = sn_mcp_project_output_schema( $schema );
	}
	return $out;
}

/**
 * Test seam only: tests/remote-contract-shapes.php pins SN_REMOTE_CONTRACT_VERSION_HASHES with it.
 *
 * Canonical sha256 over a slug-keyed map of output_schemas.
 *
 * Canonicalization: recursive key sort, then plain json_encode — so array
 * ORDER inside a schema (e.g. a type union) stays significant while the
 * incidental order of registration does not. Pure function of its input;
 * runtime never calls it (the runtime half of the contract is only the
 * VERSION constant, read by the deploy probe comparison).
 *
 * @param array $schemas Map of ability slug => output_schema array.
 * @return string 64-char lowercase hex sha256.
 */
function sn_remote_contract_shape_hash( $schemas ) {
	if ( ! is_array( $schemas ) ) {
		return '';
	}
	$canon = sn_remote_contract_ksort_deep( $schemas );
	return hash( 'sha256', (string) json_encode( $canon ) );
}

/**
 * Recursively key-sort associative arrays; leave list arrays in order.
 *
 * A JSON-schema `type` union like array('object','null') is a LIST whose
 * order is part of the byte-identical parity pin, so lists must not be
 * sorted; only string-keyed maps are.
 *
 * @param mixed $value Any schema fragment.
 * @return mixed
 */
function sn_remote_contract_ksort_deep( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$out = array();
	foreach ( $value as $k => $v ) {
		$out[ $k ] = sn_remote_contract_ksort_deep( $v );
	}
	if ( array_keys( $out ) !== range( 0, count( $out ) - 1 ) ) {
		ksort( $out );
	}
	return $out;
}
