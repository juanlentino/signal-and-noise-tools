# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.2.4] - 2026-09-18 — one page, not one row per note

### Fixed
- **`/verify` carries a description and a self-canonical.** The page is `noindex, nofollow` and stays so, but a site scanner (Bing's, 2026-09-18) audits what it crawls and listed every `?note=` variant as a page without a description: 44 rows today, one more for every note the queue publishes. The standalone document now says what it is in a meta description and names the bare `/verify` as its canonical, so the variants fold into one described page for any reader that honours either. Pinned on every variant.

