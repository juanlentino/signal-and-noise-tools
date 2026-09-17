# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.9.1] - 2026-09-17 — the note keeps its own name

### Changed
- **A note's title tag carries no site-name suffix.** The 2026-09-17 audit read the 43 notes' title tags at a median of 84 characters with "— Juan Lentino" at the end; Google cuts the display near 60 and paints the site name on its own line from the WebSite schema (every page emits it), so the suffix was the part that got cut and said nothing the schema does not. `sn_seo_resolve_singular_title()` returns the bare title for posts; pages, the archives and the 404 keep the "Page — Site" shape. Pinned both ways in `tests/seo-title-override.php`.

