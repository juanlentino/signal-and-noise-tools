# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- Re-architected S&N Home (`apps/sn-dashboard`) to follow OpenStation's Station Home hierarchy: editorial canvas surface with ruled boundaries, stable left rail with brand mark, location badge (`• S&N HOME`), hero aurora mesh, time-aware personalized greeting, dynamic live orientation message, Site Pulse metrics, prioritized Needs Attention queue, Continue Working recency list, and subordinate Operations & Maintenance grid.
- Refined narrow and mobile viewport reflow for S&N Home: left rail transforms to a compact sticky top icon rail (active item shows icon + text, others icon-only).
- Re-aligned S&N Analytics (`apps/sn-analytics`) with OpenStation design principles: eliminated elevated outer card wrappers on `os-section` containers, set `align-items: start` on `.snt-grid` to prevent sparse column stretching, while preserving bounded 440px tables and stable scrollbar gutters.
- Streamlined OpenStation Settings preferences to Option B: per-user native window replacement toggles for S&N Home and S&N Analytics, with Signal & Noise remaining native-only.

## [13.106.4] - 2026-09-07 — native window UI polish, Option B toggles, and mobile PWA support

### Fixed
- Added per-user OpenStation preferences for the Signal & Noise, S&N
  Dashboard, and S&N Analytics native apps while keeping every app registered.
- Polished native Dashboard and Analytics surfaces, bounded report tables, and
  empty states using OpenStation design tokens without changing their flows.
- Added mobile and standalone PWA safeguards for readable form controls,
  single-column report layouts, and safe-area-aware scrolling.
