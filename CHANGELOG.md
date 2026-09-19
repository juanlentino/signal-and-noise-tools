# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.8.2] - 2026-09-19 — an advisory is not a fault

### Fixed
- **Tag fit rides the advisory tier, not the fault count.** 16.8.0 put check 31 on the health surface, against the closed Health arc (v11.13.0: a check earns the red number only if its finding is a defect, can reach zero and stay there, and no other surface owns the list). A tag a note should carry never reaches zero; the names already itemize on `jev-tags`. Same move as `tag_hygiene` in v13.24.0: advisory tier, worklist surface, count in wp-admin, names on the door. The Health number reads 17 checks again. Pinned.

