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
- S&N Analytics UI/UX Polish: restored elevated card surfaces (`--os-ui-surface-elevated`, `--os-ui-border`, 10px radius) to `os-section::part( body )` around tables, distributions, and empty states across all 13 views, eliminating naked wireframe text and infinite canvas gaps.
- Executive Briefing Insights Card: formatted Overview insights into a structured `.snt-insights-card` with clear lead hierarchy, chips, and a hairline divider for methodology notes.
- Pill Navigation View Doors: converted the Overview doorway links into interactive secondary button pills (`os-button[variant="secondary"]`) within a dedicated `.snt-doors` strip with an uppercase "Jump to view:" label.
- Hero Empty States: centered and framed full-view empty states (`.snt-view > os-empty-state`) within elevated card containers with subtle drop shadow and comfortable padding.

## [13.106.8] - 2026-09-07 — Responsive two-column layouts and visual analytics polish

### Added
- S&N Home Multi-Column Leaves: added responsive two-column `.snt-2up` layouts to `Measurement > Machine Readers`, `AI > Models & Budget`, `Measurement > Insights`, and `Measurement > Search Console`, unnesting redundant card wrappers (`.snt-col` -> `.snt-2up-col`) and pairing forms with status panels.
- S&N Analytics Report Balance: aligned `Geography` (moving `Countries` inside `.snt-grid`), `Campaigns`, and `Login Defense` into balanced responsive multi-column layouts using `.snt-report-columns`.
- Responsive Grid & Badge Polish: added `.snt-stats` responsive CSS grid rules (`repeat(auto-fit, minmax(160px, 1fr))`) in shared `assets/os-app.css` to prevent KPI card stacking, wrapped sensor badges in `<os-cluster gap="8">`, and ensured `os-row` collapses cleanly under `@container (max-width: 860px)` and mobile PWA.

