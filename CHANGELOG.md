# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- Responsive Two-Column Layouts: adjusted container query breakpoints across S&N Analytics and S&N Home settings leaves from 860px to 640px (`@container ( max-width: 640px )`), allowing multi-column layouts (`.snt-report-columns`, `.snt-grid`, and `.snt-2up`) to render as clean, side-by-side two-column views in desktop windows.
- S&N Analytics Inline-Size Containment: declared `container-type: inline-size` on `.os-app[data-os-app="sn-analytics"] .snt-view` to establish proper container query context.
- High-Density Data Tables: applied transparent table surfaces with subtle header backgrounds and compact numeric column widths (`width: 76px` for views/visits) in `dim_table()`, eliminating dead horizontal space between labels and metrics.

## [13.106.9] - 2026-09-07 — S&N Analytics UI/UX design polish and card containment

### Added
- S&N Analytics UI/UX Polish: restored elevated card surfaces (`--os-ui-surface-elevated`, `--os-ui-border`, 10px radius) to `os-section::part( body )` around tables, distributions, and empty states across all 13 views, eliminating naked wireframe text and infinite canvas gaps.
- Executive Briefing Insights Card: formatted Overview insights into a structured `.snt-insights-card` with clear lead hierarchy, chips, and a hairline divider for methodology notes.
- Pill Navigation View Doors: converted the Overview doorway links into interactive secondary button pills (`os-button[variant="secondary"]`) within a dedicated `.snt-doors` strip with an uppercase "Jump to view:" label.
- Hero Empty States: centered and framed full-view empty states (`.snt-view > os-empty-state`) within elevated card containers with subtle drop shadow and comfortable padding.

