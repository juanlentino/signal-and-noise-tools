# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [20.7.1] - 2026-10-03 — Breeze's timed purge is off

### Fixed
- **Breeze's timed purge is off.** Its "Purge Cache After" field will not take 0: the form saved 1440, and 1 would have purged every minute. So the setting cannot turn it off, and the plugin unhooks Breeze's `breeze_purge_cache` handler and its rescheduler and clears the event, as 20.7.0 did for Breeze's update purge (owner, 2026-10-03). Pages purge on save and once per update; the nightly run only emptied a correct cache. A Breeze settings save can schedule it again, and the next request clears it. The purge ledger's `breeze_nightly` reads `off`. Pinned in `tests/purge-ledger.php`.

