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
- **The 5xx summary reads by day, and says who asked.** `signal-noise/edge-errors-summary` (and its remote twin) now carries `days[]`, one zero-filled row per day of the window with `total` and who asked (`visitor`, `worker`, `other`, `unrecorded`), plus the window's `asked_by` totals. `unrecorded` is a row stored before the errors query carried `requestSource` (17.9.3), so across that change it is exactly the pre-filter leftover, read off instead of inferred. `total` is now the full sum of the days rather than the top ten responders' share (identical when there are ten or fewer). Same reader for `cloudflare-status`'s `errors_5xx` and the Edge panels.
- **90-day availability reaches the phone.** The uptime light tier (shared by `remote-uptime-status`) fills `availability_90d` from the 90d map, cache-only. The hourly warmer refreshes that map every 6h (8h TTL), about 4 Better Stack calls per 6h. Saving the Better Stack token now also drops the 90d caches.

### Changed
- Remote contract '6' → '7' (hash RED-then-pinned): the 5xx twin's new fields, and the uptime row's `availability` description no longer says "null on the light tier", which stopped being true in 18.1.0. sn-remote-mcp-worker bumps `CONTRACT_VERSION` in step; until that deploy lands the deploy probe reads `contract_match: false`, as designed.

## [18.1.0] - 2026-09-23 — 30-day availability reaches the phone

### Added
- **30-day availability reaches the phone.** The light tier of `signal-noise/uptime-status`, which the remote twin `remote-uptime-status` shares, now carries `availability` and `incidents_30d` per row, read cache-only from the 30d map. A new hourly cron, `sn_uptime_availability_hourly`, keeps that map warm (2h TTL, so it never goes cold between runs), costing one Better Stack call per monitor or heartbeat per hour on a schedule the owner controls. The light path still makes only its two status calls, the twin's input stays `properties === array()`, and execute sharing is unchanged. `availability_90d` and `response_ms` remain detail-only. The hook is registered in `snt_cron_sn_owned_hooks()`, the opt-in gates (`sn_uptime_status_configured`) and the deactivation list.

