# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.12] - 2026-11-06 — A purge that lost its verify gets a new one

### Fixed
- **A purge can no longer sit on "verifying" until the next one.** On 2026-11-05 an automatic update's purge lost its deferred verify: no run in the cron history and nothing queued, so the Cache line stayed on "Purge dispatched, verifying". The hourly run now checks the last purge, and one still without a verdict after ten minutes gets a new verify from the theme's scheduler, which refuses a duplicate for the same epoch. A verdict of any kind, stale included, is left alone. `inc/purge-verify-heal.php` (new). Test: `tests/purge-verify-heal.php` (new).

