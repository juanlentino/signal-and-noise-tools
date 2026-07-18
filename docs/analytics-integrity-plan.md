# Analytics Integrity Phase A — Implementation Plan (scaffold)

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Status: SCAFFOLD.** Task decomposition, file map, candidate SQL, null-discipline rules, and the trap checklist are FINAL. Per-step code blocks must be grounded against the real files named in each task at execution time (this scaffold was written deliberately without re-reading them, at 83% context under the 5-hour limit). Before executing any task, read the files in its **Files:** list first.

**Goal:** Ship the approved Phase A spec ([analytics-integrity-design.md](analytics-integrity-design.md)): honest metric naming, pageview-gated headline visits, viewless count, exact + `_sample_interval`-weighted engagement, never-invert integrity guard, trailing-≤90d backfill.

**Architecture:** Additive-only. Four new columns on `wp_sn_analytics_daily` (DB v4→5, nullable so legacy rows stay distinguishable), an extended AE rollup query with every aggregate `_sample_interval`-weighted, a new **pure** derive module computing every spec-§4 field from a daily row, a read-layer merge exposing new fields beside the kept-deprecated ones, guard surfacing via Health + `error_log`, and a one-time owner-run trailing-90d re-roll.

**Tech stack:** PHP/WordPress (dbDelta, options), Cloudflare Analytics Engine SQL, standalone `tests/*.php` sweep (no WP boot in CI).

**Branch / version:** continue on `feat/analytics-integrity`; PR to `main` when done. Target **v9.63.0 (MINOR)** — confirm against the theme's `docs/VERSIONING.md`; bump/tag/Canny at END of session only. CHANGELOG entry on every code commit (docs-only commits exempt per repo convention).

---

## Phase 0 — Pre-flight probes (spec §12; blocks Task 3+, NOT Tasks 1–2)

All three probes need a booted live WP (AE creds live server-side). Batch them into **one** owner-run `wp eval-file` ask.

- [ ] **P0.1 — `pageview_visits` source.** Create `tools/ae-dialect-probe.php` (dev-only, never bundled, CI-excluded like `tests/contracts-smoke.php`). It runs, over a 1-day window, printing RAW AE JSON responses:
  1. **Primary (single query):** `count(DISTINCT if(blob1 = 'pv', index1, NULL)) AS pageview_visits` appended to the existing rollup SELECT. AE's strict typing may reject `NULL` (see `ae-sql-dialect-gotchas` memory: strict typing, alias-only ORDER BY, no `uniq()`). Do NOT substitute an `''` sentinel — empty string would count as a distinct value and poison the count.
  2. **Fallback A (two queries, same source — near-certain feasible):** duplicate the existing verified visits query (`inc/analytics-rollup.php:244-247` shape) with `AND blob1 = 'pv'` added to WHERE → `count(DISTINCT index1)`. Same dialect surface as code already proven against live AE.
  3. **Fallback B (last resort):** `sn_pageview_visits()` (`inc/analytics-sessions.php:569-577`) — pageview-gated *sessions*, a subtly different unit than gated visitor-days. Only if AE breaks entirely; if used, the field description must say "gated sessions".
  - **Decision rule:** primary if it parses; else Fallback A. Record the choice + the raw probe output in this file under "P0 results".
