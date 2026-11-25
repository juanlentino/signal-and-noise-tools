# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.64] - 2026-11-25 — Settings › Analytics and Accounts and connections in headed rows by subject

### Changed
- **Settings › AI: the Remote door box now shows its switch first and the state sentence after it**, the same order as the Read door and Write door boxes, on both the classic screen and the S&N window. Test: `tests/os-leaf-settings-ai-doors.php`.
- **Settings › Analytics and Settings › Accounts and connections are grouped into headed rows.** Both panes let their boxes flow into as many columns as fit, so Analytics stretched to six thin columns and Accounts put one tall Zenodo box beside three short ones. Analytics is now three rows of three, by subject: Data collection (Collector endpoint, Roles kept out, Feed tracking), What counts as a read (North star, Session funnels, Publish windows), Signals and scans (Engine tuning, Machine readers, Insights). The split follows Google Analytics' Admin, which files settings under data collection and data display. Accounts keeps Credentials, then Search engines (IndexNow, Search Console) and Records and profiles (Zenodo, Downloads on /provenance, Muso.AI). Search engines groups submission and indexing as Google Search Console and Bing Webmaster Tools do. The downloads line, which shared the Zenodo box, is now a box of its own with the same fields and Save, and the IndexNow box is titled "IndexNow" under its row. No field, action or handler changed. Both twins. A row with none of its modules loaded paints no heading. Tests: `tests/os-leaf-settings-analytics.php`, `tests/os-leaf-monitoring-analytics.php`, `tests/os-leaf-settings-accounts-boxes.php`, `tests/os-leaf-settings-accounts-rows-absent.php` (new).

