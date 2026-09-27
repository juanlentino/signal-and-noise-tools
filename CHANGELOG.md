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
- **Provenance tags land on the hub.** The `provenance` tag no longer exists, so 19.1.0 sent `/tag/music-provenance/`, `/tag/cryptographic-provenance/` and `/tag/falsifiability/` to `/notes/`. They and `/tag/provenance/` now 301 to `/provenance/`. A retired-map value starting with `/` is a page path. A saved rule in the Redirects screen still runs first.

## [19.1.0] - 2026-09-27 — the north star shows its calibration; retired tags redirect

### Added
- **The north star shows its calibration.** `signal-noise/north-star` now carries `calibration`: four weeks of visitor-days that opened a core page, split by device (phone, desktop, unknown), with how many pass the live rule and how many would pass at 25/50/75% scroll or 15/30/60s dwell. Read-only; the definition stays the setting. The session query now reads the device.

### Fixed
- **Retired tag archives redirect.** The 83-to-23 tag pass (2026-08-15) ran through wp-cli, so it never filled the tag redirect map and Search Console kept 404s for the old `/tag/<slug>/` archives. `inc/tag-retired-map.php` carries the 61 retired slugs from `tag-merge-map.md`; each 301s to its surviving tag, or to `/notes/` when the tag was deleted or its survivor no longer exists. The resolver now matches the canonical `/tag/` form as well as `/notes/tag/`; a live term still wins.

