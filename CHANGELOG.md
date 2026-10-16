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
- **Six large modules split under the house line cap** (internal only; nothing changes on screen or in behavior). `inc/seo-schema.php`, `inc/deploy-workers.php`, `inc/analytics-admin.php`, `inc/mcp/mcp-tools.php`, `inc/rss-feed-tracker.php` and `inc/analytics-signals.php` each become a shell plus three `*-parts.php` files, a pure move: the shell's original lines plus each part minus its header rebuild the old file byte for byte, hooks keep their order, and every function is still declared once. Source-reading tests read through new by-name helpers in `tests/lib/inc-population.php`, and the two phpcs exclusions that named `inc/rss-feed-tracker.php` now name the two parts that need them.
- **Measurement › Health: the scan's other readings share one row** (owner, live 2026-10-16). Passing checks and "Also scanned, shown elsewhere" stood one full-width box each; they are both what else the scan covered, of a height, so they sit side by side, with "Could not run" leading the row when a check could not. Layout only: every check, chip and link still paints.

## [2026.10.21] - 2026-10-16 — Last seen on Your devices moves on the owner's day

### Fixed
- **Your devices: Last seen moves on the owner's day.** It moved only when the marker was re-signed, once per UTC day, so a browser enrolled on an evening (already tomorrow in UTC) still read yesterday the next morning (live, 2026-10-16). Last seen now updates on the first wp-admin visit of each site-local day, at most one write a day; the marker's signing day is unchanged, so the Workers verify it as before.

