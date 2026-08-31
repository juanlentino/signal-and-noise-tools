<?php
/**
 * Signal & Noise — MCP server: capabilities (allowlist SoT ×2, door identity,
 * protocol version, server identity). Pure data; no side effects. Sub-project
 * B of the machine-readability program (see
 * docs/superpowers/specs/2026-07-11-*B-mcp-*), widened in v9.50.0 to a second
 * door (see inc/mcp/mcp-endpoint.php).
 *
 * @package SignalNoiseTools
 * @since 9.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_MCP_PROTOCOL_VERSION' ) ) {
	// Fallback MCP protocol revision. The endpoint echoes the client's requested
	// version when we support it (sn_mcp_negotiate_version), so this is only used
	// when the client asks for something we don't recognize.
	define( 'SN_MCP_PROTOCOL_VERSION', '2025-06-18' );
}

// Door identifiers. The door is per-request CONTEXT — a parameter threaded
// through allowlist resolution, tool projection, and the call gate — never a
// mutable global. Every sn_mcp_* function that behaves differently per door
// takes $door as its last argument, defaulting to the read door (the door
// nothing existed before v9.50.0 could reach).
if ( ! defined( 'SN_MCP_DOOR_READ' ) ) {
	define( 'SN_MCP_DOOR_READ', 'read' );
}
if ( ! defined( 'SN_MCP_DOOR_RW' ) ) {
	define( 'SN_MCP_DOOR_RW', 'rw' );
}

/**
 * The read-door allowlist: ability slugs exposed as read-only MCP tools. This
 * is a security gate — tools/list advertises exactly these and tools/call
 * rejects anything not here (per sn_mcp_is_allowed, for the read door).
 * Cross-namespace (plugin + theme) resolves through the one global
 * WP_Abilities_Registry. Widened 15 → 23 in v9.50.0 (docs/ai-abilities-catalog
 * audit), 23 → 25 in v9.82.0 (anchor-status, provenance-integrity-status),
 * 25 → 28 in v10.6.0 (corpus inspection trio), 28 → 29 in v10.16.0
 * (near-duplicate-scan), 29 → 31 in v10.17.0 (keyword-candidates,
 * link-candidates), 31 → 32 in v10.21.0 (topic-clusters), 32 → 33 in
 * v10.22.0 (cadence-flags), 33 → 35 in v10.26.0 (sn-posts, sn-site-facts
 * — the first two CONSOLIDATED tools, new alongside every ability they
 * absorb; nothing below was unregistered), and 35 → 36 in v10.29.0
 * (sn-scan — the third CONSOLIDATED tool, absorbing block-migrations-scan,
 * pattern-adoption-scan, duplicate-body-scan, near-duplicate-scan, and
 * link-candidates; again new alongside old, nothing unregistered), and
 * 36 → 37 in v10.30.0 (sn-validate — the fourth CONSOLIDATED tool,
 * deterministic model-free validation of proposed content; zero writes,
 * zero model calls), and 37 → 38 in v10.69.0 (ai-cache-probe-status — the
 * prompt-cache probe verdict, previously readable only on the Insights
 * admin page).
 * the read-only-by-construction guarantee is unchanged — every slug here is
 * PURE-READ or READ-REMOTE by curation, never a write/action/AI-billed
 * ability (those live on the rw door only, see sn_mcp_rw_allowlist).
 *
 * @return string[]
 */
