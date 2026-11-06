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
- **A purge can no longer sit on "verifying" until the next one.** On 2026-11-05 an automatic update's purge lost its deferred verify: no run in the cron history and nothing queued, so the Cache line stayed on "Purge dispatched, verifying". The hourly run now checks the last purge, and one still without a verdict after ten minutes gets a new verify from the theme's scheduler, which refuses a duplicate for the same epoch. A verdict of any kind, stale included, is left alone. `inc/purge-verify-heal.php` (new). Test: `tests/purge-verify-heal.php` (new).

## [2026.11.11] - 2026-11-04 — Spanish CV: Panacea founder line reworded

### Changed
- **/cv/: the Panacea Studio founder line reworded in the owner's words.** The move in 2021, the three partners brought in to run the studio, and the non-operating founder role, keeping ownership and strategic direction. Replaces the earlier line that said the same thing; the Spanish PDF regenerates with it. `inc/seed-content/resume-data-es.json`.

