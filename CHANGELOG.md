# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The daily edge rollup runs at 01:15 UTC, and each day's failing paths can be read locally.** The rollup was a `daily` event anchored to when it was first seen unscheduled (20:43 UTC here), so a break mailed about 21 hours after the UTC day closed; it now runs at 01:15 UTC and mails within about two hours. An event at another time is moved once, with one immediate run when the move would otherwise leave yesterday unread (`tests/edge-rollup-schedule.php`). `cloudflare-status` `errors_5xx.paths_by_day` carries each day's top five failing paths with status, who answered and who asked, `stored: false, paths: null` for a day recorded before who-asked was stored (`tests/edge-5xx-read-back.php`). Local only: the remote edge-errors twin keeps its weekly shape and contract 14; the per-day field reaches it with the MCP worker's next major release.

### Changed
- **A break alert counts visitor errors and says what the day's errors were.** On 2026-10-09 the mail said "/" answered a server error 4 times on 2026-10-08, and nothing stored could say whether a person or a Worker had asked: `err_path_status` had the status but no asker, `err_source` the asker but no path. The edge rollup now also stores `err_path_asker` per day (`"<asker> <edge> <origin> <cache> <path>"`). The break line counts visitor-asked errors only (still 3 in a UTC day); Worker and other errors on the path ride along as context. The mail carries the day's breakdown, for example "All 4 on / that day: 3 x 522 (Cloudflare, origin never answered, visitor), 1 x 503 (origin, via Worker)". A day stored before the new rows keeps counting every error and says so, with the status breakdown it has. Counts and status words only. Pinned by `tests/alerts.php` (visitor-only counting, Worker errors not making up the count, the breakdown line, a day with no stored asker), `tests/edge-5xx-read-back.php` and `tests/edge-analytics-sees-5xx.php` (the stored value, its cut at 160 characters). The Oct 8 finding and a proposal to run the rollup sooner after the UTC day closes are in `docs/ops/edge-5xx-break-alert.md`.

## [23.3.3] - 2026-10-09 — share bars on an even accent ramp, another site's monitor is not this site's uptime

### Fixed
- **The share bars use an even ramp of the accent, so their pieces read apart.** Faded by opacity, the steps were under 0.1 apart in OKLab and the last two sat under 1.5:1 on the card: one muddy red. Each step now keeps the accent's hue (oklch relative color) at an even lightness, about 0.1 apart, with chroma easing off as it lightens: deep red, rose, pink, blush, each at roughly 3:1 or more on the card. The biggest share stays the deepest; segments keep a 2px card-colored gap. A browser without relative color draws the plain accent.
- **Another site on the Better Stack account no longer counts as this site's uptime.** The account also watches Panacea Studio; its monitor counted in SN Systems' "N of N up", Slowest and verdict, the morning brief and the uptime abilities. A monitor (and its incidents) is kept only when its URL is this site's host or a subdomain of it, so a site added to the account later is left out with no list to keep. Better Stack itself still watches it.