function sn_mcp_allowlist() {
	$slugs = array(
		// ── v11.34.0 — RETIRED FROM THE DOOR (tier A) ──────────────────────
		// Removed here, NOT deleted: the ability still exists, still answers
		// wp_get_ability()->execute(), still serves its internal callers and
		// stays REST-reachable behind its own permission_callback. This list
		// gates the MCP DOOR only, so retirement is reversible by one line.
		//
		// Each was absorbed by a consolidated tool AND had ZERO calls across
		// the 30-day telemetry window (1,855 calls total, table_present:true):
		//   get-latest-theme-tag        -> sn-site-facts{latest_theme_tag}
		//   list-block-patterns         -> sn-site-facts{block_patterns}
		//   get-seo-route-meta          -> sn-site-facts{seo_route_meta}
		//   link-candidates             -> sn-scan{link_candidates}
		//   get-design-system-summary   -> design_tokens (sn-site-facts' own
		//     description already called this one "retired, not absorbed" while
		//     it sat in this list — a contradiction, now closed)
		// ── v13.0.0 — WAVE 2 RETIRED FROM THE DOOR ─────────────────────────
		// Same contract as wave 1 (v12.0.0): removed here, NOT deleted — every
		// ability stays registered, still answers wp_get_ability()->execute(),
		// still serves its internal callers and stays REST-reachable behind its
		// own permission_callback. Verdicts + evidence:
		// docs/mcp-consolidation/retirement-verdicts-2026-08-25.md.
		//
		// Absorbed, absorber live and proven:
		//   pattern-adoption-scan -> sn-scan{pattern_adoption}
		//   list-posts            -> sn-posts
		//   get-post-content      -> sn-posts (content is an opt-in field)
		// Retired outright (spec'd "retired, not absorbed" since day one;
		// the weekly-digest prompt was rewritten in the same release so the
		// door no longer hands out a recipe for two not_founds):
		//   get-insights, get-narration
		// Plugin (signal-noise/) — operational reads.
		'signal-noise/get-health-scan',
		'signal-noise/uptime-status',
		'signal-noise/get-deploy-status',
		'signal-noise/get-analytics-summary',
		'signal-noise/get-rss-stats',
		'signal-noise/get-cron-history',
		'signal-noise/list-cron-events',
		'signal-noise/get-analytics-events',
		// v12.11.0 — the IPv6 criterion gauge, previously wp-admin only. The
		// criterion was pre-committed so the NUMBER triggers the call; a
		// number nobody can query triggers nothing. LOCAL door only —
		// login-defense telemetry is not the analytics-only remote slice.
		'signal-noise/login-defense-ipv6-criterion',
		// v9.82.0 — operational status. Both readonly, both sub-second, and what
		// they return is status an agent should be able to see for itself.
		'signal-noise/anchor-status',
		'signal-noise/provenance-integrity-status',
		// v10.6.0 — corpus inspection: list-posts + get-post-content moved to
		// the wave-2 retirement block above (absorbed by sn-posts).
		// v10.16.0 (2026-07-30) — near-duplicate cousin scan: PURE-READ by
		// construction (kernel cosine over the same corpus walk, no writes);
		// spans non-public statuses for the same pre-publish reason as the
		// trio above. Read door 28 → 29.
		// v10.17.0 (2026-07-30) — deterministic candidate generators: PURE-READ
		// by construction (inc/ml-candidates.php never writes — the kernel
		// proposes, a person decides); keyword ranking spans non-public
		// statuses for the same pre-publish reason as the trio above.
		// Read door 29 → 31.
		'signal-noise/keyword-candidates',
		'signal-noise/topic-clusters', // v10.21.0: the stored topic partition (ML pipeline #4).
		// v13.45.0. Had NO recorded verdict anywhere — not retired, not
		// absorbed, never accounted for; the completeness sweep of 2026-08-30
		// found it unreachable from either door by omission rather than by
		// decision. read_corpus and readonly, so it joins its two siblings here.
		//
		// A SINGLE, deliberately, and NOT an sn-scan type: sn-scan takes a SCOPE
		// and walks the corpus, while this REQUIRES post_id and scores ONE draft
		// against everything else — "the same kernel as near-duplicate-scan,
		// asked from the other direction", and near_duplicate already is that
		// walk. A single-target query given a scope it cannot honour would
		// misreport what it does.
		'signal-noise/draft-echoes', // v10.17.0: one draft scored against the corpus.
		'signal-noise/cadence-flags',  // v10.22.0: publish + cron rhythm deviations (ML pipeline #5).
		// Theme (signal-and-noise/) — identity + design system.
		// v10.26.0 — MCP consolidation, phase 2: the first two CONSOLIDATED
		// tools, registered NEW alongside every ability they absorb (nothing
		// above this line was touched). Both PURE-READ by construction:
		// sn-posts reuses inc/corpus-inspect.php's own primitives verbatim;
		// sn-site-facts only dispatches to already-doored read abilities
		// (9 theme + list-template-overrides), never writes.
		'signal-noise/sn-posts',
		'signal-noise/sn-site-facts',
		// v10.29.0 — MCP consolidation, phase 3: the third CONSOLIDATED tool.
		// PURE-READ by construction: every adapter behind sn-scan calls the
		// same internal functions the five absorbed scan abilities call
		// (never wp_get_ability()), plus a sixth source (orphan_media,
		// wrapping the pure-SQL sn_health_check_orphaned_media(), never the
		// AI-gated ai-orphan-suggest path). Writes nothing, ever.
		'signal-noise/sn-scan',
		// v10.30.0 — MCP consolidation, phase 4: the fourth CONSOLIDATED tool.
		// PURE-READ by construction: every check in inc/sn-validate-checks.php
		// and inc/sn-validate-checks-media.php is a read + compute, never a
		// write, and never a model call (structurally pinned — see
		// tests/abilities-sn-validate.php acceptance test 6).
		'signal-noise/sn-validate',
		// v10.69.0 — prompt-cache probe verdict. PURE-READ by construction: the
		// probe itself returns $response untouched on every path, and this reads
		// the option it already wrote. Doored because the question it answers
		// ("would caching pay, and on which model?") is one an agent proposing an
		// AI change should be able to settle for itself instead of asking a human
		// to open wp-admin. Read door 37 → 38.
		'signal-noise/ai-cache-probe-status',
		// v13.1.0 — read-door coherence (owner-reopened consolidation, see the
		// wave-3 decision amendment in retirement-verdicts-2026-08-25.md). Two
		// SECTIONED-BATCH readouts on the sn-site-facts pattern — each answers
		// one coherent question, each section keeps its source's exact shape.
		// PURE-READ by construction: both only dispatch to already-registered
		// read abilities via snt_sn_site_facts_dispatch(). NEW ALONGSIDE OLD:
		// the thirteen absorbed singles above stay doored until a telemetry
		// window justifies a wave-4 retirement. Read door 19 → 21.
		'signal-noise/sn-status',
		'signal-noise/sn-metrics',
	);

	/**
	 * Filter the read-door MCP tool allowlist. Callbacks MUST return slugs of
	 * abilities that are safe to expose read-only over an admin-gated endpoint.
	 *
	 * @param string[] $slugs
	 */
	return (array) apply_filters( 'sn_mcp_allowlist', $slugs );
}

