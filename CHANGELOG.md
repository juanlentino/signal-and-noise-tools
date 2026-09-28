# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.6.3] - 2026-09-28 — the check reads the refresh

### Fixed
- The Analytics server token check in Connections › Credentials now reads the worker's refresh result. The worker-version reader dropped the refresh block from the worker's report, so the check said "Unknown" on every real reading while the refresh itself was fine. A test now parses a live copy of the worker's report.

