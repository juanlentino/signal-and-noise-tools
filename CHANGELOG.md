# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **A 0 in the 5xx days is never ambiguous again.** Each `days[]` row of `edge-errors-summary` (and its remote twin) now carries `read`: `read` (the errors query answered for that day), `failed` (it was refused, so a 0 means not read), `pending` (no rollup has covered it yet: today, or yesterday before the daily run) or `untracked` (stored before this bookkeeping). On 2026-09-24 the phone read 09-23 as 0 with `query: null`, which turned out to mean the daily rollup had not run since 17.9.3, not a clean day; `query` only ever held the LAST run, so it could not say which days in the window were read. The rollup now records every run's outcome per day (`sn_edge_errors_read_days`, newest 30). Remote contract '7' → '8' (hash RED-then-pinned); sn-remote-mcp-worker bumps in step.

## [18.2.0] - 2026-09-24 — 5xx by day, and who asked

### Added
- **The 5xx summary reads by day, and says who asked.** `signal-noise/edge-errors-summary` (and its remote twin) now carries `days[]`, one zero-filled row per day of the window with `total` and who asked (`visitor`, `worker`, `other`, `unrecorded`), plus the window's `asked_by` totals. `unrecorded` is a row stored before the errors query carried `requestSource` (17.9.3), so across that change it is exactly the pre-filter leftover, read off instead of inferred. `total` is now the full sum of the days rather than the top ten responders' share (identical when there are ten or fewer). Same reader for `cloudflare-status`'s `errors_5xx` and the Edge panels.
- **90-day availability reaches the phone.** The uptime light tier (shared by `remote-uptime-status`) fills `availability_90d` from the 90d map, cache-only. The hourly warmer refreshes that map every 6h (8h TTL), about 4 Better Stack calls per 6h. Saving the Better Stack token now also drops the 90d caches.

### Changed
- Remote contract '6' → '7' (hash RED-then-pinned): the 5xx twin's new fields, and the uptime row's `availability` description no longer says "null on the light tier", which stopped being true in 18.1.0. sn-remote-mcp-worker bumps `CONTRACT_VERSION` in step; until that deploy lands the deploy probe reads `contract_match: false`, as designed.

