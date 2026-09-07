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
- Replace the OpenStation PWA’s white tiles with opaque dark variants that preserve the exact JL artwork, dimensions, and maskable inset. Use new manifest and Apple icon URLs so browsers can detect the updated art. Installed icons use a stable dark appearance independently of page CSS.
- Apply native OpenStation surfaces, borders, and readable foregrounds to asynchronously loaded Uptime tables, incident details, loading/error states, and status labels in Analytics and Home. Preserve status colors in dots and contain wide tables within the panel.

## [13.106.17] - 2026-09-07 — Fix native Analytics badges and quiet cache history

### Fixed
- Collapse past cache checks into a neutral disclosure so old failures and escalations do not look like active errors beside the current verdict.
- Apply native badge surfaces and readable text to all four Analytics maturity tiers, lifecycle status pills (including Refresh and Evergreen), and signal labels. Preserve tier distinctions with colored dots instead of low-contrast classic text.
