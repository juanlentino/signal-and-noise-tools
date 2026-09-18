# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.3.4] - 2026-09-18 — the position is the reading

### Fixed
- **Check 30 routes to the human instead of hiding behind a floor.** On 16.3.3's first pass Jev's positions were informative (search titles spread from 0.58 to 1.88 of 2, and the bottom four matched a human read) and its confidences were not (one of 69 cleared 0.9), so the 0.9 "act automatically" floor from 16.3.0 hid every reading. A finding is now a position below level one ("names the subject"), whatever the confidence; the note carries the confidence and, under 0.5, says "Jev is unsure; read it yourself", which is the docs' own prescription for that range. Descriptions are held to the same rule. Pinned.

