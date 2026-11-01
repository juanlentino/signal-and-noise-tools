# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.4] - 2026-11-01 — Stored scroll per view is counted by pageview ID

### Fixed
- **Stored scroll per view is counted by pageview ID, so it no longer passes 100%.** Read live on 2026-11-01: 21 of the 500 busiest stored path-days read above 100% scroll per view, holding 41 of 1,226 views. The worst was `/` on 2026-09-07 at 400% on one view, and the same leak showed in the bot class.

  The beacon sends each milestone once per view, so the extra milestones are `sc` events whose pageview row was never stored. The likely cause is the collector's per-visitor limit, not yet proven.

  The daily rollup now runs a third query, `inc/analytics-rollup-pid-scroll.php`. It reads each second-generation view's deepest milestone by pageview ID (`double11`, recorded since 2026-10-05), and counts only views whose pageview row is in the same day, path and class. An `sc` event with no stored pageview counts nowhere. This feeds the stored `scroll_events` and `scroll_sum`; the legacy `scroll_avg` keeps its raw per-event ratio.

  It falls back to the plain event count, as before, in three cases:
  - a window read from the legacy dataset, which has no pageview ID;
  - a failed read;
  - a read cut by the row cap.

  The daily roll rewrites the trailing seven days. Earlier second-generation days take the per-view count when the history recompute next runs. The exception is a recompute batch whose window starts before 2026-10-05: it reads the legacy dataset and keeps the plain count, which on 2026-11-01 covers roughly 2026-10-05 to 2026-10-11. `tools/reroll-analytics-90d.php` runs the same per-view read through its own one-day transform, so it stays the cron's twin.

  Review fixes (independent review of #2340): pins on the per-view read's alignment with the main read (day bucket, class expression, `WHERE` with lower bound, upper bound and over-cap tail, folded or not, zoned or not), the run's zone and fold fallbacks, and the reroll tool's transform.

  Tests: `tests/analytics-rollup.php` (237) and `tests/reroll-analytics-90d.php` (67); the dialect guard now scans the new file. Each of these mutants was seen red:
  - a pageview-less view counted as one;
  - the row-cap refusal skipped;
  - the upsert ignoring the per-view count;
  - the run skipping the merge;
  - each of six alignment drifts: zone, fold and over-cap tail in the builder or the run, and the upper bound;
  - the reroll transform losing its upper bound, a reroll write dropping the per-view merge, its pre-flight drift check removed.

