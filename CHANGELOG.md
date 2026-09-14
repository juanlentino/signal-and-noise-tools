# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.7.2] - 2026-09-14 — the Provenance window's empty Commits table

### Fixed
- **The Provenance window's empty Commits table read "Loading anchor status…" forever.** A window has no poller (the classic page hydrates its table from `/status`; the window paints rows server-side), so its empty state is a state, nothing pending, never a wait. The port (#1083, 09-06) borrowed the classic pre-hydration sentence as the empty copy, and it never showed because something was always pending; the first time the queue drained (2026-09-14: 0 pending, 79 confirmed) it read as a stall. The empty copy now says "No pending proofs. Every commit is anchored; press Refresh after minting to see new ones here." Pins in `tests/os-leaf-tools-provenance.php` flipped: the empty table says nothing is pending and never carries "Loading".

