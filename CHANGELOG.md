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
- **The Article's `dateModified` is the provenance commit, not the row's save.** A title-tag override on every note (2026-09-17) bumped `post_modified` on all 43 and told Google every note changed that day. `sn_schema_date_modified()` reads `_sn_prov_last_commit_gmt`, the moment the chain last committed the normalized prose (the stale-posts check's clock since 11.11.8), and falls back to `post_modified` only for a post without a commit. Pinned both ways. Theme 13.2.7 does the same for the reader-visible "Updated" line.

### Added
- **The Article says it is free to read and names its license**: `isAccessibleForFree: true` and `license` pointing at the RSL file the edge already links as `rel="license"`. What the headers say, in the schema an answer engine reads.

## [15.9.1] - 2026-09-17 — the note keeps its own name

### Changed
- **A note's title tag carries no site-name suffix.** The 2026-09-17 audit read the 43 notes' title tags at a median of 84 characters with "— Juan Lentino" at the end; Google cuts the display near 60 and paints the site name on its own line from the WebSite schema (every page emits it), so the suffix was the part that got cut and said nothing the schema does not. `sn_seo_resolve_singular_title()` returns the bare title for posts; pages, the archives and the 404 keep the "Page — Site" shape. Pinned both ways in `tests/seo-title-override.php`.

