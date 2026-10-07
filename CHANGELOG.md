# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.9.4] - 2026-10-07 — the 2-row allowance covers an estimate too

### Changed
- **The 2-row allowance covers an estimate too** (owner rule 2026-10-07). The first live run of the 22.9.3 gate showed Oct 6's `vi` 2 vs 0 was not a lost row: the legacy dataset held one sampled row standing for 2, the new one held none. Independent sampling can land on zero on one side for any small event. An event other than pageviews whose two sides differ by at most 2 (rows and visitors) is now allowed whether either side is counted or estimated. Pageviews still get no allowance, and a gap of 3 is still a mismatch.

