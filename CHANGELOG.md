# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.0.4] - 2026-09-27 — reading time adds up its slices

### Fixed
- **Reading time adds up its slices.** Since the beacon's v10.44.4 (2026-07-18) each tab switch sends the time since the last send, a delta, so a read split by tab switches arrives as slices; the north star and the within-day visit summary (the Visits view's engaged reads) compared the LARGEST slice with their floors instead of the sum. A 50-second read across two switches counted as 25. Both now sum per page, so every week since July 18 is recounted, and the north star's trend with it. Tests pin a split read crossing each floor; keeping the old max fails them.

