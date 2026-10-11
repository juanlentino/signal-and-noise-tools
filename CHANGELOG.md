# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.9.1] - 2026-10-11 — a due watch stays on SN Systems

### Fixed
- **A due watch stays on SN Systems.** 23.9.0 judged the General-save guard watch only on a wp-admin request, so the card showed "Watch due" on the page load and lost it two minutes later, when its poll re-read the same lines through REST and got "cannot tell". The wp-admin reading is now remembered (option `snt_watch_general_save_seen`, written only when the answer changes) and every request that cannot tell answers with it and its date, so the card, its poll and the MCP `watches` read agree. Pinned by `tests/watches.php`.

