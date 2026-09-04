# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- `sn-posts` accepts `status` and `fields`. Both were previously ignored in silence: a caller passing a filter got the whole corpus and a success, indistinguishable from a filter that matched everything. An unknown field name is now a 422 naming it and listing the valid ones, and the `post_ids` scope reports `filtered` separately from `missing` — a post that exists but does not match the status is not the same as one that does not exist.

## [13.95.2] - 2026-09-04 — the sn-apply family gets a directory

### Changed
- sn-apply-* implementation files live under inc/sn-apply/; inc/abilities-sn-apply.php is the loader. No behaviour change.

