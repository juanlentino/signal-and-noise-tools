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
- **A CI flake in `tests/mcp-telemetry-read.php`** (62/63 on the 2026.11.8 cut, green on rerun). Four checks rebuilt a timestamp, to the second, with `$ago()` after the fixture had built it, so a second ticking between the two failed them. They now compare the fixture's own value. A forced 1-second pause reproduces CI's 62/63 on the old test and passes the new one.

## [2026.11.8] - 2026-11-02 — The résumé's Markdown follows the lane: /resume.md?lane=operations

### Changed
- **The résumé's Markdown follows the lane.** `/resume.md?lane=operations` (and `?output_format=markdown` on the operations lane) is the operations résumé: its headline and summary, What I've Built, its experience and sections, and no phone. On `/resume/?lane=operations` the page's `Link` header and head alternate name it, so an agent asking that address for Markdown gets that lane. The rights-signals Worker 1.37.2 reads the lane query; until then it converts the visible lane, as of 1.37.1. A section with nothing in it is left out of the Markdown. The music Markdown is unchanged byte for byte, pinned to main's output. `tests/resume-page-lanes.php` (+4).

