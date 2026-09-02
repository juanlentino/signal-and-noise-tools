# reader-anomalies — scope

**Status:** BUILT and shipped in v13.76.0. This document is the scope it was
built to; the CHANGELOG entry records what changed against it.
**Premise corrected twice while scoping; both corrections are load-bearing.**

## Why this subsystem

Machine readers is the only subsystem with **zero ML consumers** and by far the
most data. Measured 2026-09-02 over 30 days:

| | |
|---|---|
| machine requests | **69,833** (`total_exact: true`, not truncated) |
| human visits, same period | ~130 |
| ratio | roughly **500 : 1** |

The analytics ladder (descriptive → predictive → prescriptive, I1–I6) is complete
and sophisticated. It is also **starved**: Theil–Sen and Holt run over a few
visits a day. The same engine pointed at per-family crawler volume has real
signal. This proposal adds no new statistics; it supplies a denser input.

## Correction 1 — the day-grain already exists

I assumed the plugin would need a new rollup, or the worker a new dimension.
Neither. The sensor contract is already per-day:

```
GET /_sn/rights-signals/machine-readers?days=N
200 { worker, days, data: [{ family, surface, day, hits }] }   # days clamped 1..90
```

`snt_mr_fetch()` already pulls this (15-minute display transient) and
`snt_mr_normalize_rows()` already allowlist-coerces family/surface and validates
`day` as `YYYY-MM-DD`. The dashboard aggregates the grain away; nothing else does.

**Consequences:** no worker change, no new storage, no contract bump for the data
path, and no new queries — which is the analytics engine's standing rule
(engagement forecasts are deferred for exactly this reason). Up to 90 days of
history is available on demand today.

## Correction 2 — what the skill gate actually catches

v13.75.0 added `skill = 1 - mae/mae_naive` and withholds a forecast at
`skill <= 0`. I built it expecting it to fire on thin data. **Measured: it does
not.** Holt scored **+0.06** on realistic thin noisy traffic and was not
suppressed — correct, because persistence chases noise while a smoothed level
tracks the mean. It fires on **structural misfit**: a reversed trend (-0.19), a
sawtooth cycle (-0.13).

That matters here because crawler series are exactly where reversals and cycles
live. The gate is what decides which families **earn** a forecast, per family,
on measured evidence.

## The build

### 1. `snt_mr_daily_series( $rows, $family, $from, $to )` — pure

Reshape normalized rows into the series shape the composers already take:
`[ { views: int }, … ]`, ordered by day.

**Zero-fill is load-bearing, not tidiness.** A day with no hits is a real zero,
not a gap. Without zero-fill a crawler that stops simply produces a shorter
series, and "went quiet" — the most interesting reading on this data, and the
site's own thesis — becomes invisible. The fill is bounded by `$from`/`$to`, so
it can never invent history before the window.

### 2. Three tiers, two already injectable

- `sn_analytics_trajectory_of( subject, label, series, from, to, min_points )` — **injectable today**
- `sn_analytics_forecast_of( subject, label, series, from, to, opts )` — **injectable today**
- `sn_analytics_signal_anomalies( from, to, class, opts )` — **NOT injectable**: it
  queries the local analytics tables and is coupled to `$class`.

So one small addition: `sn_analytics_anomaly_of( subject, label, series, from, to, opts )`,
a series-injectable sibling mirroring `trajectory_of`. That makes all three tiers
uniformly injectable and is reusable beyond this pipeline.

**Two-sided, unlike `snt_ml_views_rhythm`.** That one is deliberately one-sided —
a busy week is reach, not deviation. For crawlers the opposite holds: a reader
going quiet is the signal. Stated here because the divergence from the sibling is
a decision, not an oversight.

### 3. Surface mix as a second dimension

Feed each day's surface hits to `snt_ml_corpus_drift( before, after, min_docs, top )`
treating surfaces as tokens. It already returns `risen / fallen / entered /
silenced` with a `verdict: 'ok'|'thin'` and the bucket sizes, "so the caller can
render WHY it refused."

This is what surfaces *"GPTBot began fetching robots.txt"* (`entered`) or
*"stopped fetching the feed"* (`silenced`) without writing a new detector.

### 4. Evidence gates, all inherited

- `>= 7d` span before any statistic is trusted (v10.32.0 rule 4 — a fixed-COUNT
  window needs a wall-clock gate).
