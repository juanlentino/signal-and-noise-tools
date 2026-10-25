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
- **Overlapping cron runners no longer read as a failed job.** Since 2026.10.64 the plugin records core's cron errors, and on 2026-10-25 the Systems card showed `sn_prov_orphan_sweep` failed: three cron runners overlapped while 2026.10.65 was installing, the second rescheduled an event the first had already saved, `update_option()` saw no change and core reported `could_not_set`. The runs before and after succeeded and the event stayed on its five-minute schedule. A `could_not_set` is now recorded only when the cron list is not as the step meant to leave it: after a reschedule, no future copy of the event; after an unschedule, the due copy still there. The check reads the stored `cron` row, not the process cache, so a runner that cached the options before the other one saved still reads the saved list. Any other error code is recorded as before.

## [2026.10.65] - 2026-10-25 — sn-status takes the single tools' inputs

### Changed
- **sn-status takes the single tools' inputs.** The 2026-10-24 wave-4 read retired nothing: none of the eight absorbed singles had collapsed, and the reason was input, not habit. sn-status could not force a fresh deploy or uptime read, ask for uptime's full payload, filter the scheduled cron list, or size cron history, so a caller needing any of that fell back to `get-deploy-status`, `uptime-status`, `list-cron-events` or `get-cron-history`. It now takes `force_refresh` (deploy, uptime), `detail` (uptime), `sn_only` and `hook` (cron_scheduled) and `limit` (cron_history), each forwarded only to the source that declares it and only when set, so a bare request dispatches exactly as before. One behavior change: a request with `hook` that names cron_scheduled now gets that hook's events only. `get-analytics-events` was already off the door (19.3.0); its 2 calls in the window predate that. The `wave4_telemetry` watch moves to 2026-11-25; the read is in `docs/mcp-consolidation/retirement-verdicts-2026-10-24.md`.

### Tests
- **sn-status's inputs are checked against the real source schemas.** The forwarding table is now `snt_sn_status_takes()`, and `tests/sn-status-inputs-drift.php` loads the real `get-deploy-status`, `uptime-status`, `list-cron-events` and `get-cron-history` registrations: a source that renames or drops an input, or a type or bound that drifts on either side, fails the sweep instead of silently degrading that section to `{error:"unavailable"}` for the callers who set it.

