# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.65] - 2026-11-25 — Settings › AI: the Remote door box shows its switch first, as the Read and Write doors

### Changed
- **Settings › AI: the Remote door box now shows its switch first and the state sentence after it**, the same order as the Read door and Write door boxes, on both the classic screen and the S&N window. Test: `tests/os-leaf-settings-ai-doors.php`.

