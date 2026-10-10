# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.4.0] - 2026-10-10 — break alerts count visitors and explain the day; the edge rollup runs at 01:15 UTC

### Changed
- **The daily edge rollup runs at 01:15 UTC, and each day's failing paths can be read locally.** The rollup was a `daily` event anchored to when it was first seen unscheduled (20:43 UTC here), so a break mailed about 21 hours after the UTC day closed; it now runs at 01:15 UTC and mails within about two hours. An event at another time is moved once, with one immediate run when the move would otherwise leave yesterday unread (`tests/edge-rollup-schedule.php`). `cloudflare-status` `errors_5xx.paths_by_day` carries each day's top five failing paths with status, who answered and who asked, `stored: false, paths: null` for a day recorded before who-asked was stored (`tests/edge-5xx-read-back.php`). Local only: the remote edge-errors twin keeps its weekly shape and contract 14; the per-day field reaches it with the MCP worker's next major release.
- **A break alert counts visitor errors and says what the day's errors were.** On 2026-10-09 the mail said "/" answered a server error 4 times on 2026-10-08, and nothing stored could say whether a person or a Worker had asked: `err_path_status` had the status but no asker, `err_source` the asker but no path. The edge rollup now also stores `err_path_asker` per day (`"<asker> <edge> <origin> <cache> <path>"`). The break line counts visitor-asked errors only (still 3 in a UTC day); Worker and other errors on the path ride along as context. The mail carries the day's breakdown, for example "All 4 on / that day: 3 x 522 (Cloudflare, origin never answered, visitor), 1 x 503 (origin, via Worker)". A day stored before the new rows keeps counting every error and says so, with the status breakdown it has. Counts and status words only. Pinned by `tests/alerts.php` (visitor-only counting, Worker errors not making up the count, the breakdown line, a day with no stored asker), `tests/edge-5xx-read-back.php` and `tests/edge-analytics-sees-5xx.php` (the stored value, its cut at 160 characters). The Oct 8 finding and a proposal to run the rollup sooner after the UTC day closes are in `docs/ops/edge-5xx-break-alert.md`.

