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
- **AI › Agent tools: the refusals, counted.** Every write a door declined, set against the rule that declined it: one table of door, rule, 7 days and 30 days, busiest first, on both twins and as `refusals` inside sn-site-facts' `tool_telemetry`. A rule that never fired is not listed. Most counts come from the call log (refused, rejected, invalid and conflicting calls to write tools, sn-apply's gates among them). The two kinds decided before the log are now counted on their own: the write door's credential, kill switch and capability checks, and write abilities denied outside MCP (WordPress 7.1+). They live in one non-autoloaded option, a door, a rule and a count per day for 90 days, never a payload; a signed-out request is not counted. Observe only: no allow or deny decision changed.

### Changed
- **Measurement › Health: the scan's other readings share one row** (owner, live 2026-10-16). Passing checks and "Also scanned, shown elsewhere" stood one full-width box each; they are both what else the scan covered, of a height, so they sit side by side, with "Could not run" leading the row when a check could not. Layout only: every check, chip and link still paints.

## [2026.10.21] - 2026-10-16 — Last seen on Your devices moves on the owner's day

### Fixed
- **Your devices: Last seen moves on the owner's day.** It moved only when the marker was re-signed, once per UTC day, so a browser enrolled on an evening (already tomorrow in UTC) still read yesterday the next morning (live, 2026-10-16). Last seen now updates on the first wp-admin visit of each site-local day, at most one write a day; the marker's signing day is unchanged, so the Workers verify it as before.

