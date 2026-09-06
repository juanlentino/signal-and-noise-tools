# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.106.1] - 2026-09-06 — native window polish

### Fixed
- Native Analytics focused tabs now open directly on their own reports instead
  of repeating Overview's insights, KPIs, and chart. The filter bar uses stable
  responsive rows, and custom date fields appear only when Custom is selected.
- Native Dashboard and Analytics windows use roomier cards and detail columns,
  readable supporting text, and proportional labels with monospace reserved for
  values, reducing the dense low-contrast wall visible in the first release.