- [ ] **P0.2 — Weighted-aggregate forms.** Same probe file. `sumIf(_sample_interval, blob1='pv')` is already proven live (existing rollup), so `sumIf` + expression args exist. Verify the multiplication form parses: `sumIf(double1 * _sample_interval, blob1 = 'sc')`. If rejected, fall back to `sum(if(blob1 = 'sc', double1 * _sample_interval, 0))`. Also capture one full raw AE response — it becomes the Task 3 test stub (stub must model the transport, not an invented shape).
- [ ] **P0.3 — Versioning confirm.** Read the theme repo's `docs/VERSIONING.md`; confirm MINOR → v9.63.0. (Additive schema + new user-visible fields = MINOR under the global rules; this is a cheap double-check, not a decision.)
- [ ] **P0.4 — Guard surface (recommendation: default, don't block).** `error_log('[sn-analytics] integrity violation …')` + set option `sn_analytics_integrity_alert` (timestamped payload) read by the Health scan (`get-health-scan` surface). Insights deferred. Proceed with this default unless the owner objects.

## P0 results (live probe, owner-run 2026-07-17, `wp eval-file` on juanlentino.com)

- **P0.1 verdict: USE FALLBACK A.** Primary rejected — HTTP 422: *"the 2nd and 3rd arguments to IF() function must have the same type but instead had String and Null"* (consistent with the v5.2.0 rejection; the `count(DISTINCT <expr>)` dialect-guard ban STAYS — do not relax it). Fallback A passed (HTTP 200, `pageview_visits` UInt64). → **Task 3 adds a SECOND rollup query**: the existing verified shape + `AND blob1 = 'pv'` in WHERE + `count(DISTINCT index1) AS pageview_visits`, merged per `(day, path, class)` key in PHP.
- **P0.2 verdict: multiplication form OK.** `sumIf(double1 * _sample_interval, blob1='sc')`, the `tm` twin, and the weighted event counts all parsed (HTTP 200). No `sum(if(...))` fallback needed.
- **Transport shape for the Task 3 stub (from the raw live response — the stub MUST model these):** envelope `{meta:[{name,type}…], data:[…], rows, rows_before_limit_at_least}`; **UInt64 columns come back as JSON STRINGS** (`"views":"1"`, `"scroll_events":"0"`, `"pageview_visits":"11"`) while Float64 come back as numbers (`scroll_sum:3250`, `time_sum:294971`); `avgIf` with zero matching events returns `null` while the weighted sums return `0` for the same rows — numeric-string counts, null avgs, zero sums, all in one response.
- **Live validation bonus:** the viewless mechanism is directly visible in the probe window — feed paths with `views=0, visits≥1` (the RSS `srv:1` beacon class) and `/` human 2026-07-16 showing `views=2, visits=5` (the inversion, live).
- Probe result sets were LIMIT-ed samples (`rows_before_limit_at_least` 29/225 vs rows 10/20) — fine for dialect probing; the production rollup's own limits are unchanged by this.
- P0.4 guard surface: no owner objection raised → proceed with the default (`error_log` + `sn_analytics_integrity_alert` option read by Health). P0.3 (VERSIONING.md double-check) still pending, trivial.

## File map

| Action | Path | Responsibility |
|---|---|---|
| Modify | `inc/analytics-rollup.php` | installer (+5 cols — four engagement + `pageview_visits INT UNSIGNED NULL`, amended post-review; DB v4→5), rollup query, upsert, rollup-side guard |
| Create | `inc/analytics-derive.php` | pure derive functions — **no WP calls**, `require()`-able by tests directly |
| Modify | `inc/analytics-read.php` | summary assembly: merge derive output, null discipline, read-side defensive guard |
| Modify | ability file registering `get-analytics-summary` (locate: `grep -rn "get-analytics-summary" inc/`) | description + output schema |
| Create | `tools/ae-dialect-probe.php` | P0 probes (dev-only) |
| Create | `tools/reroll-analytics-90d.php` | backfill (dev-only, owner-run) |
| Create | `tests/analytics-derive.php` | pure-fn unit tests (require, no stubs) |
| Modify | `tests/abilities-analytics.php` | full response-schema contract + description assertions |
| Modify | existing rollup test (locate: `grep -ln "analytics-rollup\|sn_analytics_rollup" tests/`) | new columns, weighted forms, idempotence, guard |

---

### Task 1 — Schema: five nullable columns, DB v5

> **Amended post-review:** the v5 schema is **FIVE** columns, not four — the four engagement sums below **plus `pageview_visits INT UNSIGNED NULL DEFAULT NULL`** (spec §4/§8 store it per daily row: the Task 3 upsert writes it, the Task 4 read layer range-sums it). The original scaffold omitted it; the adversarial review of the Task 1 commit caught the gap. DB version stays '5' (unreleased — nothing has shipped it).

**Files:** Modify `inc/analytics-rollup.php` (installer `sn_analytics_daily_install()`, const `SN_ANALYTICS_DAILY_DB_VERSION`). Test: locate the existing install/migration test in `tests/`; extend it (else add assertions to the rollup test).

- [ ] Read the installer + CREATE TABLE block.
- [ ] Add `scroll_sum FLOAT NULL DEFAULT NULL`, `scroll_events INT UNSIGNED NULL DEFAULT NULL`, `time_sum FLOAT NULL DEFAULT NULL`, `time_events INT UNSIGNED NULL DEFAULT NULL`, `pageview_visits INT UNSIGNED NULL DEFAULT NULL`. **NULLABLE is load-bearing:** legacy rows must read NULL ("never measured"), never a fabricated 0 (`realtime-zero-vs-null` memory). Mind dbDelta's whitespace/KEY quirks — mirror the existing column formatting exactly.
- [ ] Bump `SN_ANALYTICS_DAILY_DB_VERSION` `4` → `5`. Never drop/recreate — the table holds real history since v2.
- [ ] Test asserts the CREATE TABLE string contains the four columns as nullable; run it; commit (`feat: analytics daily schema v5 — engagement sum columns (nullable)` + CHANGELOG).

### Task 2 — Pure derive module (TDD; no WP, no P0 dependency)

**Files:** Create `inc/analytics-derive.php`, Create `tests/analytics-derive.php`.

Function: `sn_analytics_derive_metrics( array $daily ): array`. Input keys: `views`, `visits` (≡ unique_visitor_days), `pageview_visits`, `scroll_sum`, `scroll_events`, `time_sum`, `time_events` (any may be absent or NULL). Output: every spec-§4 field — `unique_visitor_days`, `pageview_visits`, `viewless_visits`, `view_visit_ratio`, `pageviews_per_visitor_day`, `scroll_avg_per_view`, `time_avg_per_view`, `scroll_avg_per_visit`, `time_avg_per_visit` — plus `integrity_violation` (bool: `views < pageview_visits`, both non-null).

**Rules (each one is a shipped-bug class from memory):**
- `array_key_exists()` for absent-vs-null — `??`/`isset()` cannot distinguish them and `($x['k'] ?? 'missing') === null` is unsatisfiable.
- Ratio = `null` when denominator is 0 **or** any input is null/absent. Never cast null → 0, never 0 → null. A zero-traffic day yields real 0 counts and null ratios.
- `viewless_visits = unique_visitor_days − pageview_visits` only when both non-null; else null.
- Pure: no WP functions, no globals — tests `require` the real file (never stub; `test-unguarded-fn-declarations` memory), guard the declaration with `function_exists`.

- [ ] Write `tests/analytics-derive.php` FIRST with value-pinned fixtures (not label echoes): (a) full modern row → every field exact-value asserted; (b) legacy row (NULL sums) → derived engagement/gated fields all null, legacy passthrough intact; (c) zero-traffic day → 0 counts, null ratios; (d) inverted fixture (`views=3, pageview_visits=5`) → `integrity_violation === true`; (e) absent-key row → nulls, no notices.
- [ ] Run: FAILS (file missing). Implement. Run: PASSES. Commit + CHANGELOG.

### Task 3 — Rollup query + upsert + rollup-side guard (needs P0.1/P0.2)

**Files:** Modify `inc/analytics-rollup.php` (query at ~:244-247, upsert), extend the rollup test.

- [ ] Extend the SELECT with (using the P0-verified forms): `scroll_sum = sumIf(double1 * _sample_interval, blob1='sc')`, `scroll_events = sumIf(_sample_interval, blob1='sc')` (weighted count — NOT raw `countIf`; research §5: counts are `sum(_sample_interval)`), `time_sum = sumIf(double2 * _sample_interval, blob1='tm')`, `time_events = sumIf(_sample_interval, blob1='tm')`, plus `pageview_visits` per the P0.1 decision.
- [ ] Fix the legacy `scroll_avg`/`time_avg` to the weighted form `scroll_sum/scroll_events` (research §5: today's unweighted `avgIf` is wrong under sampling; harmless at interval=1 but fix in this pass). Same value at interval=1 → no visible change.
- [ ] Upsert writes the four new columns + `pageview_visits`. **`wpdb %f` locale hazard applies to the new FLOAT columns:** bind as `number_format($v, 4, '.', '')` → `%s`, never `%f`. Keep the 100-row upsert chunking unchanged.
- [ ] Rollup-side guard (human class): if `views < pageview_visits` → `error_log` + set `sn_analytics_integrity_alert` option; **still write the row** — the alarm is the feature, never clamp or skip.
- [ ] Tests: stub mirrors the RAW AE JSON captured in P0.2 (the transport's real shape — `test-stub-drift-invents-shapes` memory; real names: `scroll_avg`, NOT `avg_scroll`); assert upsert receives the new columns with pinned values; re-roll same day twice → identical row (idempotence); inverted stub → alert option set AND row still written. Commit + CHANGELOG.

> **⚠ Warning (post-review, upsert coercion):** `sn_analytics_rollup_upsert()` today coerces missing keys via `?? 0` — e.g. `(float) ( $r['views'] ?? 0 )` (`inc/analytics-rollup.php:311-314`). That is correct for the legacy NOT NULL DEFAULT 0 columns, but do **NOT** copy the pattern for the five new nullable columns: `?? 0` turns absent/null into a fabricated 0, defeating the nullable schema and the whole null-vs-zero discipline. Use `array_key_exists()` + explicit NULL binding (write SQL NULL when the key is absent or null) so a row with no engagement data stays "never measured".
>
> **⚠ Warning (post-review, coupled tests):** `tests/ae-dialect-probe.php` is deliberately coupled to the CURRENT rollup SELECT shape (its transforms are grounded against the real `sn_analytics_rollup_sql()` output). When Task 3 extends the SELECT it WILL fail loudly — that is by design: re-verify its needles against the new SQL, or retire it if the probe has served its purpose. And if the live P0.1 verdict is "use primary", `tests/analytics-sql-dialect.php`'s ban on `count(DISTINCT <expr>)` (it allows only a bare column) must be relaxed for that exact proven form — `count(DISTINCT if(blob1 = 'pv', index1, NULL))` — before the rollup SQL can carry it.

### Task 4 — Read layer: summary assembly

**Files:** Modify `inc/analytics-read.php` (engagement weighting at ~:99-100, summary path), extend its test.

- [ ] Range aggregation: sums/counts add across days (`views`, sums, events, `pageview_visits`, `unique_visitor_days` — both already visitor-DAY units, so summing stays honest); then call `sn_analytics_derive_metrics()` ONCE on the range totals; merge into the response WITHOUT touching `views`/`visits`/`scroll_avg`/`time_avg` (kept, deprecated).
- [ ] **Mixed-range rule (legacy + modern rows):** if ANY row in range has NULL `scroll_sum` → exact engagement + gated fields are null for that range, and the response carries `exact_metrics_since` (option set by Task 6) so the UI/tools can say why. Honest null beats a silently partial denominator.
- [ ] Read-side defensive guard: same check, same alert option (idempotent set).
- [ ] Mind the local-vs-UTC "today" parity trap: the read filter must mirror the rollup's day boundary exactly (`analytics-read-rollup-parity-traps` memory).
- [ ] Tests: range of 3 modern rows → pinned derived values; range mixing legacy+modern → nulls + `exact_metrics_since` present; commit + CHANGELOG.

### Task 5 — Ability: description, output schema, contract test

**Files:** Modify the ability file found via `grep -rn "get-analytics-summary" inc/`; extend `tests/abilities-analytics.php`.

- [ ] Description documents EVERY denominator verbatim-style: what `visits` really is (deprecated, approx unique visitor-days, can exceed views), `pageview_visits` (headline, cannot invert), `viewless_visits`, per-view vs per-visit engagement ("per_visit diluted by viewless days"), and the `exact_metrics_since` discontinuity.
- [ ] Output schema: add every new field, correct types, nullable where the derive layer can return null. (Desktop Mode normalizes ability schemas at `desktop_mode_ai_tools` — additive fields are safe; do not rename existing ones.)
- [ ] Contract test pins the FULL response schema — every spec-§4 field present with correct type (null allowed where nullable) — and asserts the description contains each denominator name + `exact_metrics_since`. Run the FULL `tests/` sweep (admin-registry full-sweep rule: registry tests break on unrelated edits). Commit + CHANGELOG.

### Task 6 — Backfill re-roll (owner-run)

**Files:** Create `tools/reroll-analytics-90d.php` (dev-only; CI-excluded; never bundled).

- [ ] Loop `$day = today−89 … today` (**SITE-LOCAL day**, matching the rollup's boundary — the rollup buckets by the site zone via `sn_analytics_site_tz_name()` since the v9.26.4 migration, America/New_York on live; the UTC path only when the site zone is a manual offset. This step originally said "UTC" — stale, corrected: a re-roll bucketed differently from the durable history would write adjacent-day rows beside it instead of overwriting) calling the existing per-day rollup function; echo per-day result (never silent — a `success:true` cron that writes nothing is a known failure shape).
- [ ] On completion set option `sn_analytics_exact_metrics_since = (today − 89d)` (Y-m-d, site-local day).
- [ ] Owner runs via `wp eval-file` on live. Idempotence already covered by Task 3's test. Commit + CHANGELOG.

### Task 7 — Close-out

- [ ] Full `tests/*.php` sweep green (run every file, as CI does).
- [ ] Update `docs/analytics-integrity-design.md` status line → implemented; append "P0 results" to THIS file.
- [ ] CHANGELOG consolidated entry; version bump v9.63.0 + tag + Canny at END of session only (per global rules). PR `feat/analytics-integrity` → `main`; squash-merge + annotated tag; **owner installs via wp-admin → Updates — do NOT dispatch deploy.yml**.

---

## Trap checklist (from project memory — re-check at every task)

- **Null ≠ 0, absent ≠ null**: `array_key_exists`, never `??`/`isset` for the distinction; empty result is an ANSWER.
- **Stub drift**: stubs mirror the RAW AE JSON from P0.2 and the real callee's names (`scroll_avg` not `avg_scroll`). A transport stub must model the transport's TRANSFORM.
- **Vacuous assertions**: pin values, counts, order — not labels the fixture supplies for free.
- **`wpdb %f`**: FLOAT binds via `number_format(...,'.','')` → `%s` (four new columns!).
- **Upsert chunking at 100**; **day-boundary parity** between rollup and read (SITE-LOCAL day via `sn_analytics_site_tz_name()` since v9.26.4 — not UTC).
- **Full test sweep** before PR — registry tests break on unrelated edits.
- **CI boots no WP**: `tools/*.php` stay excluded like `contracts-smoke.php`.
- **No JS this phase** → the `wp_localize_script` string-cast trap doesn't bite; if a widget later consumes these fields, re-read that memory first.

## Execution notes for the next session

1. Read the spec §4–§9 + this plan. Both are committed on `feat/analytics-integrity`.
2. **Read before writing:** `inc/analytics-rollup.php`, `inc/analytics-read.php`, the `get-analytics-summary` ability file, `tests/abilities-analytics.php`, and one known-good sibling test to copy stub patterns from.
3. Order: Tasks 1–2 immediately (no P0 dependency) → batch P0 into ONE owner `wp eval-file` ask → Tasks 3–6 → Task 7.
