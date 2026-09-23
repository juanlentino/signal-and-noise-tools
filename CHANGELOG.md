# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.0.0] - 2026-09-23 — the 5xx rollup reaches the phone

### Changed
- #1691's `@since` and code markers read 18.0.0, the number the cut gives it (Y rolls X at 10), not 17.10.0.

### Added
- **The 5xx rollup reaches the phone.** New `signal-noise/edge-errors-summary` (read door 48 → 49, sn-status section `edge_errors`) carries the last seven days of 5xx on its own: total, failing paths, who answered, and the query's last outcome. It reuses the same reader as `cloudflare-status`'s `errors_5xx`, so the two can't disagree. It's split off so it can get a remote twin, `remote-edge-errors-summary`, while `cloudflare-status` stays local because it describes the perimeter. Remote contract '5' → '6' (15 twins). The worker's `sn_remote_edge_errors` row ships with sn-remote-mcp-worker's matching contract bump, and until that deploy lands the deploy probe reads `contract_match: false`, as designed.

