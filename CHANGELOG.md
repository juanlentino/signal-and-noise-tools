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
- Bound the MCP read budget to fixed minute windows instead of extending the same counter on every accepted request. Keep the 120-request cap and existing permission/kill-switch behavior; use atomic persistent-cache increments and report the remaining-window retry delay.
- Keep timestamped last-known Deploy Status and Uptime data during temporary fetch failures, with explicit stale notices rather than current-green status. Back off after failures, respect retry delays, avoid overlapping polls, and cancel pending requests on widget teardown.
- Recompose native Analytics so headline metrics and charts precede routine forecast and release commentary while anomalies remain prominent. Retain controls, counts, explanations and exports; tighten phone spacing, align desktop period controls, and keep uptime table columns reachable. Classic header output remains unchanged.

### Added
- Deterministic rate-window and widget recovery/cancellation tests, plus populated Overview composition checks covering Uptime, Movers, annotations, sessions and scroll reachability.

## [13.107.3] - 2026-09-08 — Responsive Analytics without hiding information

### Changed
- Recompose native Analytics into visible period, traffic/exclusion, and action groups that wrap with the app window. Keep Range, Compare, Human/Suspect/Bot, filtered counts, CSV, JSON and Refresh available without a sheet or overflow menu. Increase the actual traffic-segment and export targets rather than shrinking controls.
- Present native insight signals as a readable status, full explanation and explicitly labeled confidence, replacing the oversized capsule. Keep the period summary, every signal and the existing Full insights disclosure behavior; classic admin presentation is unchanged.

### Fixed
- Seed Custom range from the selected period before server validation so switching from a rolling range opens editable dates instead of falling back to seven days. Preserve existing custom dates on subsequent filter changes.
- Give Range and Compare stable morph keys so toolkit-generated IDs do not cause focused controls to be replaced after a server response.

### Added
- Real Overview route fixtures for no-forecast and actionable-warning states, with toolkit keyboard/touch, popover Escape/focus, filter/date/Refresh round trips through PHP and DOM morph, export payload, disclosure, and native-window sizing/scroll regressions. Document the local harness and its non-production limits.

