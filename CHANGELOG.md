# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **The analytics 2.0 comparison ends when the old write stops.** Once the analytics worker reports 2.0.0 or later, the daily dual-write check no longer compares (a day with rows in one dataset only would read as a mismatch and send every read to a dataset with no new data). A good verdict (pageviews matched and the events dataset proven) freezes with its clean day, so reads stay on the new dataset; anything less is never frozen into a good one: it says why, and the `analytics_v2_frozen_bad` watch ripens. Before a mismatch replaces a good verdict, the worker version is asked again live, so a deploy inside the version cache's ten minutes freezes instead of clearing. Rolling the worker back to 1.x resumes the comparison.

### Changed
- **Gap analysis: the public label and the gap selection agree with the record.** `docs/proposals/proving-the-provenance-thesis.md` said twice that it "stays local" while public in this repository since 2026-08-17 (#695); the label now says so. Its Plan section named gaps 2 and 4 as selected, which contradicted the header and the resolved open question; it now names 2 and 3, and the status line, which still said "planning only", now says a gap that ships a step records it with its date. Docs only.
- **Gap analysis: gap 1 records what shipped.** `docs/proposals/proving-the-provenance-thesis.md` moves gap 1 from "blocked on publication" to step 1 shipped on 2026-10-05: the third paper splits identity into anchoring, which the ledger's new `weigh.mjs` addresses (signal-and-noise-provenance#40), and custody, which stays open, with deviation D-1 unchanged. Docs only.

### Removed
- **The publish-time AI auto-fill.** When a note or page went live with an empty meta description, OG card title or excerpt, a background job filled them with no review step. It is removed (owner decision 2026-10-05), so no model text reaches a published field without a person applying it: the editor's Suggest and Apply buttons are the path for all three. Text the job already wrote keeps its "auto-generated at publish" notice until a person saves or dismisses it; the notice, its dismiss ability and the sentinels stay until none is left on the site. Removed with it: the `snt_prepop_event` handler, schedule and deactivation entry (an event still queued at deploy fires once with no handler), `snt_prepop_passes_content_gate()`, the `SNT_PREPOP_*` constants and the `snt_prepop_min_words`, `snt_prepop_schedule_jitter_max` and `snt_prepop_daily_call_ceiling` filters (no caller here or in the theme). No figure on any screen came from the job. AI.md drops its exception and the public AI-maturity page says nothing is generated at publish. Pinned in `tests/prepop-removed.php`, which fails against the old file.

## [22.2.0] - 2026-10-05 — analytics reads stitch across the 2.0 clean day

### Added
- **Analytics reads that cross the 2.0 clean day read each dataset for its own side.** Split at New York midnight on the clean day (when the worker rotates the visitor hash), so no visitor-day straddles it and sums and distinct counts merge exactly. The over-cap visitor list, which feeds every human/bot filter, is the first read to do so: once the dual-write check turns green it is built from the old dataset before the split and the new one from it, so the stitch is proven live during the seven green days before analytics 2.0.0 stops the old write. A failed half fails the read, never half a list. Nothing changes before that first green verdict.
- **The other analytics reads that reach back past the 2.0 clean day are stitched too.** Percentiles take each side's value distribution, merged, and compute p50/p75/p90 exactly the way Analytics Engine does; the drilldown's top pages add per-path views and visits; session events (the north star's 12-week trend) join their rows; the bot-signal readout counts the UTC day holding the split once. A failed half fails the read, and a half at the row cap gives no percentile rather than an approximate one. The seven-day nightly rollups need no split: by the time the old write stops they read only days the new dataset holds.

### Changed
- **AI.md: the rule cites Google's generative AI guidance as an outside reference.** One paragraph under "The rule" links Google's guidance (revised 2026-10-01), which asks for a manual review of AI-generated titles, meta descriptions, structured data and alt text before publishing. It dates the rule here to 2026-07-30, the 10.10.0 entry that first stated human review as a principle, and names the one path that falls short: the background prepopulation that can fill an empty meta description, OG card title or excerpt on a note or page with no review step. Docs only; no test pins AI.md's text.

