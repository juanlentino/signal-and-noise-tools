# Wave-4 retirement verdicts — 2026-09-27

The `wave4_telemetry` watch ripened on its date (2026-09-25). This sheet is the
read that answered it and what shipped in 19.3.0.

## The evidence and its honest limits

30-day `sn_tool_call` rollup read 2026-09-27T05:26Z via `sn-site-facts
{tool_telemetry}`: **76,409 calls**. Most are `direct`-door plumbing (dashboard
widgets calling abilities in-process: `get-deploy-status` 24,236, `cache-freshness`
19,405, `uptime-status` 12,246). No door retirement touches those; the widgets
keep calling the abilities.

The question is the **read door** (agents over MCP). Proxy `-32602` refusals are
still invisible to every layer, so a zero means no call reached the server.

## Read-door calls per absorbed single (30 days)

| Single | Read-door calls | Replacement's use | Verdict |
|---|---|---|---|
| `ai-cache-probe-status` | 0 | `sn-status{ai_cache_probe}` | **Retired** |
| `cadence-flags` | 1 | `sn-status{cadence}` | **Retired** |
| `get-rss-stats` | 1 | `sn-metrics{rss_stats}` | **Retired** |
| `get-analytics-events` | 2 | `sn-metrics{analytics_events}` | **Retired** |
| `get-deploy-status` | 75 | `sn-status{deploy}` 47 | Keep |
| `anchor-status` | 54 | `sn-status{anchor}` 6 | Keep |
| `get-health-scan` | 51 | `sn-status{health_scan}` 14 | Keep |
| `uptime-status` | 40 | `sn-status{uptime}` 5 | Keep |
| `get-analytics-summary` | 38 | `sn-metrics{analytics_summary}` 14 | Keep |
| `list-cron-events` / `get-cron-history` | 13 / 11 | `sn-status{cron_*}` 30 | Keep |
| `provenance-integrity-status` | 12 | `sn-status{provenance_integrity}` 6 | Keep |

## Why most stay

Agents had not migrated, the auditing session included. And part of the singles'
traffic was the site's own nightly run: `inc/scheduled-reads.php` called five
singles by name, so their counts measured a cron job, not usage. 19.3.0 moved that
run to `sn-status` / `sn-metrics`; 19.3.1 made it count a failed section as a failed
read (the consolidated tools degrade one source to `{error:"unavailable"}` inside a
successful call).

## What shipped (19.3.0)

- The four retired from the read door in `inc/mcp/mcp-capabilities.php`. Door-only,
  the same contract as waves 1 and 2: every ability stays registered and
  REST-reachable. Read door 51 → 47. `tests/mcp-capabilities.php` pins each retired
  slug absent from both doors and its absorber present.
- Tests that used a retired tool only as a generic doored vehicle moved to
  still-doored ones (`provenance-integrity-status`, `shape-stability`,
  `purge-verification-log`).
- The watch re-dated to **2026-10-25**, so the other eight are judged on a month
  without the nightly run inflating them.
