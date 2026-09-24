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
- **An update no longer blanks the phone's uptime figures for up to an hour.** After updating to 18.2.0 on 2026-09-24, every `availability` read null on the phone until the next hourly warmer run. Once per `SNT_VERSION` on `admin_init` (the shape of `inc/uptime-heartbeat-removal.php`), the plugin now queues one immediate run of `sn_uptime_availability_hourly` and drops the 90d refresh flag, so the 30- and 90-day windows refill within a minute of the first admin page load. Queued rather than inlined: the warm is ~8 Better Stack calls and does not belong on a page load.

## [18.2.0] - 2026-09-24 — 5xx by day, and who asked

### Added
- **The 5xx summary reads by day, and says who asked.** `signal-noise/edge-errors-summary` (and its remote twin) now carries `days[]`, one zero-filled row per day of the window with `total` and who asked (`visitor`, `worker`, `other`, `unrecorded`), plus the window's `asked_by` totals. `unrecorded` is a row stored before the errors query carried `requestSource` (17.9.3), so across that change it is exactly the pre-filter leftover, read off instead of inferred. `total` is now the full sum of the days rather than the top ten responders' share (identical when there are ten or fewer). Same reader for `cloudflare-status`'s `errors_5xx` and the Edge panels.
- **90-day availability reaches the phone.** The uptime light tier (shared by `remote-uptime-status`) fills `availability_90d` from the 90d map, cache-only. The hourly warmer refreshes that map every 6h (8h TTL), about 4 Better Stack calls per 6h. Saving the Better Stack token now also drops the 90d caches.

### Changed
- Remote contract '6' → '7' (hash RED-then-pinned): the 5xx twin's new fields, and the uptime row's `availability` description no longer says "null on the light tier", which stopped being true in 18.1.0. sn-remote-mcp-worker bumps `CONTRACT_VERSION` in step; until that deploy lands the deploy probe reads `contract_match: false`, as designed.

