# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.6.2] - 2026-09-14 — the Anchors widget shows a recording version

### Fixed
- **The Anchors widget could not show a freshly minted version.** A commit is `unanchored` from the moment it is persisted until the settle-window dispatch reaches the Worker and the Worker answers `pending`; the theme presents that as *Recording*. `snt_prov_anchor_overview()` counted only `confirmed` and `pending`, so a note that had just minted v2 read "40 of 41 notes anchored, no anchors pending" on the desktop while its page said RECORDING · V2. The overview now carries a third list, `recording` (`{ post_id, title, version }`), and the widget paints it: "1 recording · 40 of 41 anchored" with the note and version. `anchor-status` (read door, local only) gains the field. Guard: a minted-not-dispatched v2 is a recording row; confirmed + pending + recording = total, so no note falls between the lists; mutation (recording dropped) red.

