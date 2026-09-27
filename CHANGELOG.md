# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **One retired-tag map.** The theme kept its own list (`provenance`, `cryptography`, `music-identification`) beside the plugin's 61, and ran first, so a change in one was silently overridden by the other. All three were already in `inc/tag-retired-map.php` with the same targets; a test now pins them there, and the theme drops its copy in its next release.

## [19.1.1] - 2026-09-27 — provenance tags land on the hub

### Fixed
- **Provenance tags land on the hub.** The `provenance` tag no longer exists, so 19.1.0 sent `/tag/music-provenance/`, `/tag/cryptographic-provenance/` and `/tag/falsifiability/` to `/notes/`. They and `/tag/provenance/` now 301 to `/provenance/`. A retired-map value starting with `/` is a page path. A saved rule in the Redirects screen still runs first.

