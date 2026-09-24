# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.3.0] - 2026-09-24 — every 5xx day says whether it was read

### Fixed
- **An update no longer blanks the phone's uptime figures for up to an hour.** After updating to 18.2.0 on 2026-09-24, every `availability` read null on the phone until the next hourly warmer run. Once per `SNT_VERSION` on `admin_init` (the shape of `inc/uptime-heartbeat-removal.php`), the plugin now queues one immediate run of `sn_uptime_availability_hourly` and drops the 90d refresh flag, so the 30- and 90-day windows refill within a minute of the first admin page load. Queued rather than inlined: the warm is ~8 Better Stack calls and does not belong on a page load.
- **A 0 in the 5xx days is never ambiguous again.** Each `days[]` row of `edge-errors-summary` (and its remote twin) now carries `read`: `read` (the errors query answered for that day), `failed` (it was refused, so a 0 means not read), `pending` (no rollup has covered it yet: today, or yesterday before the daily run) or `untracked` (stored before this bookkeeping). On 2026-09-24 the phone read 09-23 as 0 with `query: null`, which turned out to mean the daily rollup had not run since 17.9.3, not a clean day; `query` only ever held the LAST run, so it could not say which days in the window were read. The rollup now records every run's outcome per day (`sn_edge_errors_read_days`, newest 30). Remote contract '7' → '8' (hash RED-then-pinned); sn-remote-mcp-worker bumps in step.

