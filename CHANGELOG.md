# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.3.1] - 2026-10-08 — dimmer share bars, version-stamped card caches, a Deploy Status that fits

### Fixed
- **A release's new card layout shows at once.** SN Traffic's and SN Reading's payload caches were keyed by the day only, so 23.3.0's Countries and Sources pair waited out the old build's 15 minutes. The keys now carry the plugin version.
- **The share bars are dimmer.** They keep the card's accent, at lower strength, so a share (89% one page only, 88% direct) no longer reads like SN Systems' warnings. Core Web Vitals keep their full good, needs work and poor colors.
- **SN Deploy Status fits when placed fresh.** Its default height is 470 (about 450 measured with the groups and the workers' deploy line); a saved layout keeps its own height.

