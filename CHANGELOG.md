# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.66] - 2026-10-25 — overlapping cron runners are not a failed job

### Fixed
- **Overlapping cron runners no longer read as a failed job.** Since 2026.10.64 the plugin records core's cron errors, and on 2026-10-25 the Systems card showed `sn_prov_orphan_sweep` failed: three cron runners overlapped while 2026.10.65 was installing, the second rescheduled an event the first had already saved, `update_option()` saw no change and core reported `could_not_set`. The runs before and after succeeded and the event stayed on its five-minute schedule. A `could_not_set` is now recorded only when the cron list is not as the step meant to leave it: after a reschedule, no future copy of the event; after an unschedule, the due copy still there. The check reads the stored `cron` row, not the process cache, so a runner that cached the options before the other one saved still reads the saved list. Any other error code is recorded as before.

