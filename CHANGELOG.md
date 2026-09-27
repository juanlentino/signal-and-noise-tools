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
- **Home's numbers stop shouting.** A tile whose previous week had under 50 shows the plain previous count ("prev 37") instead of a percentage (37 to 317 views read as +757%); Pages per visit checks both views and visits, since the ratio moves with either. The north star's four weeks are labelled numbers ("Aug 30 5 · Sep 6 9 · Sep 13 0 · Sep 20 4", this week in the accent colour) instead of a chart that was mostly empty axis at single-digit counts.

## [19.0.0] - 2026-09-27 — Home leads with the north star

### Changed
- **S&N Home leads with what it is for.** The north star is the hero: engaged readers at display size, its four weeks as an `os-histogram`, and beside it the three signals that matter now (resume PDF downloads, research links followed, notes shared), ruled in the pulse tiles' own language. The other twelve signals fold behind "All signals". Home stops repeating itself: the Publishing and Trust & Operations tile groups, the recent-deploys list and the second maintenance bar are gone (the Systems wall, the Deploy Status and Quick Actions widgets and the Integrity tab carry them); the Systems wall folds behind one line ("All 11 systems current", or how many need a look), whole, so freshness-dot.js still fills its Caches card; "All clear" paints nothing, since the greeting already says the site is healthy; queries that brought no clicks are hidden; Continue working shows three rows (View all still counts every item). About half the height. The weekly counts sit under the trend (`Weekly: 5 · 9 · 0 · 4`), since the histogram draws nothing for a zero week; the Engagement tile reads "Pages per visit" with a plain number instead of "v/s".

