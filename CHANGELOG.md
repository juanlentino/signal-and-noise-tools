# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.1.0] - 2026-09-23 — 30-day availability reaches the phone

### Added
- **30-day availability reaches the phone.** The light tier of `signal-noise/uptime-status`, which the remote twin `remote-uptime-status` shares, now carries `availability` and `incidents_30d` per row, read cache-only from the 30d map. A new hourly cron, `sn_uptime_availability_hourly`, keeps that map warm (2h TTL, so it never goes cold between runs), costing one Better Stack call per monitor or heartbeat per hour on a schedule the owner controls. The light path still makes only its two status calls, the twin's input stays `properties === array()`, and execute sharing is unchanged. `availability_90d` and `response_ms` remain detail-only. The hook is registered in `snt_cron_sn_owned_hooks()`, the opt-in gates (`sn_uptime_status_configured`) and the deactivation list.

