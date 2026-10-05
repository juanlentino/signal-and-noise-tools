# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.1.2] - 2026-10-05 — /stats bars drawn at their exact proportion

### Fixed
- **/stats bars are drawn at their exact proportion.** Every bar took the rounded whole percent, so 356 human views beside 89,578 machine reads drew an empty bar (0.4% rounded to 0). Bars now use the exact share (six decimals, so no positive share draws as 0) while the number beside them keeps its rounding, and a 1px gap inside the outline keeps a hairline fill visible instead of merging into the border.

