# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.106.4] - 2026-09-07 — native window UI polish, Option B toggles, and mobile PWA support

### Fixed
- Added per-user OpenStation preferences for the Signal & Noise, S&N
  Dashboard, and S&N Analytics native apps while keeping every app registered.
- Polished native Dashboard and Analytics surfaces, bounded report tables, and
  empty states using OpenStation design tokens without changing their flows.
- Added mobile and standalone PWA safeguards for readable form controls,
  single-column report layouts, and safe-area-aware scrolling.
