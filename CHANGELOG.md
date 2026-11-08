# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.17] - 2026-11-08 — The exact daily edge totals are stored again

### Fixed
- **The exact daily edge totals are stored again.** The daily rollup asked `httpRequests1dGroups` for 395 days at once, and this zone refuses a window wider than 52 weeks, 1 day and 1 hour (the dataset's `maxDuration`, read live 2026-11-08). The whole request failed every day, unlogged, so Traffic & edge read Edge requests 0, Bandwidth 0 B, every status code 0 and no countries. `SN_EDGE_BACKFILL_DAYS` is 364, and the first run after install back-fills the year. Test: `tests/edge-rollup.php` (the daily window fits the limit; it failed at 395).

