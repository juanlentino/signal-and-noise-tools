# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [21.8.2] - 2026-10-04 — SN Reading fits its card; SN Health without the reason line

### Fixed
- **SN Reading fits its card again.** Once session depth started arriving, "Two pages" and "Three or more" added two rows and pushed "Open Analytics" below the card's fold. They share one row now ("Two pages · three or more: 10% · 2%"). "Open Machine Readers" gets the same gap before its arrow as the other widget links. SN Health names a check that could not run without a reason line under it; the reason is on the Health tab, one link away.

