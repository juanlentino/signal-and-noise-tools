# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.8.4] - 2026-09-26 — the edge 5xx rollup names which pages return which status

### Added
- **The edge 5xx rollup names which pages return which status (#1006).** It stored failing paths and responders as two separate dims, so there was no way to say which URL got a 520; on 2026-09-23..25 the 520s ran 75 to 110 a day, all on uncached PHP. A third dim, `err_path_status`, records `"<edge> <cache> <path>"` (for example `520 dynamic /wp-json/wp/v2/posts`) from the errors query the rollup already runs, capped at the column's 160 by cutting the path end, never the status. `edge-errors-summary` returns it as `paths_by_status` (top 10), and its remote twin with it, so the remote contract moves 9 to 10. It starts empty: days before this release have none, which means not recorded, not no errors.

