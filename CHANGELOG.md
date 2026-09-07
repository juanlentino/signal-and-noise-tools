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
- Refresh the Cache widget from the authoritative freshness ability after purge actions and while verification is pending. Keep historical post-save counts separate from the current verdict, show read failures, and preserve purge errors in command feedback.
- Match Signal & Noise Preferences to native Features checkbox cards, add its sidebar glyph, and serialize preference saves so rapid edits or reopening the panel cannot leave stale controls. Use the native warning palette and visible focus styling for Analytics attention links.

## [13.106.15] - 2026-09-07 — Restore Overview rail and align Analytics filters

### Fixed
- Restore the native Analytics header to the classic two-thirds Overview column with Uptime above Movers in the right-hand rail. Align the Human/Suspect/Bot selector with the Range and Compare fields.
