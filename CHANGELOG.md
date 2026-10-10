# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.5.0] - 2026-10-10 — the remote door queries analytics rows and reads machine readers by day

### Added
- **The remote door can say where and from where: `sn_remote_analytics_query` (remote contract 15).** Asked on 2026-10-10 which pages moved after a Hacker News submission, the remote surface could report a 37% lift and name no page and no source. `signal-noise/analytics-rows` (local, as `sn-metrics{analytics_rows}`) and its twin `signal-noise/remote-analytics-rows` return the stored analytics as counted rows by path, referrer, country, device or day, or one of the first four with day: views, pageview_visits, time and scroll per view (milliseconds; path and day only, the dims table stores no engagement). Path by referrer, country or device is not stored and is refused, not estimated. Hard limits: nothing finer than a day and no visitor identifiers; paths are an allowlist of the site's own content, anything else `(unmatched)`, because stored paths are visitor-chosen text and the answer lands in a model's context; referrers are strict hostnames or the worker's sentinels, anything else `(invalid)`; values under 3 visitor-days are `(withheld)` with their counts kept; out-of-set arguments are refused at the origin. The remote contract moves from 14 to 15 (`SN_REMOTE_CONTRACT_VERSION`, the remote contract, not the plugin version); the sn-remote-mcp Worker needs its matching release. Pinned by `tests/analytics-rows.php` (refusals, crafted paths and referrers, the floor, the limit boundary, the summary's table and filters), `tests/remote-contract-shapes.php` (the contract 15 hash), `tests/abilities-remote-set.php`, `tests/mcp-remote-guard.php`, `tests/mcp-remote-verdicts.php`, `tests/abilities-sn-metrics.php` and the door manifest. The hard limits and their reasons are in `docs/ai-abilities-catalog.md`.
- **Machine readers by day: `series: "day"` on `sn_remote_machine_readers`.** The summary could say how many crawler reads a window held, not when. An optional `series: "day"` adds `daily` (one entry per UTC date, oldest first, zero-filled: day, total, first_party, ai_training, purposes, ai_surfaces) and `daily_total_exact`; the window is rolling, so its first and last dates carry `partial: true`, and every daily field sums to its window figure. Without `series` the response is byte-identical to before (pinned against a fixture captured before the change); any other value is refused. No `ai_training_status`: the sensor records a read before the response exists, so no status is stored, and capturing it is a separate worker change. Same remote contract 15 as `sn_remote_analytics_query` (both unreleased, one bump); the sn-remote-mcp Worker passes `series` in its matching release. `inc/machine-readers-daily.php`, `tests/machine-readers-daily.php`, `docs/ai-abilities-catalog.md`.

### Tests
- **No two plugin files may declare the same global function** (`tests/no-duplicate-functions.php`). Each suite loads only the files it tests, so the daily series' first name, `snt_mr_daily_series`, passed every suite while `inc/mr-series.php` already declared it; the live site would have fataled with "Cannot redeclare". CI's PHPStan caught it by arity; the guard now catches it by name.