/**
 * The rw-door allowlist: the owner-approved safe set of write/action/AI-billed
 * abilities exposed over POST /mcp-rw (v9.50.0). Recounted directly against
 * `abilities-audit-2026-07-15.md`: every ability the audit recommends RW-DOOR,
 * MINUS the three the owner holds back (destructive, later explicit opt-in —
 * never `run-cron-event`, which the audit itself excludes from every MCP
 * surface: unbounded do_action() on any non-sn_* hook), PLUS the two
 * audit-log reads the owner chose to gate behind the rw door instead of the
 * read door (plaintext usernames — see the audit's PII flags on
 * get-audit-log/export-audit-log). Widened to 35 in v9.82.0 by anchor-sweep
 * (30 plugin + 5 theme); the read-door 25 are never duplicated in here — a
 * client wanting reads uses the read door. Widened 35 -> 36 in v10.40.0 by
 * sn-apply (MCP consolidation session 6b) — the fifth CONSOLIDATED tool,
 * registered NEW alongside every ability it absorbs (block-migrations-apply,
 * pattern-adoption-apply, ai-alt-apply, ai-drift-apply, ai-link-apply,
 * update-post-surfaces, regenerate-og-card, anchor-sweep — none of which
 * were touched, unregistered, or deleted).
 *
 * Held OUT on purpose (present on neither door — verify with
 * sn_mcp_is_allowed before ever touching this list):
 *   - signal-noise/run-cron-event        — unbounded do_action() dispatch.
 *     PRECISION, v13.49.0: "unbounded" is now narrower than it reads.
 *     snt_cron_run_event_impl() already refuses a hook with no
 *     `has_action()` registration, and the ability already refuses every
 *     `sn_*` internal. What stays genuinely unbounded is that `has_action()`
 *     admits ANY registered action, not only a scheduled cron event — so the
 *     hazard is dispatch of arbitrary registered hooks, not of arbitrary
 *     strings. The exclusion stands; the reason is the accurate one.
 *     AND IT IS NOW LOAD-BEARING IN A SECOND WAY (v13.49.0): the `sn-apply`
 *     change type `schedule_cron_event` BOOKS a run of an SN-owned hook and
 *     returns, without dispatching anything. Dispatch is the hazard here and
 *     booking is not, which is exactly why one is reachable and this stays
 *     held. Do not "unify" them.
 *   - signal-noise/ai-orphan-apply       — permanent delete, skips trash, no undo.
 *   - signal-noise/merge-tags            — sitewide term reassign + delete.
 *   - signal-noise/clear-template-overrides — wipes Site Editor template rows.
 *
 *     STILL TRUE OF THE DOOR, NO LONGER TRUE OF THE CAPABILITY (v13.49.0):
 *     neither slug is doored, and neither should be. But both are now reachable
 *     as `sn-apply` change types (`merge_tags`, `clear_template_overrides`), so
 *     "held out" describes the TOOL and not the reach. Both exclusion reasons
 *     above were written against a doored tool — a bare slug with no dry run, no
 *     gates and no audit row. As a change type each inherits `dry_run:true`,
 *     the four gates, idempotency and the rw audit trail, and both abilities are
 *     `manage_options`, the same tier `sn-apply` already carries, so no
 *     permission boundary moved. Same capability, different risk object. Read
 *     this list as "never a doored tool", never as "unreachable".
 *   - signal-noise/run-health-scan       — too slow to survive the wire. The MCP
 *     layer dispatches synchronously with no timeout and no execution budget;
 *     the scan takes roughly 35s today and up to ~105s when something is
 *     actually down, and Cloudflare's ~100s edge cap sits in front of it. Doored,
 *     it would hand an agent a tool that hangs and then dies at the edge with
 *     nothing to show for the wait. The results stay reachable through the
 *     doored read ability get-health-scan, so an agent can still read the
 *     verdict; it just cannot cause one.
 *
 *     CORRECTED 2026-08-23: this used to close with "and the scan runs on cron
 *     whether or not anyone asks". It does not. Nothing schedules it — there is
 *     no wp_schedule_event for health anywhere in inc/, and sn_health_run_scan()
 *     has exactly two live callers: this ability, and sn_handle_health_scan()
 *     behind the wp-admin "Run scan" button. So the scan runs when a human
 *     clicks, and at no other time; a reading was found 18 hours stale, and
 *     would have gone on ageing indefinitely if nobody had clicked.
 *
 *     That clause was load-bearing — it was half the reason holding the ability
 *     back cost nothing — so it is corrected rather than deleted. The exclusion
 *     itself still stands on its own: the wire really cannot carry a 35-105s
 *     synchronous dispatch behind a ~100s edge cap. What does NOT follow from it
 *     is that the scan happens anyway.
 *
 * @return string[]
 */
