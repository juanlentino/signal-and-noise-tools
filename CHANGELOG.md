# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.106.15] - 2026-09-07 — Restore Overview rail and align Analytics filters

### Fixed
- Restore the native Analytics header to the classic two-thirds Overview column with Uptime above Movers in the right-hand rail. Align the Human/Suspect/Bot selector with the Range and Compare fields.
