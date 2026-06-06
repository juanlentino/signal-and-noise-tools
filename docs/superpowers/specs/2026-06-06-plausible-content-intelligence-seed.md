# Plausible content-intelligence layer — SEED (flagship candidate for v5.0.0 + theme v10.0.0)

**Status:** SEED captured 2026-06-06 from a grounding/sizing pass (3 agents: current SN usage vs full Plausible CE Stats API vs strategic surfaces). **Not a spec.** Deep design defers to the v5.0.0 + v10.0.0 major-cycle brainstorm. This records the opportunity with real substance so the major has a *marquee feature* candidate (it is currently scoped cleanup-only).

**Why this is flagship-shaped, not a quiet minor:** the *work* is additive (new reads / surfaces — never a SemVer-breaking change on its own), but the *vision* — an analytics-driven content-intelligence layer feeding both the AI strategist and the reader-facing site — is a headline capability. The one genuinely major-flavored piece (the v1→v2 API substrate refactor, §4) is a data-layer behavioral shift that fits a major. `ai/ai` has **zero** overlap on the analytics axis (it is all single-post editorial assist), so this is SN-owned differentiation.

---

## §1. The gap — what we extract today vs. what CE exposes

**Today (verified — `inc/plausible-api.php`):** Plausible **Stats API v1** (legacy GET), **only `7d`**, ~7 metrics (visitors, pageviews, bounce_rate, visit_duration, per-page visitors, per-source visitors, realtime count), **exactly two breakdowns** (`event:page` top-7, `visit:source` top-7). No timeseries, no filters, no goals, no geo/device/browser, no custom props, no trend/comparison. Surfaces: 4 grandfathered dashboard widgets + the Plausible admin tab (config/status) + 3 `diagnostics` abilities + one signal into `insights.php` (a `{path => views_7d}` map).

**CE exposes (verified vs live docs) — the unused surface:**

| Axis | Unused today |
|---|---|
| **API** | v2 `POST /api/v2/query` (universal: aggregate/timeseries/breakdown, **multi-dimension**, arbitrary `and`/`or`/`not` filters, saved segments, `order_by`, pagination, `include.imports`). v1 kept only for the realtime endpoint (no v2 equivalent). |
| **Metrics** | `visits`, `views_per_visit`, `events`, **`scroll_depth`** (read-completion), **`time_on_page`**, **`conversion_rate`** + `group_conversion_rate`, `percentage`. (Revenue metrics are NOT in CE.) |
| **Dimensions** | `entry_page`, `exit_page`, `referrer`, `channel`, all `utm_*`, `device`, `browser(_version)`, `os(_version)`, `country/region/city(_name)`, **`event:goal`**, **`event:props:*`** (custom properties), `time:hour/day/week/month` (timeseries). |
| **Behavior** | **Goals + custom events + conversion tracking + custom properties** — all available in CE; we use **none**. (Funnels + revenue goals + Sites-Provisioning API are the only things CE withholds.) |
| **Comparison** | period-over-period trend (v1 `compare=previous_period`, or two v2 queries diffed). |

---

## §2. Opportunity directions (sketches for the brainstorm — not yet scoped)

**D1 — Deepen the insights signal (cheapest, highest-leverage).** `insights.php` (the Content Opportunity Advisor) already joins per-post 7d views into its single AI-strategist call. Enrich the signals dict with **trend/delta** (7d vs prior 7d), **scroll_depth** + **time_on_page** per post, entry/exit pages, and per-post top sources. This sharpens recommendations from "Note X has traffic" to *"Note X gets traffic but 22% scroll depth → rewrite the lede"* and *"Topic Y is trending +180% → double down."* No new surface — better cards in the existing Insights tab. **Plug-in points:** enrich `snt_insights_collect_signals()` and/or add recommendation `type`s to the parser allowlist.

**D2 — Front-end "Read next / Popular" surface (the v10.0.0-visible marquee).** The theme consumes **zero** Plausible data today. `templates/single.html`'s Note footer has *no* related/popular surface (just two static links); `templates/home.html` + `page-notes.html` are hard-coded `orderBy:date`. Add a Plausible-driven **"Read next / Popular this week"** block (dynamic block render callback or a `[sn_*]` shortcode, mirroring the `[sn_reading_time]` precedent) fed by the per-post views map — and optionally a "Most read" ordering on `/notes`. Real analytics shaping the *reader* experience, branded (front-end is the one place branded presentation is unconstrained).

**D3 — Goals + conversions (the unused CE superpower).** SN tracks zero goals. Define a handful — *Read-to-end* (scroll goal), *Clicked the pillar link*, RSS/newsletter signup, outbound-link click — to turn Plausible from a pageview counter into a **behavior** source feeding D1 + D2. Requires front-end tracking-snippet additions + Plausible goal config (Site Settings → Goals). **CE limit:** no funnel API — sequences must be inferred, not queried.

