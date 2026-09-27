# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.0.3] - 2026-09-27 — the north star's trend is twelve weeks, one chart

### Changed
- **The north star's trend is twelve weeks, one chart.** Twelve slim bars, oldest first, each with its count, touching at 4px so they read as one chart (four spaced columns read as scattered marks); the first date and "this week" under it, this week in the accent. One analytics read covers all twelve weeks (cached an hour); the four-week figures (the change, the 3-week average, readers per note) are computed as before from its newest four. The reading gains `trend` beside `series`.