function sn_mcp_rw_allowlist() {
	$slugs = array(
		// ── v11.34.0 — RETIRED FROM THE DOOR (tiers B and C) ──────────────
		// Tier B: absorbed, with a trickle of calls the consolidated tool now
		// serves — ai-link-apply's siblings block-migrations-apply and the
		// theme fact tools, listed on sn_mcp_allowlist().
		//
		// Tier C, ABSORBED (2):
		//   ai-alt-apply    -> sn-apply{change.type:alt_text}
		//   ai-drift-apply  -> sn-apply{change.type:drift_replace}
		//
		// Tier C, NOT ABSORBED (19) — owner decision 2026-08-19, taken with the
		// consequence stated. These lose their MCP path and gain no equivalent:
		// the AI GENERATION tools (alt/drift/orphan/link suggestions, excerpt,
		// meta description, og-card title, page-note summary, block-pattern
		// suggestion + content, brand-voice rewrite + validation, suggest-tags),
		// the audit-log trio, and the two dismissal tools.
		//
		// Their zero call count is NOT evidence they are unused: this telemetry
		// counts MCP doors only, and these have live non-MCP callers —
		// inc/ai-excerpt.php, inc/ai-drift-phrase-suggest.php, sn-validate's own
		// checks, and the wp-admin AI surfaces. Every one remains registered,
		// callable via wp_get_ability()->execute(), and REST-reachable behind
		// its own permission_callback. This list gates the MCP DOOR only.
		//
		// WATCH: dismiss-candidate backed sn-scan's `dismissed` flow, and
		// ai-alt-apply was the only application path for alt-text staged rows
		// (sn-apply's own docs call those rows "stranded"). If either is missed,
		// re-adding one line here restores it.
		// ── v11.34.0 — RETIRED FROM THE DOOR (tier A) ──────────────────────
		// Absorbed AND zero calls in the 30-day telemetry window. Removed from
		// the door, not deleted — see the note on sn_mcp_allowlist().
		//   duplicate-body-scan       -> sn-scan{duplicate_body}
		//   pattern-adoption-suggest  -> sn-scan{pattern_adoption}
		//   pattern-adoption-apply    -> sn-apply{change.type:pattern_adoption}
		//   anchor-sweep              -> sn-apply{change.type:anchor_sweep}
		//   regenerate-og-card        -> sn-apply{change.type:og_card}
		// ── v13.0.0 — WAVE 2 RETIRED FROM THE DOOR ─────────────────────────
		// Same contract as wave 1: door-only removal, nothing deleted.
		// Verdicts: docs/mcp-consolidation/retirement-verdicts-2026-08-25.md.
		//   block-migrations-suggest -> sn-scan{block_migrations} +
		//     sn-apply{block_migration} (44% of its 219 windowed calls were
		//     schema_error/refused — the consolidated path carries the
		//     fingerprints this one kept fumbling)
		//   update-post-surfaces     -> sn-apply{change.type:surfaces}
		//   run-insights-scan, run-narration — retired outright with their
		//     read siblings (spec'd so since day one)
		//
		// KEPT ON PURPOSE — ai-pair-suggest + ai-link-apply. The spec's
		// mapping calls them absorbed; the shipped code disproves it: sn-scan
		// deliberately emits apply_hint:null for link_candidates because
		// ai-link-apply validates a positional fingerprint only the
		// AI-mediated suggest can produce. No sn-apply bridge exists, so
		// retiring the pair would DELETE the AI link flow over MCP, not
		// consolidate it. Code outranks the mapping table.
		'signal-noise/ai-link-apply',
		'signal-noise/ai-pair-suggest',
		'signal-noise/prune-unused-tags',
		// v13.25.0 — the tag-vocabulary pair beside prune-unused-tags: AI-billed
		// returns-only drafting + an only-if-empty write (never clobbers an
		// owner edit; replays answer skipped_nonempty). Owner-directed the day
		// the seed (v13.23.0) and tag_hygiene (v13.24.0) shipped.
		'signal-noise/describe-tags',
		'signal-noise/apply-tag-description',
		'signal-noise/unschedule-cron-event',
		'signal-noise/purge-all-caches',
		// v9.82.0 — not readonly, but idempotent: one bounded wp_remote_post
		// (timeout 20) asking the provenance Worker to upgrade already-confirmed
		// proofs. The rw door's kill switch, app-password binding, rate limit,
		// and audit trail are exactly the envelope this wants.
		// v10.40.0 — MCP consolidation session 6b: the consolidated write
		// tool. Four ability-level gates (fingerprint/validation/capability/
		// idempotency) on TOP of this door's existing hardening — see
		// inc/abilities-sn-apply.php.
		'signal-noise/sn-apply',
		// Theme (signal-and-noise/) — 5, all AI-billed + return-only.
	);

	/**
	 * Filter the rw-door MCP tool allowlist. Callbacks MUST NOT add
	 * run-cron-event or the three held-back destructive/blast-radius slugs
	 * documented on sn_mcp_rw_allowlist().
	 *
	 * @param string[] $slugs
	 */
	return (array) apply_filters( 'sn_mcp_rw_allowlist', $slugs );
}

