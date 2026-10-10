# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.7.1] - 2026-10-10 — the tag-merge script no longer ships

### Removed
- **The tag-merge script no longer ships.** `tag-merge-apply.sh`, the one-off WP-CLI pass that retired 83 tags into 23 on 2026-08-15, sat at the plugin root, so every release carried it and the live site served it to anyone (HTTP 200, 7.8 KB of post IDs and tag names, no secrets). It is deleted; git history keeps it. `tag-merge-map.md`, the record the ADR and `inc/tag-retired-map.php` cite, moved to `docs/ops/`, which does not ship.

