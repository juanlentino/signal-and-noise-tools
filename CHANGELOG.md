# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.5.1] - 2026-09-18 — the floor is the site's scale

### Fixed
- **The fit floor is 5 impressions, not 20, and `jev-query-fit` shows what was judged.** The first live fit pass (16.5.0) judged one note on two queries: Search Console's 28-day window held 469 impressions across 47 pages, with page impressions running 229, 40, 38, 22, 15…, so a 20-impression floor on a page × query pair left one pair standing. The floor is 5, which is the site's scale rather than the rubric's; the pass is still one request per note. The read ability now returns `judged_notes` (every note judged with its rows: query, counts, score, confidence) beside `gaps` and `stray`, so the reading can be checked against what was asked. Pinned.

