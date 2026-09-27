# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.4.2] - 2026-09-27 — a rebuild replaces the day

### Fixed
- Re-rolling analytics now replaces each day it reads instead of only overwriting the rows that came back. Before, a path that dropped out of the fresh result kept its old stored row, so after the history recompute excluded an over-cap visitor, that visitor's views on pages nobody else opened that day stayed counted (7-day human views read 139 against about 62 in Analytics Engine). Every rollup the nightly and the recompute write (page views, referrer/country/device, campaigns, hour and scroll/time buckets, entry and exit pages, events and event properties) now deletes the stored rows for exactly the days its query fully covered and writes the fresh rows in the same transaction. A failed, empty or row-cap-truncated read deletes nothing, days outside the window are untouched, and each writer only clears its own rows (one dimension, one metric, entry or exit). Run Recompute analytics history once more to clear the stale rows already stored.

