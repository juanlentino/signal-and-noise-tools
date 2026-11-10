# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.23] - 2026-11-10 — Pull-quote suggestions build core's Pull Quote

### Fixed
- **Pull-quote suggestions follow the theme's core pull quote.** Since theme 2026.11.7 the pull-quote pattern is `core/pullquote` with the Pull-quote block style (`is-style-pull-quote`). The pattern-adoption suggestion now builds that markup, copied from the theme's `patterns/pull-quote.php`, instead of the old `sn-pattern-pull-quote` group, and the conventions registry fixture is refreshed from the theme's registry. Pull quotes already written as the old group or the `signal-noise/pull-quote` block are still not candidates, so published notes are neither flagged nor rewritten. Tests: `tests/pattern-adoption-suggest.php`, `tests/pattern-adoption-detect.php`.

