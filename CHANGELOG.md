# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [21.9.1] - 2026-10-05 — desktop cards read cleanly

### Fixed
- **Desktop cards read cleanly after the fold to six.** Deploy Status no longer prints a raw timestamp ("Last successful refresh: 2026-10-05T00:38:44.028Z"): a current reading has no footer, and a failed refresh says "Last good reading 6 min ago · retrying in 5 min". Labels no longer break inside a word ("Searc / h", "Monitor / s"): a value too wide for its row takes its own line, right-aligned, and a label breaks only a word longer than the card. The card buttons share one style, the compact outlined button of Sweep now, on the same line as the card's link: Clear DB overrides with Open Health, Check for updates with Open Dashboard. Every list that grows on a busy day shows two rows and counts the rest ("+2 more pending in Provenance"): notes minting and anchors pending on SN Provenance; checks to look at, checks that could not run and monitors not up on SN Systems; campaigns on SN Traffic. The default heights of those three cards grow 60 px for that busiest state; a saved size is kept.

