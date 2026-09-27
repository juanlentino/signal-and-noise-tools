# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.0.1] - 2026-09-27 — Home's numbers stop shouting

### Changed
- **Home's numbers stop shouting.** A tile whose previous week had under 50 shows the plain previous count ("prev 37") instead of a percentage (37 to 317 views read as +757%); Pages per visit checks both views and visits, since the ratio moves with either. The north star's four weeks are labelled numbers ("Aug 30 5 · Sep 6 9 · Sep 13 0 · Sep 20 4", this week in the accent colour) instead of a chart that was mostly empty axis at single-digit counts.

