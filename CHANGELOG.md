# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.106.13] - 2026-09-07 — Restore native OpenStation Analytics presentation

### Fixed
- Render Analytics report sections, metric cards and expandable tables with native OpenStation components, preserving shared calculations, rich drill links, report order and comparison charts. Keep Login defense within the scrollable report body.
