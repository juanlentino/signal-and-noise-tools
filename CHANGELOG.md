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
- Bind Analytics and Dashboard styles to their native frame roots so card surfaces and responsive two-column containers apply; keep existing content order and collapse report/settings grids only at container widths of 640px or less.

## [13.106.10] - 2026-09-07 — Clean responsive two-column UI/UX for S&N Analytics and Settings leaves

### Added
- Responsive Two-Column Layouts: adjusted container query breakpoints across S&N Analytics and S&N Home settings leaves from 860px to 640px (`@container ( max-width: 640px )`), allowing multi-column layouts (`.snt-report-columns`, `.snt-grid`, and `.snt-2up`) to render as clean, side-by-side two-column views in desktop windows.
- S&N Analytics Inline-Size Containment: declared `container-type: inline-size` on `.os-app[data-os-app="sn-analytics"] .snt-view` to establish proper container query context.
- High-Density Data Tables: applied transparent table surfaces with subtle header backgrounds and compact numeric column widths (`width: 76px` for views/visits) in `dim_table()`, eliminating dead horizontal space between labels and metrics.

