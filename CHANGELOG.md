# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.3] - 2026-11-01 — The events rows keep time and scroll per view only by page and day

### Fixed
- **The rows read's events source no longer reports time and scroll per view where a view's beacons land in another row.** Read live on 2026-11-01, after the 2026.11.2 install:
  - by city, one Los Angeles view read 475% scroll per view, because its reader's beacons geolocated elsewhere than its page view;
  - by hour, hours 18 and 19 read 125%, because a view's beacons fell in the next hour;
  - under a referrer filter, `(direct)` took every beacon, because a beacon carries no referrer. For `/` over 30 days it held all 157 scroll events of the page's 71 views, and google.com's rows read 0.

  The events source now keeps `time_avg_per_view` and `scroll_avg_per_view` only by path, link_code and day with no referrer filter, as the stored rollups always have. On referrer, country, device, region, city and hour rows, and under a referrer filter, both are null. `sort: time` is refused wherever time would be null; before, it fell back to alphabetical order. A row of beacons only, with no page view, is dropped: it carried 0 views and no figure. Views and visitor-days are unchanged, so four dimensions still add up to one (test 7b). The sessions source files each beacon under its own view and keeps both on every row. The fields read and the rows read's description now say all of this.

  Review fix (independent review of #2338): the time sort refusal, the referrer filter, and pins on every allowlist edge.

  Tests: `tests/analytics-rows-wide.php` (72). Each of these mutants was seen red: engagement kept on every row, the zero-view row kept, the referrer clause in the fetch, and the dimension and referrer clauses of the time sort.

