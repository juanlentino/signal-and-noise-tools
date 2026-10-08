# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.3.2] - 2026-10-08 — the admin bar no longer shows S&N as an update after it is installed

### Fixed
- **The admin bar no longer shows S&N as an update after it is installed.** The install request still runs the old code, and anything in it that rebuilt `update_plugins` wrote "update available" back for the version just installed; when that write landed after the version watchdog had already run (the desk fires many requests at once after an install), the badge stayed until the Plugins screen made WordPress re-check. A read-time filter on `site_transient_update_plugins` now moves an S&N entry whose new version is not newer than the installed one to `no_update`, so every reader is right whoever wrote the record.

