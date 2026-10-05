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
- **/stats: the calendar sits beside the reading-rhythm sentence on a wide page**, under a full-width chart, instead of leaving the right half empty. Reading order is unchanged (sentence, chart, calendar); under 900px it stacks as before.

## [22.1.0] - 2026-10-05 — /stats takes the wide page

### Added
- **/stats takes the wide page, as the owner approved.** The page, its title and rule use the theme's shared page track (1320px, as About, Resume and Services do) instead of the 760px text column. Where readers come from, How they read and Humans and machines sit as three columns that fold to two and then one; Most read keeps a readable line. The stylesheet now loads in the head on that page, so the title paints wide from the start.
- **A watch says why /stats has no machine figures.** The hourly refresh records each run; `public_stats_machines` ripens while the last run stored nothing (the note names the reason: a failed or capped sensor read, or one that does not reach the window's first day) or none has run in three hours.

### Fixed
- **/stats shows Visits and One page only through the nightly lag.** 22.0.0 hid both unless every day of the window had rolled up, and the session rollup records the day before at 21:45 UTC, so they were missing about 22 hours a day. A run of days from the window's first day now shows, with the count on the Visits tile ("(29 of 30 days)"); a hole inside the window or a missing first day still leaves them out.
- **SN Systems right-aligns a wrapped value.** A long health-check label pushed its count to its own line at the left edge; it now sits at the right like every other row.

