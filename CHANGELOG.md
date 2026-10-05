# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The dual-write check accepts the same sample on both sides.** Analytics Engine samples ordinary visitor-days at write time (22.3.0's diagnostic: about one in six human visitor-days, typically 2 stored rows standing for 4; every figure has always carried these estimates), so a day with any sampled event could never match and analytics 2.0 could never start. The two pageview datasets were measured holding the same sample. An event type now counts as exact when both pageview datasets hold the same sample: the same totals AND, read visitor by visitor, the same sampled visitor-days with the same stored rows and weights. It is listed under `identical_sample`; a different sample stays inconclusive and an exact difference is still a mismatch. Owner-approved.

## [22.3.0] - 2026-10-05 — the analytics 2.0 freeze and the sampling diagnostic

### Added
- **The dual-write check names who was sampled.** Every day so far reads `sampled` at a volume Analytics Engine documents as never sampled; it samples per visitor-day hash, so a sampled day should trace to a few heavy visitor-days. On such a day the `analytics-dual-write` report lists the sampled visitor-days per dataset (rows stored against rows they stand for), whether each holds rows the human reads count (the same read-time rule) or is over the page-view cap, and how many are traffic the reads actually count. It says when it is inconclusive (a failed or cut-short read, or no over-cap list), and that it is a separate read, which Analytics Engine may resolve differently. Three more reads, on demand only; the daily check is unchanged.
- **The analytics 2.0 comparison ends when the old write stops.** Once the analytics worker reports 2.0.0 or later, the daily dual-write check no longer compares (a day with rows in one dataset only would read as a mismatch and send every read to a dataset with no new data). A good verdict (pageviews matched and the events dataset proven) freezes with its clean day, so reads stay on the new dataset; anything less is never frozen into a good one: it says why, and the `analytics_v2_frozen_bad` watch ripens. Before a mismatch replaces a good verdict, the worker version is asked again live, so a deploy inside the version cache's ten minutes freezes instead of clearing. Rolling the worker back to 1.x resumes the comparison.

### Changed
- **Gap analysis: the public label and the gap selection agree with the record.** `docs/proposals/proving-the-provenance-thesis.md` said twice that it "stays local" while public in this repository since 2026-08-17 (#695); the label now says so. Its Plan section named gaps 2 and 4 as selected, which contradicted the header and the resolved open question; it now names 2 and 3, and the status line, which still said "planning only", now says a gap that ships a step records it with its date. Docs only.
- **Gap analysis: gap 1 records what shipped.** `docs/proposals/proving-the-provenance-thesis.md` moves gap 1 from "blocked on publication" to step 1 shipped on 2026-10-05: the third paper splits identity into anchoring, which the ledger's new `weigh.mjs` addresses (signal-and-noise-provenance#40), and custody, which stays open, with deviation D-1 unchanged. Docs only.

