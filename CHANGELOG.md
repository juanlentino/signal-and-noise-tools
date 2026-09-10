# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- The `/notes` position-drift watch now records that its window is confounded. The page changed materially between the watch being set and its 2026-09-11 due date — the pillar rail arrived in the hero, the corpus stamp left it, the heading outline changed, and the first note moved twice — so a worse position on that date cannot be attributed to drift rather than to the changes. The watch says so rather than leaving the next reader to infer it.

## [13.109.0] - 2026-09-09 — the coverage sweep sees the whole site

### Fixed
- The weekly search-coverage inspection now covers the whole public site — posts, Pages **and tag archives** — not just posts. It walked `post` only, so /provenance, its three essays, the maturity pages and Start Here had never been inspected — and this map is the only thing that can tell "not indexed" apart from "indexed with nobody searching for it". Their zero-impression readings looked like findings when they were unanswered questions. Tag archives are not even in the sitemap (core emits posts and pages only) yet six of them earn impressions, so Google reached them by following links — a URL that ranks and has never been inspected is the exact blind spot this map exists to close. The per-run cap was never the limit (200 against an API allowing 2,000/day, for ~40 posts + ~28 pages + ~23 tags); the post-type filter was. Targets are now keyed `post:<id>` / `term:<id>`, because term and post ids are independent sequences that would otherwise collide and silently drop one URL.

