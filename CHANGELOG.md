# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.107.5] - 2026-09-08 — Quiet status widget refresh

### Fixed
- Keep Deploy Status and Uptime background polls silent: retain the rendered content and colors while pending, preserve Last deploy and last-successful-refresh recency, and advance refresh timestamps only on success. Failed refreshes retain last-known data with a compact keyboard-accessible warning and tooltip details instead of a warning paragraph; retries, backoff and teardown cancellation remain unchanged.

