# Wave-2 retirement verdicts — 2026-08-25

Evidence pass over everything still on the MCP doors after v12.0.0 ("the
consolidation collects", #745, 2026-08-19) executed wave 1: 74 tools → 33.
This sheet is the wave-2 input the program's phases 6–12 now need: a six-day
stumble review of wave 1, a retire/keep verdict per remaining doored tool, and
a build-or-descope call on the six consolidated tools that were never built.
**Read-only: nothing here ships until the owner picks a wave-2 scope.**

## The evidence and its honest limits

30-day `sn_tool_call` rollup read 2026-08-25T18:06Z via `sn-site-facts
{tool_telemetry}`: **12,995 calls**. Three caveats before any number below:

1. **11,630 of those are `direct`-door plumbing** — `get-deploy-status`
   (6,789), `uptime-status` (3,426), `get-rss-stats` (1,415) called
   in-process by dashboard widgets. They are not MCP-client traffic and no
   door retirement touches them. MCP-door traffic is ~1,300 calls.
2. **The table cannot predate v10.25.0 (tagged 2026-07-31)**, so "30 days"
   holds at most ~25 measured days. Nominal ≠ measured.
3. **Proxy `-32602` refusals are invisible to every telemetry layer** (known
   since v10.60.0), so a client failing against a retired tool through the
   desktop proxy may leave no row. Door-level refusals DO log (see the
   stumble below), proxy-level ones do not.

Wave-1 verdicts used the pre-08-19 window (1,855 calls). This window spans
the retirement itself: pre-08-19 rows describe the OLD surface.

## Stumble review — six days post wave 1

The Cherny rule: add back only what stumbles twice. Observed stumbles since
2026-08-19: **one**. `signal-and-noise/get-theme-version` → `not_found`,
door `read`, 2026-08-20 04:58 — a client reached for a tier-B tool the day
after it left. Once, never repeated; `sn-site-facts{theme_version}` serves
it. **No re-adds are justified.**

Two WATCH items from the wave-1 code comment, checked:
- `dismiss-candidate` backed sn-scan's dismissed flow → no stumble visible,
  but see the sn_dismiss decision below: MCP clients currently have NO
  dismissal path at all.
- `alt_text` stranded rows → no attempted calls; still stranded, still
  honest.

## Wave-2 verdicts — retire (absorbed, absorber live and proven)

| Tool (door) | 30d calls | Absorber | Note |
|---|---|---|---|
| `block-migrations-suggest` (rw) | 219 — **44% schema_error/refused**, none since 08-14 | `sn-scan{block_migrations}` → `sn-apply{block_migration}` | The error rate is itself the argument: the consolidated path carries fingerprints correctly |
| `update-post-surfaces` (rw) | 20, last 08-20 | `sn-apply{surfaces}` (in `SNT_SN_APPLY_CHANGE_TYPES`, [sn-apply-executors.php:53](../../inc/sn-apply-executors.php)) | Traffic exists but the absorber is the same write behind four gates |
| `pattern-adoption-scan` (read) | 1, on 08-05 | `sn-scan{pattern_adoption}` | Wave-1 leftover; its suggest/apply siblings already retired |
| `list-posts` (read) | 41, last 08-23 | `sn-posts` (77 calls served, content as opt-in field) | Tier-B shape: real trickle, absorber proven since v10.26.0 |
| `get-post-content` (read) | 72, last 08-23 | `sn-posts` | Same; the pair retires together or not at all |
| `get-insights`, `get-narration` (read) + `run-insights-scan`, `run-narration` (rw) | 2+5+1+1 | none — spec'd "retired, not absorbed" from day one | **Blocker first:** the weekly-digest MCP prompt ([mcp-prompts.php:58](../../inc/mcp/mcp-prompts.php)) instructs clients to call `get-narration` and `get-insights`. Rewrite the prompt in the same release or the door hands out a recipe for two `not_found`s |

## Wave-2 verdicts — keep (the mapping said absorbed; the code says not)

**`ai-pair-suggest` + `ai-link-apply` (rw, 11 + 3 calls).** The spec mapped
them into `sn-scan`/`sn-apply`, but the shipped code contradicts the mapping:
sn-scan's own adapter deliberately emits `apply_hint: null` for
`link_candidates` because `ai-link-apply` validates a positional fingerprint
**only an AI-mediated suggest call can produce** — there is no
`sn-apply` path that accepts a scan candidate for link insertion with that
fingerprint contract. Retiring the pair would delete the AI link flow over
MCP, not consolidate it. Keep both until someone builds the fingerprint
bridge; the code evidence outranks the mapping table.

**No-absorber keeps (nothing to decide):** `keyword-candidates` (3),
`topic-clusters` (9), `cadence-flags` (5), `login-defense-ipv6-criterion`,
`ai-cache-probe-status` (6), `anchor-status` (172 total), 
`provenance-integrity-status` (10), `get-health-scan` (27), and the ops/
analytics reads pending the build-or-descope calls below.

## Build or descope — the six unbuilt consolidated tools

| Tool | Would absorb | Verdict |
|---|---|---|
| `sn_terms` | `keyword-candidates` only — `suggest-tags` was tier-C retired | **Descope.** A 1:1 wrapper consolidates nothing |
| `sn_dismiss` | dismissal (both legacy dismiss tools tier-C retired) | **Defer, but tracked as a gating dependency:** since wave 1, no MCP client can dismiss a candidate (wp-admin only); `include_dismissed` on sn-scan is inert for 4 of 8 types. Phases 10–11 (unattended routines) NEED dismissal or the routine spams. Build it when phase 10 starts, not before |
| `sn_health` | `uptime-status`, `get-deploy-status`, `provenance-integrity-status`, `anchor-status`, `get-health-scan` (MCP-door: ~110 calls) | **Owner call.** Payoff is context width (5 slots → 1), not behavior. If the 33→11 target still stands, build; if the plateau at 25 after this wave is acceptable, descope |
| `sn_metrics` | `get-analytics-summary` (7), `-events` (3), `get-rss-stats` (4) | Same call as `sn_health`, weaker (14 MCP-door calls total) |
| `sn_events` | `get-cron-history` (12), `list-cron-events` (19); audit-log pair already tier-C'd | Same call |
| `sn_danger` | `prune-unused-tags` (1), `purge-all-caches` (59 rw + 10 direct), `unschedule-cron-event` (4) | Same call; `purge-all-caches` is the only workhorse and already lives behind the rw envelope |

Descope-everything leaves the doors at **19 read + 6 rw** after wave 2 —
short of the spec's 11, and honestly fine: the spec's own merge rule
("merge by return shape, never by topic") is strained by `sn_health`'s five
disjoint shapes. The 11 was a target, not a contract.

## Defects found by this pass (fix with wave 2)

1. **sn-scan's doored description is stale** ([abilities-sn-scan.php:75](../../inc/abilities-sn-scan.php)):
   it still tells clients apply hints name `block-migrations-apply`,
   `pattern-adoption-apply`, `ai-orphan-apply` — all off the doors since
   wave 1 — while the CODE correctly emits `signal-noise/sn-apply` / null.
   An agent-facing description contradicting the tool's own output is the
   v11.13.0 dead-pointer failure in prose form.
2. **The weekly-digest prompt** names two tools this sheet retires (see
   table above) — same release, or the retirement waits.

## Recommended wave-2 release (one PR, minor-with-breaking → major per house rules)

Retire from doors: `block-migrations-suggest`, `update-post-surfaces`,
`pattern-adoption-scan`, `list-posts`, `get-post-content`, plus the
insights/narration quartet WITH the prompt rewrite. Keep the ai-link pair.
Fix the sn-scan description. Extend the wave-1 invariant test (retired slug
absent from both doors AND absorber present) to the new set. Doors land at
**19 read + 6 rw = 25**.

Wave 3, if ever, is the sn_health/sn_metrics/sn_events build — decide it
when the surface width actually hurts, and re-read telemetry then rather
than trusting this sheet's window.

---

## Wave-3 decision — DESCOPED, then SUPERSEDED same day (owner, 2026-08-25)

> **Amendment (later the same day):** the owner reopened the read-door
> consolidation with a coherence framing this section's descope did not
> weigh: the fourteen narrow reads answer fragments of two questions
> ("what is the site's operational state?" / "how is it being read?") that
> a caller must stitch across many calls. v13.1.0 builds `sn-status` and
> `sn-metrics` on the sn-site-facts SECTIONED-BATCH pattern — which is NOT
> the `sn_health` this section rejected: no shape-gluing, each section
> keeps its source's exact payload under its own key, so the
> merge-by-return-shape objection below does not apply to what was built.
> New-alongside-old; the thirteen absorbed singles retire in a wave 4 only
> after a telemetry window. `sn_terms` stays descoped, `sn_dismiss` stays
> deferred-gating-phase-10, `sn_danger` stays unbuilt. The paragraph below
> is preserved as the decision it was when taken.

Taken the same day wave 2 shipped as v13.0.0, on this sheet's evidence:

- **`sn_health`, `sn_metrics`, `sn_events`, `sn_danger`: NOT BUILT.** The
  doors stay at **19 read + 6 rw = 25**. The consolidation payoff at this
  point is context width only, the width is not hurting, and `sn_health`'s
  five disjoint return shapes strain the spec's own merge-by-return-shape
  rule. The spec's 11-tool figure was a target, not a contract.
- **`sn_terms`: descoped** (would wrap exactly one tool).
- **`sn_dismiss`: deferred, NOT descoped** — it gates phase 10: no MCP
  dismissal path has existed since wave 1, and an unattended routine
  without dismissal is a spam generator. Build it when phase 10 starts.
- **Revisit condition:** an agent workflow measurably degraded by tool-list
  width, or phase 10 starting (which forces `sn_dismiss` and reopens the
  question with fresh telemetry). Do not reopen on taste.

The consolidation SURFACE work (build-order phases 2–7) is complete.
Phases 8–12 (server-side gate enforcement, credential scopes, the
self-maintenance routine, the first unattended job, REST route deletion)
are a different kind of decision — each expands unattended agency or
deletes code, and each waits for an explicit owner start, not a telemetry
threshold.
