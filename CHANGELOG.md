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
- **Measurement › Search Console: Unanswered searches.** The queries Google showed the site for at least 5 times in the synced 28-day window that earned no click, most shown first, each with its impressions, average position and the page Google showed; a query shown on the home page or an index is marked "No note answers this". Capped at 25 with "+N more; the list is capped, not complete." Arithmetic only, no model reads it. The daily sync now also reads the query x page rows (500, stored compact beside the window; a failure there keeps the window), and a new read-only ability, `signal-noise/search-unanswered`, serves the list on the read door (a sibling, not a key on search-performance, whose remote twin's contract stays as it is). Painted on the native leaf and its classic twin, with honest empty states for no synced data and an empty window.

### Changed
- **Measurement › Health: the scan's other readings share one row** (owner, live 2026-10-16). Passing checks and "Also scanned, shown elsewhere" stood one full-width box each; they are both what else the scan covered, of a height, so they sit side by side, with "Could not run" leading the row when a check could not. Layout only: every check, chip and link still paints.

## [2026.10.21] - 2026-10-16 — Last seen on Your devices moves on the owner's day

### Fixed
- **Your devices: Last seen moves on the owner's day.** It moved only when the marker was re-signed, once per UTC day, so a browser enrolled on an evening (already tomorrow in UTC) still read yesterday the next morning (live, 2026-10-16). Last seen now updates on the first wp-admin visit of each site-local day, at most one write a day; the marker's signing day is unchanged, so the Workers verify it as before.

