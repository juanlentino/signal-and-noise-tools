# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.6.7] - 2026-10-06 — links on the colophon band are underlined

### Fixed
- **Links on the colophon's Kept honest band were invisible until hovered.** On the band a link takes the text's color, and the theme removes link underlines, so the maturity index, pair programmer, OpenStation, Daniel López Sánchez and the record's note title read as plain text. They are now underlined at rest in the signal red, thicker on hover and focus.

