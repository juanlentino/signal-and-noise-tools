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
- **Tag fit rides the advisory tier, not the fault count.** 16.8.0 put check 31 on the health surface, against the closed Health arc (v11.13.0: a check earns the red number only if its finding is a defect, can reach zero and stay there, and no other surface owns the list). A tag a note should carry never reaches zero; the names already itemize on `jev-tags`. Same move as `tag_hygiene` in v13.24.0: advisory tier, worklist surface, count in wp-admin, names on the door. The Health number reads 17 checks again. Pinned.

## [16.8.1] - 2026-09-19 — an edge reading is taken twice

### Fixed
- **Check 30 takes an edge reading twice.** "Recognition was always downstream" read 1.00 one day and 0.98 the next and flapped in and out of the red count, and three findings a judge says it is unsure about is not a number that should read red. The daily pass now keeps the previous pass's verdict beside the new one, and a note is listed only when two consecutive passes read the field below the line (per field: a description that held is listed while a title that flapped is not); a first reading with no previous pass still counts, a read being a read until the next one. The confidence caveat stays. Pinned (48).

