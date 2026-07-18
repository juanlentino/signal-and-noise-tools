# Analytics integrity — design spec

**Date:** 2026-07-17
**Status:** Approved (design); pending owner spec-review before planning
**Target version:** MINOR — tentatively v9.63.0 (confirm against the theme's `docs/VERSIONING.md` before the bump; bump/tag/Canny at end of session only)
**Origin:** Phase 1 audit of the `views < visits` anomaly in `get-analytics-summary` (findings preserved in the `analytics-views-visits-misnamed-root-cause` memory).

---

## 1. Problem (from the Phase 1 audit)

`get-analytics-summary` returned human `views < visits` (7d 87/131, 90d 778/714), read as impossible. Root cause is **not** a phantom-detection failure and **not** an aggregation bug — it is that **`visits` is misnamed** and the summary reads the non-pageview-gated surface:

- Verified query (`inc/analytics-rollup.php:244-247`): `views = sumIf(_sample_interval, blob1='pv')` (sample-corrected pageviews); **`visits = count(DISTINCT index1)`**, `index1 = SHA-256(IP+date)` = **approximate unique visitor-days, not sessions**, over all event types with **no pageview gate**.
- The two are not dimensionally comparable (views sample-corrected; visits a raw distinct-hash count). The module header already warns: *"treat views/visits as an estimate, not a precise ratio."*
- So `views < visits` is **structural**: any visitor-day whose only events are `sc`/`tm`/`ce`/`cp` (orphan scroll/time beacon, custom event, or the RSS `srv:1` server beacon) lands `views=0, visits≥1`.
- The read/aggregation layer is arithmetically faithful. The wrongness is **semantic**.

Two **distinct** anomalies were conflated in the original report:
1. **The inversion** (`views < visits`) — the misnaming above. Fixable in-repo.
2. **The 7d engagement dip** — `scroll_avg`/`time_avg` are per-engagement-event, views-weighted at read (`inc/analytics-read.php:99-100`), i.e. effectively per-view, **not** per-visit. A viewless visitor-day contributes nothing. The dip is therefore **real traffic** (a LinkedIn skim burst genuinely lowers per-view engagement on an 87-view sample), **not** a denominator artifact.

The classifier (`human`/`suspect`/`bot` = `blob7`) is assigned **per-event in the external Cloudflare worker** (UA + datacenter ASN + CF bot score, no behavioral gate); it is not in this repo. "Viewless," however, is fully derivable in PHP (a visitor-day with 0 pageviews).

## 2. Goals / non-goals

**Goals**
- Make the summary honest and unambiguous: every denominator explicitly named and documented.
- Make the inversion structurally impossible on the headline visit metric, with an integrity guard for the impossible case.
- Expose the phantom (viewless) count as first-class, derived in-repo.
- Deliver exact per-view **and** per-visit engagement.
- Backfill as far as the data allows; state the discontinuity plainly.

**Non-goals (this phase)**
- Worker-side previewer classification (Phase B — only if the derived label proves too coarse).
- Any "fix" to the engagement dip — it is real traffic, correctly labeled.
- The deploy-widget "Last deploy" fix — separate concern, separate PR (see §10).

## 3. Design principle — "show the most"

Owner directive: **bias toward maximum transparency.** Where a choice exists between exposing more vs. fewer fields/denominators, expose more. Keep every back-compat field (deprecated, never silently redefined). Never ship a single ambiguous denominator when both can be named.

## 4. Metric vocabulary (the summary response)

Phase A: the API is a superset of today — nothing removed, nothing silently redefined.

| Field | Definition | Notes |
|-------|-----------|-------|
| `views` | pageview events, `sumIf(_sample_interval, blob1='pv')` | unchanged |
| `visits` | = `unique_visitor_days` | **kept, deprecated**; description states "approx unique visitor-days (IP+date), not sessions; can exceed views" |
| `unique_visitor_days` | explicit alias of today's `visits` | honest name for the raw distinct-`index1` count |
| `pageview_visits` | distinct visitor-days with **≥1 pageview** | **headline visit metric**; `views ≥ pageview_visits` always → cannot invert |
| `viewless_visits` | `unique_visitor_days − pageview_visits` | the phantom count, derived |
| `view_visit_ratio` | `views / pageview_visits` (≥1) | the meaningful "pageviews per real visit" |
| `pageviews_per_visitor_day` | `views / unique_visitor_days` (may be <1) | raw ratio, exposed for transparency ("show the most") |
| `scroll_avg_per_view` | `scroll_sum / views` (exact) | honest engagement |
| `time_avg_per_view` | `time_sum / views` (exact) | honest engagement |
| `scroll_avg_per_visit` | `scroll_sum / unique_visitor_days` (exact) | **labeled "diluted by viewless days"** |
| `time_avg_per_visit` | `time_sum / unique_visitor_days` (exact) | **labeled "diluted by viewless days"** |
| `scroll_avg`, `time_avg` | today's views-weighted approximation | **kept, deprecated**; denominator documented; not silently changed |

## 5. The never-invert guard

Because `views ≥ pageview_visits` holds by construction (each gated visit contributes ≥1 pageview; `views` is additionally sample-corrected), an inversion on the headline metric is arithmetically impossible. A guard therefore asserts `views ≥ pageview_visits` for the `human` class at rollup (and defensively at read). If it ever fails, that is a genuine rollup/sampling bug — surface it (a Health/Insights signal + a logged warning), never serve it silently. This is the owner's "human must never invert" requirement, expressed as a real integrity alarm rather than a cosmetic clamp.

## 6. Engagement schema extension

Today `wp_sn_analytics_daily` stores per-event *means* (`scroll_avg`, `time_avg`) — insufficient for an exact per-view or per-visit denominator. Add four columns (dbDelta ADD — backward-compatible, no key rotation):

- `scroll_sum FLOAT`, `scroll_events INT UNSIGNED` — from `sumIf(double1, blob1='sc')`, `countIf(blob1='sc')` (or AE equivalent).
- `time_sum FLOAT`, `time_events INT UNSIGNED` — from `sumIf(double2, blob1='tm')`, `countIf(blob1='tm')`.

Exact metrics then derive cleanly (§4). The legacy `scroll_avg`/`time_avg` remain populated for back-compat. Bump `SN_ANALYTICS_DAILY_DB_VERSION` (currently `4`) → `5`; migration is additive (never drop the table — it holds real history since v2).

## 7. Viewless / "previewer" — orthogonal, not a class

A viewless visitor-day still carries the worker's class. So `viewless_visits` is a **cross-cutting derived count**, not a new `blob7` class. This answers the original 2b ("new class vs fold into suspect"): **neither, this phase** — it is an orthogonal signal computed in PHP. A real `previewer` class is Phase B (worker), reached only if the derived count proves too coarse.

## 8. Backfill (bounded by AE retention)

AE retains ~90 days of raw events. Plan:
- **Re-roll the trailing ≤90d from AE** to populate the new columns and the gated `pageview_visits` exactly (the rollup is already idempotent per-day, so a re-roll self-corrects).
- **Older daily rows cannot be split** — the stored aggregate already collapsed `index1` and holds no engagement sums; they keep their legacy (approximate, non-gated) values.
- **Discontinuity date = (today − 90d)**, surfaced in the tool description and a one-line data note so trend lines aren't misread across the boundary.

## 9. Data-availability constraint — `pageview_visits` source (resolve in the plan)

The gated count needs "distinct `index1` having ≥1 `pv`." Two candidate sources:
1. **AE gated-distinct column** (preferred, for one-source consistency) — e.g. a conditional distinct in the rollup query. **Feasibility to verify against live AE** (see the `ae-sql-dialect-gotchas` memory: strict typing, no `uniq()`); confirm AE supports a gated `count(DISTINCT …)` before committing.
2. **Session-engine fallback** — `sn_pageview_visits()` (`inc/analytics-sessions.php:569-577`) already computes a pageview-gated visit surface (feeds `sn_session_daily`). If AE gated-distinct is infeasible, source `pageview_visits` from there, noting it is gated *sessions*, a subtly different unit than gated visitor-days.

The plan must pick one with evidence from live AE, not assume.

## 10. Out of scope / separate work

- **Deploy-widget "Last deploy"** (`deploy-widget-tracks-deploy-yml-only` memory): point the widget ability at `snt_deploy_history_merged()` (the merged feed the Dashboard got in v4.1.4). A PATCH fix in a different subsystem — **separate PR**, not this release.
- **Phase B — worker previewer classification.** Deferred; documented option.

## 11. Migration, versioning, testing

- **Versioning:** additive schema + new user-visible fields → **MINOR** (tentatively v9.63.0). Read the theme's `docs/VERSIONING.md` first. CHANGELOG entry on every commit; version bump + tag + Canny at end of session only.
- **Migration:** `sn_analytics_daily_install()` gains the four columns via dbDelta (additive); DB version `4`→`5`; never drop.
- **Tests** (the standalone `tests/*.php` sweep + the six-trap library):
  - Contract test pinning the full new response schema of `get-analytics-summary` (every field present, correct types) — extends `tests/abilities-analytics.php`.
  - `viewless_visits == unique_visitor_days − pageview_visits`.
  - `views ≥ pageview_visits` guard fires on a synthetic inverted fixture (and is silent on valid data).
  - Exact engagement: `scroll_avg_per_view == scroll_sum/views` on a known fixture (guard against the `scroll_avg` vs `avg_scroll` stub-drift trap — assert against the real callee).
  - Rollup upsert writes the four new columns; re-roll idempotence.
  - Update the MCP tool description; a test asserts the description documents each denominator + the discontinuity date.

## 12. Open items for the implementation plan

1. Verify AE gated-distinct feasibility (§9) against live AE; else session-engine fallback.
2. Confirm the exact AE aggregation for `scroll_sum`/`time_sum`/counts (sumIf/countIf availability).
3. Confirm the MINOR bump against `docs/VERSIONING.md`.
4. Decide where the guard signal surfaces (Health vs Insights vs logged-only).
