# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.1] - 2026-09-10 — the card title guidance says what was measured

### Fixed
- The OG card title field's guidance was wrong. It advised 60–90 characters; measured against the actual font at the actual sizes, one line holds about 24 characters and an 83-character title truncated even at the smallest step. The card wraps to three lines across 1040px and steps 88→74→62px before ellipsizing, so the limit is rendered width and long words break early — character count is a poor proxy twice over. The helper now says what was measured.

### Changed
- The `/notes` position-drift watch now records that its window is confounded. The page changed materially between the watch being set and its 2026-09-11 due date — the pillar rail arrived in the hero, the corpus stamp left it, the heading outline changed, and the first note moved twice — so a worse position on that date cannot be attributed to drift rather than to the changes. The watch says so rather than leaving the next reader to infer it.

