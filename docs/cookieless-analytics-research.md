# Richest cookieless analytics — research report

**Date:** 2026-07-17 · **Method:** deep-research workflow (6 search angles, 24 sources fetched, 118 claims extracted, 25 adversarially verified 3-vote: **25 confirmed, 0 refuted**) · **Feeds:** the "stay cookieless, go rich" initiative (owner decision 2026-07-17) and the Phase A integrity spec (`docs/analytics-integrity-design.md`).

**Hard constraint held throughout:** NO cookies, NO localStorage — preserve the no-consent-banner property.

---

## 1. Identity: the state-of-the-art upgrade to our hash

Our current `index1 = SHA-256(IP + date)`. The verified state of the art is **Plausible's rotating-salt hash**:

```
visitor_id = hash(daily_salt + website_domain + ip_address + user_agent)
```

- Salt is **server-side, secret, rotated AND deleted every 24 h** (Plausible: midnight UTC; code-verified in their open-source repo — `Plausible.Session.Salts`, PR #5067). Raw IP/UA never persisted.
- **What each change buys us:**
  - **+ user_agent** — partially disambiguates users behind shared/CGNAT IPs. Cloudflare's own research: ~35% of /24 prefixes carry 100+ distinct browser clients per day (~500k multi-user IPs); counting distinct UAs per IP is one of Cloudflare's own multi-user-IP detection signals. Caveats: identical browser builds behind one IP still collide; Chrome's 2022+ UA reduction shrank UA entropy.
  - **+ secret rotating salt (replacing the predictable `date` component)** — with the old salt destroyed, yesterday's hashes **cannot be regenerated even by us**; cross-day re-identification becomes structurally impossible rather than merely avoided. This is the privacy backbone of the consent-free claim.
  - **+ domain** — trivially cheap; isolates the hash per site.
- **Semantics unchanged:** this is still **unique visitor-DAYS**. Plausible itself counts the same person as a new unique each day and its multi-day "unique visitors" are the *sum of daily uniques* (their GitHub discussion #1445 admits this openly). The upgrade improves **within-day accuracy**, not the visitor-days ceiling — which **validates Phase A's honest-naming design exactly** (Plausible has the same semantics we're renaming toward).
- **Salt mechanics for our Worker:** store in Worker KV or a secret; rotate via cron at midnight UTC; **retain the previous day's salt briefly across rotation** (Plausible does) so in-flight sessions aren't split — the Worker tries both salts at the boundary. (Open question: replicate that dual-salt window vs. accept boundary splits.)
- **Rejected alternative:** GoatCounter's in-memory indirection (`concat(siteID, UA, IP)` → random UUID held in RAM ≤8 h; no salt since v2.6.0). Verified accurate, but wrong for us: Workers are stateless across isolates, so the in-memory map would need Durable Objects/KV — reintroducing a stored mapping. The salted hash achieves never-persist-raw-inputs **statelessly**.

## 2. Legal basis (medium confidence — the one non-unanimous finding, 2-1)

The consent-free property rests on **no storage on / access to the user's device** (ePrivacy Art 5(3) attaches to terminal-equipment storage/access, not to server-side transient hashing). Nuances the verifiers insisted on:
- GDPR **still applies** to the transient IP processing (legitimate-interest basis) — "consent-free" ≠ "GDPR-exempt."
- This is the **prevailing interpretation** (Plausible's commissioned legal assessment, Matomo's analysis), **not settled case law**; EDPB Guidelines 2/2023 read Art 5(3) broadly. Treat "no consent banner" as defensible-by-design, not adjudicated.
- Practical rule: any future feature must keep **zero device storage/access** — that design property, not "no cookies" narrowly, is what clears the trigger.

## 3. Sessions & metric definitions (cookieless best practice)

- **30-min inactivity window** = the industry convention (Plausible; GA-compatible semantics). GoatCounter uses 8 h — an outlier. Our session engine already implements 30-min gap-splitting on the visitor-day hash: **validated, keep it.**
- Compute at rollup by ordering a hash's events and splitting on >30-min gaps. **Bounce** = single-pageview session. **Engaged session** = ≥2 pageviews OR a scroll/time engagement event (our existing `engaged` flag: scroll ≥50% AND dwell ≥15 s is stricter — fine, keep, but the ≥2-pv OR engagement variant is the common definition).
- Sessions spanning midnight split (salt rotation) — mitigated by the dual-salt window (§1) or accepted as a documented artifact.
- **Multi-day "unique visitors" must be labeled visitor-days.** Every honest vendor does this. (Phase A's `unique_visitor_days` naming is exactly right.)

## 4. Beacon reliability (validates + refines our pv/sc/tm design)

- **`navigator.sendBeacon()` fired on `visibilitychange` → `hidden`** (plus `pagehide` as Safari fallback). **Never `unload`/`beforeunload`** — they frequently never fire on mobile (MDN verbatim) and break bfcache. Benchmarks: visibilitychange+pagehide ≈ 91% delivery vs. badly lagging unload-based firing.
- Because `visibilitychange` fires on every tab switch, send **incremental, idempotent payloads** — max-scroll-so-far, accumulated engaged time — and take **max/sum at rollup**. (This also reduces the "orphan `sc`/`tm` without `pv`" class behind our viewless visitor-days.)
- Caveats: 64 KiB sendBeacon cap; some ad-blockers block sendBeacon specifically (fetch+keepalive is the fallback).
- **Action item:** audit the theme beacon against this trigger pattern (it lives in the theme repo, not here).

## 5. Cloudflare AE — the hard envelope (all verified against live docs 2026-07-17)

- **Row shape: 20 blobs / 20 doubles / 1 index (≤96 bytes).** Our 64-char hex SHA-256 fits the index. **Our blob budget is already FULL (20/20** — per existing project memory), so any new dimension requires evicting or packing an existing blob.
- **Retention: fixed 3 months, non-configurable** → the WP daily rollup tables remain mandatory; every day must be aggregated well inside the window.
- **Sampling — the rules that keep queries honest:**
  - AE samples at write time (too many points per index) AND query time (ABR). Never assume unsampled rows.
  - Weight **everything** by `_sample_interval`: counts = `sum(_sample_interval)`; sums = `sum(x * _sample_interval)`; averages = `sum(x*_sample_interval)/sum(_sample_interval)`; quantiles via `quantileExactWeighted(q)(x, _sample_interval)`. Raw `count()` silently undercounts the moment sampling engages. **⚠ Phase A relevance: the planned `scroll_sum`/`time_sum` columns must use the weighted forms, and today's unweighted `avgIf(double1,…)` is technically wrong under sampling (harmless at our interval=1 volume, but fix it in the same pass).**
  - **The visitor hash MUST stay `index1`:** unique counts of non-index fields are unreliable under sampling (Cloudflare verbatim: rare non-index values may be entirely unobservable). Sampling is per-index-value (equitable).
  - Scale comfort: ~100 data points/sec per index value before sampling is noticeable — a personal-site workload writes unsampled. Cloudflare hedges that ABR error bounds are "difficult to prove" and advises against UUID-grade-cardinality indexes for performance; the per-visitor-hash index trades that off deliberately for unique-count accuracy (open question: benchmark at our scale).

## 6. The full rich-metric set achievable cookieless (the "go rich" menu)

| Metric | Source | JS needed? |
|---|---|---|
| Pageviews, paths | beacon `pv` | yes (existing) |
| Sessions, bounce, engaged, duration, pages/session | rollup gap-split on hash | no new JS |
| Entry/exit pages | first/last event per session | no new JS |
| Referrers / sources | `document.referrer` via beacon (or Referer header at Worker) | minimal |
| UTM campaigns | landing URL params via beacon | minimal |
| Geography (country/region) | **Worker `request.cf`** — server-side, zero JS | no |
| Device / browser / OS | **UA parsing at the Worker** — server-side, zero JS | no |
| Goals + in-visit funnels | custom events + session assembly (within a visit/day only) | existing `ce` |
| Scroll depth, time-on-page | beacon `sc`/`tm` (visibilitychange pattern, §4) | yes (existing) |
| Real-time | AE trailing-window query (existing realtime module) | no |

## 7. The honest hard limits (no technique removes these)

1. **No cross-day identity** → no returning-vs-new, no retention/cohorts, no cross-day frequency, no multi-day funnels. Every surveyed vendor accepts this by design.
2. **Multi-day "uniques" = sum of daily uniques (visitor-days)** — name them so.
3. **Within-day identity stays approximate** — shared IP + identical UA collide (undercount); same-day IP churn splits (overcount). No source quantified the net error vs. ground truth (unbenchmarked industry-wide).
4. **No cross-device identity**, even within a day.

## 8. Mapped recommendations for our stack (priority order)

1. **Worker: upgrade the hash** to `SHA-256(secret_daily_salt + domain + IP + UA)` with KV-stored salt, midnight-UTC cron rotation, old-salt deletion, brief dual-salt window. (Worker repo; ships via `npm run deploy` only.)
2. **Plugin: ship Phase A** (honest naming, gated visits, viewless count, exact engagement) — this research *validates* its semantics; add the `_sample_interval` weighting fix (§5) to the same pass.
3. **Theme: audit the beacon** for the visibilitychange→hidden + pagehide + incremental-payload pattern (§4).
4. **Plugin: assemble the rich dashboard** from what already exists (sessions engine, geography/device via Worker dims, UTM, realtime) under the honest vocabulary.
5. **Defer:** high-cardinality-index benchmarking; dual-salt boundary mechanics; the unanswered vendor survey (Fathom/Simple Analytics/CWA/Matomo — no claims about them survived verification; Plausible + GoatCounter carried the report).

## Caveats & open questions (verbatim from the verified synthesis)

- Identity findings rest on vendor self-descriptions — but both are open source and mechanisms were code-verified. The legal finding is 2-1, medium confidence, unsettled law. The 35%-CGNAT figure is 2021 (2025 follow-up says it's a conservative floor). Chrome's UA reduction weakens the UA-entropy gain. AE limits verified live but do move.
- Open: (1) does a UUID-grade-cardinality index degrade ABR/query performance at our scale? (2) dual-salt vs. accept midnight splits? (3) quantified within-day accuracy of salt+IP+UA post-UA-reduction? (4) do Fathom/Simple Analytics/CWA/Matomo differ materially?

### Key sources (primary)
plausible.io/data-policy · plausible.io/docs/metrics-definitions · plausible.io/security · github.com/plausible/analytics (Session.Salts, PR #5067; discussions #970/#1150/#1445/#1963) · goatcounter.com/help/{privacy,sessions,gdpr} · github.com/arp242/goatcounter (memstore.go) · developer.mozilla.org (sendBeacon) · Speedkit beacon-reliability benchmark · developers.cloudflare.com/analytics/analytics-engine/{limits,sampling,sql-api} + WAE FAQ · blog.cloudflare.com/multi-user-ip-address-detection