- `corpus_drift`'s `min_docs` floor per period.
- The skill gate decides forecasts per family.
- Families below a minimum daily volume report `thin`, never a flag.

## Surfaces — deliberately not only the dashboard

| surface | what lands there |
|---|---|
| **Site Health** direct test | one check, pattern of `sn_family_drift` / `sn_hibp_breach_check`; status + summary sentence |
| **MCP read door** | `signal-noise/reader-anomalies` (read-only, allowlist 42 → 43) |
| **MCP remote twin** | *optional, owner call* — see below |
| **`[sn_machine_maturity]`** | a scope row for behavioural anomaly detection; badge flips planned → live |
| **`[sn_ml_maturity]`** | the pipeline joins the consumer list (11th) |
| **narrator / digest** | the `sn_analytics_narrator` seam already turns signals into prose; reader signals ride it with deterministic floors |

### On the remote twin

`sn_mcp_remote_verdicts()` is the map, and it already carries a documented
refusal (`anchor` → RULED LOCAL, with the reason). A reader-anomalies twin looks
parity-safe — machine aggregates over public-facing traffic, no post titles, no
reader data, and `machine_readers` is already remote-approved.

But a twin is **contract 4 → 5 plus a worker release**, and the byte-identical
rule means the payload shape freezes on the day it ships. Recommend: ship local
first, add the twin only once the payload has settled.

## Three nevers, checked

1. **Never in provenance verdicts.** Nothing here touches verification. The
   standing policy explicitly permits "sweep-pattern anomalies" as a flag AROUND
   verdicts; this is that.
2. **Never profiles readers.** Machines only, family-level aggregates, no IP, no
   session, no per-visitor anything.
3. **No model in the browser.** Server-computed; surfaces render results.

## Out of scope, deliberately

- **The frozen family enum stays untouched.** A published figure (77 AI-training
  reads, 30d to 2026-07-31, note 2071) depends on what those values meant.
- **`unclassified-machine` is not investigated.** It is already identified on the
  vendor/purpose axis and carries no UA sample by construction. Treating it as a
  mystery bucket is reading the least informative axis as the diagnostic one.
- **No agent-level detection initially.** Family-level first; agent grain only if
  the family signal proves useful.
- **No auto-action.** Flags only. The owner decides.

## Settled: window and eligibility floor

**Window: 30 days**, matching the dashboard.

**Eligibility: a family needs hits on >= 20 of the 30 days.** Derived from live
data (2026-09-02, `snt_mr_fetch(30)` over the full grain), not chosen.

Days present, sorted: **2, 9, 10, 11, 14 ... 23, 24, 31, 31, 31, 31, 31**.

The distribution is **bimodal with a nine-day gap** — nothing sits between 14 and
23. Families are near-permanent residents or sporadic visitors, with no middle,
so the threshold only has to land in the empty region. 20 does.

| eligible (7) | days | median/day | | excluded (5) | days |
|---|---|---|---|---|---|
| uptime | 31 | 480 | | perplexity | 14 |
| other-bot | 31 | 374 | | feed | 11 |
| search | 31 | 124 | | google-ai | 10 |
| seo | 31 | 84 | | amazon-ai | 9 |
| openai | 31 | 8 | | commoncrawl | 2 |
| unclassified-machine | 23 | 1059 | | | |
| anthropic | 24 | 9 | | | |

Drops 5 of 12 families and only **2.6%** of traffic (68,016 of 69,836 stay in
scope).

**One rule, not two.** With zero-fill, >= 20 present days out of 30 leaves at
most 10 zeros, so the median is guaranteed to fall among real values — a
days-present floor implies a non-zero median. A separate volume floor would be a
second knob measuring the same thing.

**A volume floor would have been wrong**, and `amazon-ai` shows why: 9 days
present, median 160 when present, max 235. On a volume test it outranks
`openai` — which is present every single day at a median of 8. But there is no
series there, only bursts. The right axis is presence; size is what the
statistics already handle.

**Expected noise:** `openai` (median 8, max 731) and `anthropic` (median 9, max
60) will be the liveliest families — MAD over single-digit counts is small, so
their z-scores move easily. That is left standing deliberately: a training
crawler going from 8 to 731 in a day is the finding this exists for. Watch them
before the health check earns an attention badge.

## Settled: the narrator needs no separate budget guard

Signals are capped before they reach it, and `snt_ai_generate_with_constraints`
already routes a budget `WP_Error` to the deterministic floors. A second guard
would be a second thing to keep in step for no new protection.
