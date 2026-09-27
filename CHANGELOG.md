# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.0.2] - 2026-09-27 — the north star's weeks have their bars back

### Changed
- **The north star's weeks have their bars back.** Under each week's date and number sits a slim bar scaled to the busiest week (this week in the accent colour, a zero week a baseline tick), plain CSS with a short rise that respects reduced motion. The hero's left side no longer reads empty beside the three signals.

