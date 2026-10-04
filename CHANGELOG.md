# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [21.6.1] - 2026-10-04 — Deploy Status reads a new version within minutes

### Fixed
- **SN Deploy Status no longer shows the previous version for up to an hour after a release.** The newest release from GitHub is cached for an hour, so right after a cut or a worker deploy the widget compared against a "latest" from before that release existed. When the running version is newer than the cached newest tag, which can only mean the cache predates the release, the tag is read again at once (at most once per five minutes per worker, and the same for the plugin). The five-minute background refresh now probes every worker whatever its cache says, so a deploy shows within about five minutes; the ten-minute cache stays as slack for a late run.

