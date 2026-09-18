# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.1.3] - 2026-09-18 — the bucket takes bytes, not types

### Fixed
- **Every bucket upload goes as `application/octet-stream`.** The first production pass created its drafts and then failed every upload with Zenodo's "Invalid 'Content-Type' header. Expected one of: application/octet-stream": the bucket takes that type and no other, and the file's own type rides its extension. The test had pinned `text/markdown` as correct. Pinned to octet-stream.
- **A failed resume read keeps the draft.** When the read of a stored draft came back with anything but success the code forgot the draft id and the next pass minted a fresh one; ten empty drafts on the first production day. Now only a 404 forgets it, and the step is named `resume`, not `create`. Pinned at 500 and 404.