/**
 * Resolve the allowlist for a door. The one place a door identifier turns
 * into a concrete slug list — every per-door consumer (tools/list, tools/call,
 * and the future resources/prompts handlers) calls through here rather than
 * branching on the door itself.
 *
 * @param string $door SN_MCP_DOOR_READ or SN_MCP_DOOR_RW.
 * @return string[]
 */
function sn_mcp_allowlist_for_door( $door ) {
	return SN_MCP_DOOR_RW === $door ? sn_mcp_rw_allowlist() : sn_mcp_allowlist();
}

/**
 * Is a slug exposed over MCP on the given door? Gates tools/call, not just
 * tools/list — a slug excluded from both allowlists (the held trio,
 * run-cron-event) is unknown regardless of which door asks, and an rw-only
 * slug is unknown on the read door even if named directly.
 *
 * @param string $slug
 * @param string $door SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return bool
 */
function sn_mcp_is_allowed( $slug, $door = SN_MCP_DOOR_READ ) {
	return in_array( (string) $slug, sn_mcp_allowlist_for_door( $door ), true );
}

/**
 * Server identity for the initialize response. The rw door's name is
 * distinguished (spec: "…read-write") so a client juggling both connections
 * can tell them apart in its own UI.
 *
 * @param string $door SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return array{name:string,version:string}
 */
