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
- **The operations résumé keeps its address when shared.** On `/resume/?lane=operations` the canonical and `og:url` said `/resume/`, and a phone's share sheet copies the canonical while a link preview reads `og:url`, so the lane was dropped. Both now keep `?lane=operations` while an operations résumé exists (`inc/resume-lane-address.php`), and the hreflang trio is left out there, as the Spanish CV has no operations lane. An owner canonical override still wins; any other lane value is the music lane, so nothing a visitor types reaches the head. The theme's switch retargets both tags when the lane changes without a reload. `tests/resume-lane-address.php` (10).

## [2026.11.8] - 2026-11-02 — The résumé's Markdown follows the lane: /resume.md?lane=operations

### Changed
- **The résumé's Markdown follows the lane.** `/resume.md?lane=operations` (and `?output_format=markdown` on the operations lane) is the operations résumé: its headline and summary, What I've Built, its experience and sections, and no phone. On `/resume/?lane=operations` the page's `Link` header and head alternate name it, so an agent asking that address for Markdown gets that lane. The rights-signals Worker 1.37.2 reads the lane query; until then it converts the visible lane, as of 1.37.1. A section with nothing in it is left out of the Markdown. The music Markdown is unchanged byte for byte, pinned to main's output. `tests/resume-page-lanes.php` (+4).

