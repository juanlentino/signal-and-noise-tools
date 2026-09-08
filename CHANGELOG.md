# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.107.2] - 2026-09-08 — Restore native Analytics mobile height chain

### Fixed
- Restore blank native Analytics reports by carrying the window body height through the shell’s tab stack and panel wrappers. Keep hidden panels hidden and limit the height rule to Analytics mounts; retain container-aware scrolling.

### Added
- Mobile shell geometry regression with actual mobile CSS and native tab wrappers, browser/standalone markers and mobile–desktop–mobile transitions. Strengthen desktop coverage with native Analytics wrappers, hidden-panel assertions, and Analytics CSS loaded alongside Home/other leaves.

