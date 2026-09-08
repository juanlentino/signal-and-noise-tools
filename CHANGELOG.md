# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.107.3] - 2026-09-08 — Responsive Analytics without hiding information

### Changed
- Recompose native Analytics into visible period, traffic/exclusion, and action groups that wrap with the app window. Keep Range, Compare, Human/Suspect/Bot, filtered counts, CSV, JSON and Refresh available without a sheet or overflow menu. Increase the actual traffic-segment and export targets rather than shrinking controls.
- Present native insight signals as a readable status, full explanation and explicitly labeled confidence, replacing the oversized capsule. Keep the period summary, every signal and the existing Full insights disclosure behavior; classic admin presentation is unchanged.

### Fixed
- Seed Custom range from the selected period before server validation so switching from a rolling range opens editable dates instead of falling back to seven days. Preserve existing custom dates on subsequent filter changes.
- Give Range and Compare stable morph keys so toolkit-generated IDs do not cause focused controls to be replaced after a server response.

### Added
- Real Overview route fixtures for no-forecast and actionable-warning states, with toolkit keyboard/touch, popover Escape/focus, filter/date/Refresh round trips through PHP and DOM morph, export payload, disclosure, and native-window sizing/scroll regressions. Document the local harness and its non-production limits.

