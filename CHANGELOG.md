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
- Restore the classic Provenance grouping in native S&N Home: a full-width status summary above Commits and conditional Ledger backfill on the left, with System, Key rotation, and Genesis anchor stacked on the right. Preserve all forms and collapse to one column at 640px.

## [13.106.18] - 2026-09-07 — Fix native Uptime contrast and dark PWA artwork

### Fixed
- Replace the OpenStation PWA’s white tiles with opaque dark variants that preserve the exact JL artwork, dimensions, and maskable inset. Use new manifest and Apple icon URLs so browsers can detect the updated art. Installed icons use a stable dark appearance independently of page CSS.
- Apply native OpenStation surfaces, borders, and readable foregrounds to asynchronously loaded Uptime tables, incident details, loading/error states, and status labels in Analytics and Home. Preserve status colors in dots and contain wide tables within the panel.

