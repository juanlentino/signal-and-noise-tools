# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **The rollup-replace test no longer fails near midnight.** `tests/analytics-rollup-replace.php` built its expected window days from a separate "now", while `sn_analytics_rollup_window_days()` reads its first day at now+300 s and its last at now-300 s. On 2026-10-04 at 23:56 to 23:58 UTC the bounded-batch assertion failed on main and on CI, then passed minutes later. The test now captures the exact second the code read and builds both expectations from it, with the same skew and zone. It also expects six days instead of seven while the skew straddles a UTC midnight, which is what the code names then. Verified under a fixed fake clock at 12:00, 23:58, 00:02, 03:58 and 04:02 UTC: the old test fails at 23:58 and 00:02, the new one passes at all five. Tests only, no release.

## [21.8.2] - 2026-10-04 — SN Reading fits its card; SN Health without the reason line

### Fixed
- **SN Reading fits its card again.** Once session depth started arriving, "Two pages" and "Three or more" added two rows and pushed "Open Analytics" below the card's fold. They share one row now ("Two pages · three or more: 10% · 2%"). "Open Machine Readers" gets the same gap before its arrow as the other widget links. SN Health names a check that could not run without a reason line under it; the reason is on the Health tab, one link away.

