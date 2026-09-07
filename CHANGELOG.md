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
- Collapse past cache checks into a neutral disclosure so old failures and escalations do not look like active errors beside the current verdict.
- Apply native badge surfaces and readable text to all four Analytics maturity tiers, lifecycle status pills (including Refresh and Evergreen), and signal labels. Preserve tier distinctions with colored dots instead of low-contrast classic text.

## [13.106.16] - 2026-09-07 — Polish native preferences and refresh cache purge status

### Fixed
- Refresh the Cache widget from the authoritative freshness ability after purge actions and while verification is pending. Keep historical post-save counts separate from the current verdict, show read failures, and preserve purge errors in command feedback.
- Match Signal & Noise Preferences to native Features checkbox cards, add its sidebar glyph, and serialize preference saves so rapid edits or reopening the panel cannot leave stale controls. Use the native warning palette and visible focus styling for Analytics attention links.
