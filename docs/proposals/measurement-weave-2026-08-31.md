# The measurement weave — one join key, one grain rule, and the agent-side gap

> Origin: an extraction pass over [every-app/open-seo](https://github.com/every-app/open-seo)
> (MIT, TypeScript/Cloudflare Workers, a DataForSEO front-end with an MCP server and nine
> agent skills). The useful finding was not a thing to port. It was that the estate already
> holds more measurement than any of its agent doors can see.

**Goal:** make the estate's instruments joinable and agent-readable, without inventing
precision none of them has, and without buying market-side data before a decision depends
on it.

**Everything below was verified against the code on 2026-08-31.** Re-verify before
starting (the twice-burned rule).

---

## What is already true

### The weave spine exists

`snt_insights_collect_signals()` ([inc/insights.php](../../inc/insights.php)) already
assembles a cross-system payload and joins it **by relative permalink path**:

| § | Source | Grain |
|---|--------|-------|
| 1 | Site identity (settings) | site |
| 2 | Cloudflare AE — `sn_analytics_top_paths` / `range_totals` / `top_dimension`, human class, 7d | **path** |
| 3 | Post list (published, <2y) + tags/cats + body excerpt | **post** |
| 4 | Webhook summary | site |
| 5 | Cron freshness (`snt_cron_history`) | site |

There is no data-warehouse to build. There is a spine with two sources missing from it.

### GSC is woven for a human, not for an agent

Search Console feeds [analytics-recommendations.php](../../inc/analytics-recommendations.php)
(`rec_position_drift`, `rec_search_unclicked`, `rec_seo_meta`), the Search view, the
dashboard, `morning-brief.php`, and `search-console-crossexam.php`.

**It appears in zero `abilities-*.php` files and zero `inc/mcp/*` files.** Verified by
grep over the whole of `inc/`: every file matching `snt_gsc|search_console` is an admin,
render, derive, or client file. Not one door.

### Six derives are built and unexposed

`snt_gsc_metrics_for_path`, `snt_gsc_top_queries`, `snt_gsc_topic_interest`,
`snt_gsc_position_drift`, `snt_gsc_window_totals`, `snt_gsc_crossexam`. The sync already
pulls **both** dimensions daily — `page` (250 rows) and `query` (100) — normalizes paths,
impression-weights merged positions, and derives CTR after merging rather than averaging
rates. The instrument is good. Only its readership is short.

---

## The two rules the weave has to obey

### Rule 1 — one join key, and today there are three spellings

| Producer | Function | `/notes/foo/` → | `notes/foo` → | `''` → |
|---|---|---|---|---|
| Analytics | `sn_analytics_canonical_path` | `/notes/foo` | `notes/foo` | `''` |
| GSC | `snt_gsc_url_to_path` | `/notes/foo` | `/notes/foo` | `/` |
| Insights join map | inline `trim($path, '/')` | `notes/foo` | `notes/foo` | `''` |

They agree on the common case and disagree on leading-slash and empty inputs. A per-path
join across two of these silently **drops** rows rather than erroring — the failure mode is
a quieter dataset that still looks plausible, which is the worst kind.

**Decision: `snt_gsc_url_to_path` is the canonical spelling** (it is the only one that
guarantees a leading slash and handles a full URL). Analytics keeps its own function for
its own storage; the *weave* normalizes both sides through one shared helper, and a
fixture suite pins all three spellings against a table of adversarial inputs.

### Rule 2 — the grain rule: not everything can join at path

The machine-readers ledger aggregates to `{family, surface, day, hits}` and **carries no
path dimension** ([inc/machine-readers-api.php](../../inc/machine-readers-api.php) line 8).
The R6 scaffold already assumed that join existed and was wrong; `search-console-crossexam.php`
records the correction in its header and settles for coarse window agreement instead.

So the weave has two grains, and they must never be presented as one:

- **Grain A — per path.** AE views/engagement · GSC clicks, impressions, position, CTR ·
  post metadata · TF-IDF keyword candidates. All path- or post-keyed. Joinable.
- **Grain B — per window only.** Machine-readers ledger · uptime · deploy · RSS · cron.
  Contextual. Can qualify a Grain-A finding ("Google's position fell **and** search-family
  crawl hits fell in the same window"), never join to it.

Windows also do not line up: Google's ends ~3 days back because it is still counting;
AE runs to now. Every cross-instrument statement is an order-of-magnitude agreement check,
never an equality test.

---

## Phases

| Phase | Ships | Risk | Gated on |
|---|---|---|---|
| 0 ✅ v13.55.0 | The shared path normalizer + its adversarial fixture suite | Low | nothing |
| 1 ✅ v13.57.0 | GSC on the read door | Low — read-only over synced data | Phase 0 |
| 2 ✅ v13.57.0 | GSC into `collect_signals()` (Grain A) | Low — one section | Phase 0 |
| 3 ✅ v13.57.0 | The disagreement scan (TF-IDF ↔ GSC queries) — page-level "about X, found for Y" NOT derivable (no page×query dimension stored); shipped as a site-level query reading | Medium — new instrument | Phases 0–2 |
| 4 | Remote twins for the new reads | High — credentialed, contract bump | Phase 1 merged |
| 5 | Enum drift check against two upstream corpora | Low — read-only diff, no classifier change | nothing (independent of 0–4) |

### Phase 0 — the join key

One helper, one home, one fixture suite. Pin the empty string, a bare `notes/foo`, a full
`https://host/notes/foo/`, a query string, an anchor, a trailing double slash, and the
homepage. **Negative-control it**: feed a deliberately mis-spelled path and watch the join
count go red. A join test that passes against an unfixed normalizer is worse than none.

### Phase 1 — GSC on the read door

Per [default-to-consolidated-reads], this is **a section on an existing consolidated tool,
not a new tool**. Sections wrap the six built derives:

- `search_performance` — page rows and query rows over the stored window
- `search_drift` — `snt_gsc_position_drift()`
- `search_crossexam` — `snt_gsc_crossexam()`, with its "not a per-page join" caveat *in the
  payload*, not only in the code comment

Near-ranking opportunities (position ~8–20 carrying real impressions) are a filter over
rows already on disk — no new fetch, no new quota.

**Trap:** the sync stores a window that OVERWRITES on each run, and `SNT_GSC_PAGE_ROW_LIMIT`
is 250. `snt_gsc_window_totals()` can only report its sum as a **floor**. Any door section
that returns a total must carry that floor flag, or an agent will read a truncated sum as a
complete one.

### Phase 2 — GSC into the synthesis payload

A sixth section in `collect_signals()`, joined to the existing `views_map` through the
Phase 0 helper. The AI prompt then sees, per path, *what it earns in traffic* alongside
*what it earns in search* — today it sees only the first.

Follow the section-2 precedent exactly: a failed read degrades to `[]` for the prompt while
the dashboard surfaces the honest read-failure fold. A prompt must never be told a failed
read was an empty result.

### Phase 3 — the disagreement scan

The instrument neither OpenSEO nor GSC can build, because it needs both the corpus and the
search data:

> `keyword-candidates` (TF-IDF) says what a post **is about**.
> GSC queries say what it **is found for**.
> Where those disagree is the finding.

Three readings, and they are different problems:
- **About X, found for Y** — the page ranks for something it does not serve. Intent mismatch.
- **About X, found for nothing** — no impressions at all. A [contentless-page-seo-choke-point]
  or an indexation problem, and the cross-exam says which.
- **Found for X, about nothing** — thin content earning impressions. The best refresh candidate
  on the site.

Ship as a `sn-scan` section producing candidates, dismissible through the existing
`dismiss` path — not as prose, and not as an auto-applied change.

### Phase 4 — remote twins

`search_performance` and `search_drift` as byte-identical twins under the existing
totality-test discipline. `search_crossexam` is the one to think hard about: its payload
names paths, and the remote door's read scope is the owner's call, not mine.

---

### Phase 5 — enum drift check

Independent of 0–4. `snt_mr_valid_families()` is 19 values maintained **by hand and mirrored
across two repos** (`inc/machine-readers-api.php` ↔ the worker's `src/machine-readers.mjs`,
whose own comment demands both be extended together). Nothing checks that the enum still
matches the world. Standing rule: **derive lists, never remember them.** This one is
remembered, twice.

Two MIT-licensed **data** corpora make it checkable (see Appendix A for how they were
measured):

- `monperrus/crawler-user-agents` — 1,500 UA patterns, every one tagged, 12-tag vocabulary.
- `ai-robots-txt/ai.robots.txt` — 166 AI agents with `operator`, `function`, `respect`,
  `frequency`.

**They are a control, not a replacement.** The axes differ: our enum is vendor-first for AI
(13 named vendors), upstream is function-first and collapses all 13 into a single
`ai-crawler` tag. Adopting it as a classifier would destroy exactly the distinction the
ledger exists to make. Adopting it as a *diff* costs nothing and watches a blind spot.

Vendor both files into the repo (pinned, with the fetch date and upstream commit recorded);
a scheduled check re-fetches and reports a **two-way** difference:

```
family_drift:
  ours_unmatched:     families in snt_mr_valid_families() no upstream pattern maps to
  upstream_unmapped:  upstream tags with live ledger hits and no family of ours
                      → today: scanner (101 patterns), advertising (85), academic (37)
  vendor_gap:         ai.robots.txt operators absent from our 13 AI families
  respect_flips:      agents whose `respect` value changed since the pinned copy
  mirror_parity:      plugin enum vs worker enum — unequal is a RED, not a note
```

`mirror_parity` is the row that justifies the phase on its own: it is the only automated
check that the two hand-maintained copies still agree.

**The buckets this already names.** The v10.79.0 comment records the rows that forced
`unclassified-machine` into existence — `facebookexternalhit`, `meta-webindexer`, `Slackbot`,
`WhatsApp`, `ia_archiver`. Upstream classifies precisely those as `social-preview` (81
patterns) and `archiver` (40). That is not a proposal to reclassify them; `unclassified-machine`
is named on a different axis and stays. It is evidence the diff would have said something
true before we wrote the comment by hand.

**Traps.**
- Fail-closed on an unmeasured fetch. An upstream file that failed to download must read
  `UNAVAILABLE`, never an empty diff — an empty diff is indistinguishable from "no drift"
  and would report the healthiest possible result at the moment the instrument broke.
- Negative-control it: plant a fake family, confirm `ours_unmatched` goes red, remove it.
- Pin and diff; never fetch-and-trust at runtime. Upstream is a third party whose tag
  vocabulary can change under us — a silent vocabulary change must surface as drift, not
  be absorbed.

## What is deliberately NOT in this plan

**Buying market-side data.** Search volume, keyword difficulty, SERP composition, competitor
keyword sets, backlink profiles and LLM-citation share are all DataForSEO endpoints — OpenSEO
is structurally a well-built client for them. That is a vendor decision, not a porting
question, and no amount of PHP reaches it. Phases 0–3 cost nothing and use data already on
disk; do them first, and let them show whether a market-side spend would change a decision
you actually make. That is the only sound basis for it.

**A per-page ledger join.** It does not exist and inventing it would fabricate precision.

**A new tool.** Sections on tools that exist, per standing policy.

**Any AGPL or LGPL dependency.** See Appendix A — the licence, not the quality,
is what excludes them.

---

## Appendix A — the GitHub survey (2026-08-31)

Ten search axes across crawler classification, technical SEO, Search Console tooling,
`llms.txt`, structured data, WordPress SEO plugins and field vitals. What survived.

### Adoptable

| Repo | License | Stars | Why |
|---|---|---|---|
| `monperrus/crawler-user-agents` | MIT | 1,401 | 1,500 tagged UA patterns; Phase 5 control |
| `ai-robots-txt/ai.robots.txt` | MIT | 4,089 | 166 AI agents with `operator`/`function`/`respect`; candidate derivation source for the denied list, which currently lives in three specs |

Both are data files, not libraries — vendored and diffed, never linked. That is why they are
the recommendation.

### Rejected on licence

`gh repo view --json licenseInfo` returned **null for every repo queried**, including ones
plainly licensed. That was an artifact of the query, not a finding; re-measured through
`gh api repos/<owner>/<repo> --jq .license.spdx_id`:

| Repo | Licence | Verdict |
|---|---|---|
| `faisalman/ua-parser-js` | **AGPL-3.0** | **Excluded.** The most-starred result in the survey (10,185) and the one we cannot touch — relicensed from MIT at v2. Vendoring it into a plugin distributed as GPLv2-or-later forces the whole plugin to AGPL and breaks .org compatibility |
| `matomo-org/device-detector` | **LGPL-3.0** | Excluded for now. Usable, actively maintained, PHP — but forces GPLv3+, costing the "or later" flexibility at v2. Revisit only if a real need appears |
| `ua-parser/uap-core` | NOASSERTION | Unresolved. GitHub cannot detect it; needs a manual read before anyone relies on it |

### Rejected on quality and policy

The technical-SEO and Search-Console axes returned mostly MCP servers and agent skills days
to weeks old with stars in the teens (`librecrawl-technical-seo-audit-mcp` 38,
`houtini-ai/seo-audit` 14). **ADR-0001 excludes third-party agent skills outright**, so the
category is out on policy before quality is reached.

Real GPL WordPress SEO plugins exist (Rank Math, SEOPress, `elightup/slim-seo`). The estate
has its own schema, sitemap and OG layers; none is needed. `slim-seo` is worth reading for
its restraint, nothing more.

### Measurement caveats

- Two `gh search repos` queries returned **empty for subjects that demonstrably exist** —
  `"crawler user agents list"` found nothing while `crawler-user-agents` (1,401 stars,
  pushed two days earlier) matched immediately. Every empty result here was re-run with
  different phrasing before being treated as an absence.
- The Core Web Vitals / CrUX field-data axis returned empty and **was not resolved**. Given
  the artifact above, that is an unmeasured axis, not an empty one. Re-run before concluding
  anything about it.

