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
- Keep the native Analytics Overview headline and chart full width, with Uptime and Movers below in report order. Use OpenStation theme colors for referrer-category chips and report descriptions so they remain readable in dark and light windows. Remove nested Search table frames and code-label backgrounds, and restore contrast to Login defense decision chips.

## [13.106.13] - 2026-09-07 — Restore native OpenStation Analytics presentation

### Fixed
- Render Analytics report sections, metric cards and expandable tables with native OpenStation components, preserving shared calculations, rich drill links, report order and comparison charts. Keep Login defense within the scrollable report body.
