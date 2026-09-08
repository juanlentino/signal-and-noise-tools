# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.106.19] - 2026-09-08 — Restore the native Provenance status rail

### Fixed
- Restore the classic Provenance grouping in native S&N Home: a full-width status summary above Commits and conditional Ledger backfill on the left, with System, Key rotation, and Genesis anchor stacked on the right. Preserve all forms and collapse to one column at 640px.

