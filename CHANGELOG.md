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
- Restore the native Analytics header to the classic two-thirds Overview column with Uptime above Movers in the right-hand rail. Align the Human/Suspect/Bot selector with the Range and Compare fields.

## [13.106.14] - 2026-09-07 — Polish native Analytics layout and contrast

### Fixed
- Keep the native Analytics Overview headline and chart full width, with Uptime and Movers below in report order. Use OpenStation theme colors for referrer-category chips and report descriptions so they remain readable in dark and light windows. Remove nested Search table frames and code-label backgrounds, and restore contrast to Login defense decision chips.
