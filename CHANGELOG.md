# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.3.2] - 2026-09-15 — the Home reads where its questions are asked

### Changed
- **S&N Home: the audience lists leave Operations.** The Detail box under Operations held Recent deploys and API limits next to Top pages, Top sources and Top queries: audience numbers under the ops heading. Every panel now names its group; the audience three paint under Site pulse as "Audience detail, 7 days", the ops two stay as "Operations detail". Top queries names its column, "clicks, 28 days", so a list of zeros reads as what it is. The classic screen paints the same two blocks, audience first. Pinned on the panels, the native painter and the classic renderer.

### Fixed
- **S&N Home: Caches read "Checking…" under a line that said "verified fresh".** The card is async: `freshness-dot.js` finds it by id and writes the live verdict into `.sn-glance-card__value`. The classic wall carries both since v11.30.1; the native port dropped them, so the script appended its verdict under a placeholder it could not replace. The native wall now carries the id and the class; the verdict replaces the placeholder in place.

