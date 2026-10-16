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
- **Analytics › Search: index coverage history is kept, not overwritten.** Each completed weekly URL Inspection run now also appends one summary reading (inspected, indexed, not indexed, crawled but not indexed, unknown, errors, and the median days since Google last crawled) to `snt_gsc_coverage_history`, a non-autoloaded option capped at 104 readings (two years). The Index coverage panel and its native twin state what moved since the previous run and list the last eight readings; under two readings they say "History starts with the next weekly run." The `signal-noise/search-coverage` ability gains a `history` key; every existing key is unchanged.

### Changed
- **Measurement › Health: the scan's other readings share one row** (owner, live 2026-10-16). Passing checks and "Also scanned, shown elsewhere" stood one full-width box each; they are both what else the scan covered, of a height, so they sit side by side, with "Could not run" leading the row when a check could not. Layout only: every check, chip and link still paints.

## [2026.10.21] - 2026-10-16 — Last seen on Your devices moves on the owner's day

### Fixed
- **Your devices: Last seen moves on the owner's day.** It moved only when the marker was re-signed, once per UTC day, so a browser enrolled on an evening (already tomorrow in UTC) still read yesterday the next morning (live, 2026-10-16). Last seen now updates on the first wp-admin visit of each site-local day, at most one write a day; the marker's signing day is unchanged, so the Workers verify it as before.

