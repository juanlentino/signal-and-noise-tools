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
- **Stored scroll per view is counted by pageview ID, so it no longer passes 100%.** Read live on 2026-11-01: 21 of the 500 busiest stored path-days read above 100% scroll per view, holding 41 of 1,226 views. The worst was `/` on 2026-09-07 at 400% on one view, and the same leak showed in the bot class.

  The beacon sends each milestone once per view, so the extra milestones are `sc` events whose pageview row was never stored. The likely cause is the collector's per-visitor limit, not yet proven.

  The daily rollup now runs a third query, `inc/analytics-rollup-pid-scroll.php`. It reads each second-generation view's deepest milestone by pageview ID (`double11`, recorded since 2026-10-05), and counts only views whose pageview row is in the same day, path and class. An `sc` event with no stored pageview counts nowhere. This feeds the stored `scroll_events` and `scroll_sum`; the legacy `scroll_avg` keeps its raw per-event ratio.

  It falls back to the plain event count, as before, in three cases:
  - a window read from the legacy dataset, which has no pageview ID;
  - a failed read;
  - a read cut by the row cap.

  The daily roll rewrites the trailing seven days. Earlier second-generation days take the per-view count when the history recompute next runs. The exception is a recompute batch whose window starts before 2026-10-05: it reads the legacy dataset and keeps the plain count, which on 2026-11-01 covers roughly 2026-10-05 to 2026-10-11. `tools/reroll-analytics-90d.php` runs the same per-view read through its own one-day transform, so it stays the cron's twin.

  Review fixes (independent review of #2340): pins on the per-view read's alignment with the main read (day bucket, class expression, `WHERE` with lower bound, upper bound and over-cap tail, folded or not, zoned or not), the run's zone and fold fallbacks, and the reroll tool's transform.

  Tests: `tests/analytics-rollup.php` (237) and `tests/reroll-analytics-90d.php` (61); the dialect guard now scans the new file. Each of these mutants was seen red:
  - a pageview-less view counted as one;
  - the row-cap refusal skipped;
  - the upsert ignoring the per-view count;
  - the run skipping the merge;
  - each of six alignment drifts: zone, fold and over-cap tail in the builder or the run, and the upper bound;
  - the reroll transform losing its upper bound.

## [2026.11.3] - 2026-11-01 — The events rows keep time and scroll per view only by page and day

### Fixed
- **The rows read's events source no longer reports time and scroll per view where a view's beacons land in another row.** Read live on 2026-11-01, after the 2026.11.2 install:
  - by city, one Los Angeles view read 475% scroll per view, because its reader's beacons geolocated elsewhere than its page view;
  - by hour, hours 18 and 19 read 125%, because a view's beacons fell in the next hour;
  - under a referrer filter, `(direct)` took every beacon, because a beacon carries no referrer. For `/` over 30 days it held all 157 scroll events of the page's 71 views, and google.com's rows read 0.

  The events source now keeps `time_avg_per_view` and `scroll_avg_per_view` only by path, link_code and day with no referrer filter, as the stored rollups always have. On referrer, country, device, region, city and hour rows, and under a referrer filter, both are null. `sort: time` is refused wherever time would be null; before, it fell back to alphabetical order. A row of beacons only, with no page view, is dropped: it carried 0 views and no figure. Views and visitor-days are unchanged, so four dimensions still add up to one (test 7b). The sessions source files each beacon under its own view and keeps both on every row. The fields read and the rows read's description now say all of this.

  Review fix (independent review of #2338): the time sort refusal, the referrer filter, and pins on every allowlist edge.

  Tests: `tests/analytics-rows-wide.php` (72). Each of these mutants was seen red: engagement kept on every row, the zero-view row kept, the referrer clause in the fetch, and the dimension and referrer clauses of the time sort.

