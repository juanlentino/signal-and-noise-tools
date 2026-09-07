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
- Added per-user OpenStation preferences for the Signal & Noise, S&N
  Dashboard, and S&N Analytics native apps while keeping every app registered.
- Polished native Dashboard and Analytics surfaces, bounded report tables, and
  empty states using OpenStation design tokens without changing their flows.
- Added mobile and standalone PWA safeguards for readable form controls,
  single-column report layouts, and safe-area-aware scrolling.

## [13.106.3] - 2026-09-07 — pure native windows and App Framework polish

### Fixed
- Restored pure OpenStation App Framework native windows for S&N Dashboard and
  S&N Analytics, excising the opt-out preference mechanism and registry removal
  filter.
- Resolved nested double-scrollbars and CSS cascade conflicts across native
  dashboard and analytics bodies.
- Added OpenStation design token and dark-scheme styling for Analytics custom
  date inputs and export buttons, and polished container sizing and
  focus-visible rings in the Signal & Noise app.
