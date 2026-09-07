# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.106.12] - 2026-09-07 — Restore native Analytics report parity

### Fixed
- Restore native Analytics report parity through the shared classic report dispatcher, including correct metric units, session paths, maps, distributions, lifecycle, search and defense diagnostics. Keep native tabs and effective controls, theme the shared reports with OpenStation tokens, and repair native-root navigation and chart brushing.

