# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.9.0] - 2026-09-17 — the title says what the note is about

### Added
- **Health check 28, notes without a query-shaped title.** The 2026-09-17 pressure test against Search Console found 40 of 43 notes with a title tag that is the aphorism alone, 21 never shown to anyone, and half of all impressions on an accidental match. The H1 stays the voice; the `_sn_seo_title` override carries the plain words a reader would search, in the shape the ranking notes already use ("The estate cannot sign: key succession and music provenance"). A defect that reaches zero one line at a time, so the gap cannot silently reopen with the next scheduled note. Pure judge (`sn_health_search_title_is_shaped`, `sn_health_search_titles_judge`), the query as the thin part, the three registries (scan, surface, family) and the loader pinned.

### Fixed
- **The Dashboard leaf's Caches tile sat on "Checking…" under a meta line that said "verified fresh".** Measured live in the owner's shell: OpenStation's runtime inserts a bare `<div>` and sets `class="snt-app …"` and `data-os-app` afterwards by attribute morph, so `assets/os-host.js`, which only scanned added nodes, never hosted a root (13 analytics roots, zero `snt:paint` events) and the freshness hydrator never re-armed after a repaint. The host's document observer now also takes `class` and `data-os-app` attribute records and re-scans their target; `host()` is idempotent. Re-hosting the root by hand filled the tile with "3/3 fresh" in under a second, which is the fix's proof.