**D4 — A real analytics view in the Monitoring/Insights tab (allowed admin surface).** Beyond the top-line widgets: timeseries sparklines, geo/device/source breakdowns, a per-post performance table — via v2 query, rendered natively (NOT brutalist; admin reads native WP). **Hard constraint:** NO new dashboard widget and NO new top-level admin-bar node — this lives *inside* the existing Monitoring tab.

**D5 — Weekly content-performance digest (optional, forward).** Top movers / underperformers / trend, delivered via the existing weekly-insights cron + webhooks (email / Apple Note / n8n). Reuses infra already present.

---

## §3. Constraints & guardrails (carry into the brainstorm)

- **Reuse the SWR cache architecture.** `plausible-api.php` accessors NEVER hit the network on a render path — they read transients warmed by `admin_init` → WP-Cron loopback. Any new query = a new cached batch with its own freshness/retention. **Never** call the Stats API on a request path (front-end included — front-end reads the same cached map).
- **Surface rules** (`[[feedback_no_dashboard_widgets]]`): no NEW dashboard widgets (no carve-out), no NEW top-level admin-bar nodes. Grandfathered: the 4 Plausible widgets + the `sn-quick` dropdown. Allowed: new content in the Monitoring/Insights tab + ONE action sub-item in the dropdown (e.g. "Run Insights Scan"). Prohibited: turning the dropdown into a status display.
- **No `ai/ai` duplication** (`[[reference_ai_plugin_v1_features]]`): keep the cross-post, traffic-grounded angle; never rebuild single-post editorial generators.
- **CE operational reality:** 600 req/hr **per key** (raised only via Postgres `api_keys.hourly_request_limit`; new keys reset to 600). v2 batches many metrics/dimensions per call → stays well under. No funnels, no revenue metrics, no Sites-Provisioning API — scope around them, and re-confirm CE feature parity against the installed version's changelog (Plausible periodically migrates business features into CE).
- **Auth/infra:** Plausible CE self-hosted on Railway; SN auths via `SN_PLAUSIBLE_STATS_TOKEN` (wp-config constant, preferred) against `{self_hosted_domain}/api/...`.

---

## §4. The one major-flavored piece — v1 → v2 client refactor (the enabling substrate)

Most directions need the v2 `POST /api/v2/query` surface (timeseries, multi-dimension, arbitrary filters, `scroll_depth`/`time_on_page`/`conversion_rate`). Today's `sn_plausible_api()` speaks v1 only. A v5.0.0-era refactor to a v2 query builder — keeping v1 **solely** for `realtime/visitors` (which has no v2 equivalent) — is the foundational substrate the rest builds on, and is a real data-layer behavioral shift appropriate to a major. Sketch: a `sn_plausible_query( array $query )` helper that posts a v2 body and normalizes results, with the existing SWR cache + cron-warmer wrapping it; the legacy v1 batch becomes one v2 query among several.

---

## §5. Open questions for the major-cycle brainstorm

1. Which directions are in scope for v5/v10 — all, or a phased subset (D1 first as the cheapest win, D2 as the visible flagship)?
2. Goals (D3): which behaviors to track? (Each needs a front-end tracking change + a Plausible goal config — couples plugin + theme + the Plausible box.)
3. Front-end "popular" (D2): dynamic block render vs shortcode? How fresh must per-post counts be (the cache map is 5-min-target / 1-day-retention)? Privacy posture of surfacing counts?
4. v2 migration (§4): full cutover vs v2-for-new-queries-only; keep v1 realtime.
5. Digest (D5): in scope, and which channel?
6. Does this land as the *flagship inside* v5.0.0/v10.0.0, or as a phased post-major arc (e.g. v5.1/v10.1) so the major stays a clean cleanup release?

---

## §6. Cross-references

- Current data layer: `inc/plausible-api.php` (v1 client + SWR cache + cron warmers), `inc/plausible-widget.php` (4 grandfathered widgets), `inc/plausible-admin.php` (config tab), `inc/abilities-plausible.php` (3 diagnostics abilities).
- The spine to extend: `inc/insights.php` (Content Opportunity Advisor — already joins `{path => views_7d}`), `inc/insights-admin.php` (Monitoring → Insights tab).
- Major chain: theme `docs/superpowers/specs/2026-05-27-v5-and-v10-paired-cycle-design.md`; roadmap `docs/superpowers/specs/2026-06-05-master-execution-sequence.md` (theme repo).
- CE Stats API: `https://plausible.io/docs/stats-api` (v2), `https://plausible.io/docs/stats-api-v1` (v1, realtime). CE caveats: 600/hr per key (Postgres knob), no funnels/revenue/sites-API.
- Memory: `[[feedback_no_dashboard_widgets]]`, `[[reference_ai_plugin_v1_features]]`, `[[feedback_no_brutalist_in_admin_ui]]`, `[[feedback_read_framework_source]]`.
