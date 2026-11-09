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
- **The login doors, probed paths and edge locations count visitor requests only.** These three readings counted every request Cloudflare logged for the site. When a Worker forwards a visitor's request, Cloudflare logs it twice (the visitor's, then the Worker's own request to the server), and it logs its own Early Hints cache lookups as requests too. Read live for 2026-11-08:
  - The doors read 28 for 17 real requests. The card's five 504s were all Early Hints lookups, each next to the visitor's real 404 or 403 in the same minute.
  - Edge locations read 39,697 requests and 916 MB for 18,511 visitor requests and 331 MB.
  - The colo, doors and probes queries now filter on `requestSource:"eyeball"` (the filter the sampling probe already uses), and each new document was run live, with the current one as the control. They take effect at the next nightly rollup. Days already stored keep their old rows until they leave the range. The Bandwidth and Edge requests headline comes from the exact daily totals, which Cloudflare cannot split by source, so it does not move.
  - Tests: `tests/edge-analytics.php`.
- **The 5xx total counts what visitors asked for.** A Worker's 5xx is usually its own request for a visitor's, so the same failure was counted twice. On 2026-11-07, 7 of the 14 Worker errors had a visitor error in the same minute, path and city.
  - The total and each day's total are now visitors plus unrecorded rows. Unrecorded means stored before the source was recorded, and it stays in, since unclassified is not the same as not a visitor.
  - Worker and other rows remain under who asked, who answered and the times.
  - Which URLs failed and the path-by-status list now count visitors only too.
  - The total applies at once to the stored days, since every day in the window carries its source. The path lists take effect from the next rollup. Until a stored day leaves the window, its paths keep its Worker rows, and the dashboard count is held to the total.
  - The install check no longer needs a Worker's row to be maintenance.
  - Remote contract 31 carries the wording. The Worker's own bump ships after this release is installed.
  - Tests: `tests/edge-analytics-sees-5xx.php`, `tests/edge-5xx-read-back.php`, `tests/desktop-status-extra.php`, `tests/remote-contract-shapes.php`.

## [2026.11.17] - 2026-11-08 — The exact daily edge totals are stored again

### Fixed
- **The exact daily edge totals are stored again.** The daily rollup asked `httpRequests1dGroups` for 395 days at once, and this zone refuses a window wider than 52 weeks, 1 day and 1 hour (the dataset's `maxDuration`, read live 2026-11-08). The whole request failed every day, unlogged, so Traffic & edge read Edge requests 0, Bandwidth 0 B, every status code 0 and no countries. `SN_EDGE_BACKFILL_DAYS` is 364, and the first run after install back-fills the year. Test: `tests/edge-rollup.php` (the daily window fits the limit; it failed at 395).

