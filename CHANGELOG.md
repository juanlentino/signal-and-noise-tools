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
- Render Analytics report sections, metric cards and expandable tables with native OpenStation components, preserving shared calculations, rich drill links, report order and comparison charts. Keep Login defense within the scrollable report body.

## [13.106.12] - 2026-09-07 — Restore native Analytics report parity

### Fixed
- Restore native Analytics report parity through the shared classic report dispatcher, including correct metric units, session paths, maps, distributions, lifecycle, search and defense diagnostics. Keep native tabs and effective controls, theme the shared reports with OpenStation tokens, and repair native-root navigation and chart brushing.