function sn_mcp_server_info( $door = SN_MCP_DOOR_READ ) {
	// Branded base defaults to the site title, falling back to a fixed brand
	// when the title is blank; filterable via 'sn_mcp_server_label' so an owner
	// can rename BOTH doors at once without editing this file. Each door then
	// carries an explicit "(Read)" / "(Write)" label so a client that shows the
	// serverInfo name (rather than the connection's own key) distinguishes them.
	$site = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
	$base = '' !== $site ? $site : 'Signal & Noise';
	if ( function_exists( 'apply_filters' ) ) {
		$base = (string) apply_filters( 'sn_mcp_server_label', $base, $door );
	}
	$name = $base . ( SN_MCP_DOOR_RW === $door ? ' (Write)' : ' (Read)' );
	return array(
		'name'    => $name,
		'version' => defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '',
	);
}

/**
 * The capability map advertised at initialize AND published in the MCP Server
 * Card (/.well-known/mcp/server-card.json, inc/agent-discovery.php).
 *
 * ONE declaration, two readers — the same discipline as POLICY_VERSION in the
 * rights Worker. A server card that advertises a capability the handshake does
 * not return is worse than no card: a client provisions against the card,
 * connects, and finds the capability missing. Keeping the map here makes that
 * drift impossible to write, and tests/agent-discovery.php pins the two
 * against each other.
 *
 * `listChanged` is false throughout: neither door emits list-changed
 * notifications, and advertising a notification we never send is the same
 * class of lie.
 *
 * @return array<string,array<string,bool>>
 */
function sn_mcp_capabilities_map() {
	return array(
		'tools'     => array( 'listChanged' => false ),
		'resources' => array( 'listChanged' => false ),
		'prompts'   => array( 'listChanged' => false ),
	);
}

/**
 * Negotiate the protocol version: echo the client's requested revision when we
 * recognize it, else our pinned default. Makes us robust to spec revisions
 * without a code change.
 *
 * @param string $requested
 * @return string
 */
function sn_mcp_negotiate_version( $requested ) {
	$supported = array( '2025-06-18', '2025-03-26', '2024-11-05' );
	return in_array( (string) $requested, $supported, true ) ? (string) $requested : SN_MCP_PROTOCOL_VERSION;
}
