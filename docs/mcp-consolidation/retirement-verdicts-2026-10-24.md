# Wave-4 retirement verdicts, 2026-10-24

The second read of the `wave4_telemetry` watch (due 2026-10-25), a month after
19.3.0 moved the nightly scheduled reads off the singles. It retires nothing; it
found why the singles stay in use and closed that.

## The evidence and its limits

30-day `sn_tool_call` rollup via `sn-site-facts{tool_telemetry}`, generated
2026-10-25T00:52Z: **31,274 calls**. As before, most are `direct`-door widget
plumbing (`get-deploy-status` 6,787, `uptime-status` 5,722, `anchor-status`
2,995), which no door retirement touches. The table counts the **read door**
only. Section counts are `by_dimension` (each section a call asked for).

| Single | Read-door calls | Replacement's use | Verdict |
|---|---|---|---|
| `get-deploy-status` | 111 | `sn-status{deploy}` 91 | Keep |
| `get-cron-history` | 51 | `sn-status{cron_history}` 15 | Keep |
| `anchor-status` | 46 | `sn-status{anchor}` 36 | Keep |
| `list-cron-events` | 44 | `sn-status{cron_scheduled}` 5 | Keep |
| `get-analytics-summary` | 32 (last 2026-10-13) | `sn-metrics{analytics_summary}` 47 | Keep |
| `get-health-scan` | 16 | `sn-status{health_scan}` 42 | Keep |
| `uptime-status` | 11 | `sn-status{uptime}` 46 | Keep |
| `provenance-integrity-status` | 8 | `sn-status{provenance_integrity}` 9 | Keep |
| `get-analytics-events` | 2 (last 2026-09-26) | `sn-metrics{analytics_events}` 5 | Already retired (19.3.0) |

`get-analytics-events`' two calls predate its retirement; they are the window's
tail, not use. Read `last_call` before calling a retired tool "in use".

## Why none collapsed

Not habit alone: input. `sn-status` could not pass the singles' own inputs, so a
caller that needed one fell back. The auditing session's own calls are part of
the counts: a forced deploy refresh after each install, a single hook's schedule.

| Single | Input sn-status lacked |
|---|---|
| `get-deploy-status` | `force_refresh` |
| `uptime-status` | `force_refresh`, `detail` |
| `list-cron-events` | `hook`, `sn_only` |
| `get-cron-history` | `limit` |

`get-analytics-summary`, `anchor-status`, `get-health-scan` and
`provenance-integrity-status` had no such gap (`range` and `class` were already on
`sn-metrics`); their remaining calls are habit.

## What shipped (#2275)

- `sn-status` takes those inputs, each forwarded only to the source whose schema
  declares it and only when set; a bare request dispatches as before.
- No allowlist change.
- The watch re-dated to **2026-11-25**, so the next read covers a month in which
  every single is fully covered by a section.
