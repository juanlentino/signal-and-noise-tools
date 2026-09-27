# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.1.1] - 2026-09-27 — provenance tags land on the hub

### Fixed
- **Provenance tags land on the hub.** The `provenance` tag no longer exists, so 19.1.0 sent `/tag/music-provenance/`, `/tag/cryptographic-provenance/` and `/tag/falsifiability/` to `/notes/`. They and `/tag/provenance/` now 301 to `/provenance/`. A retired-map value starting with `/` is a page path. A saved rule in the Redirects screen still runs first.

