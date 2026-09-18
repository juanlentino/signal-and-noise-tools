# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.4.1] - 2026-09-18 — the panel warns at 0.6

### Fixed
- **The pre-publish panel warns at 0.6, not 0.5.** The first live lane map (16.4.0, 44 published notes, 44 requests, 26 s, 485k input tokens) put 19 pairs at or above 0.5 and 9 at or above 0.6; the 0.5..0.6 band was shared vocabulary ("the master", "the field", "the absence") rather than a shared argument, and a draft near that cluster would have drawn four warnings. The panel's line is now 0.6; the record's `collisions` count and the lane map keep 0.5 so the map still shows the band. Pinned.

