# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.21] - 2026-10-16 — Last seen on Your devices moves on the owner's day

### Fixed
- **Your devices: Last seen moves on the owner's day.** It moved only when the marker was re-signed, once per UTC day, so a browser enrolled on an evening (already tomorrow in UTC) still read yesterday the next morning (live, 2026-10-16). Last seen now updates on the first wp-admin visit of each site-local day, at most one write a day; the marker's signing day is unchanged, so the Workers verify it as before.

