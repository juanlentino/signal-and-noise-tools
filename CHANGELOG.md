# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **Live visits on /stats and in admin, still cookieless.** /stats opens with a Live strip: readers active in the last 5 minutes and human views today (site time), refreshed every 60 s while the tab is open. The analytics dashboard's Now, Right now and Views today figures (native and classic) update in place every 30 s, and the Traffic desktop widget gains a live Reading now row. Everything already on those surfaces stays. One public route, `GET signal-noise/v1/live` (human counts only, read from the realtime transient, edge-cacheable for 30 s behind a 30 s bucket in the query), and one gated twin, `live/admin` (every class, `view_stats`). Either keeps the pair warm through the same throttle as the admin warmer, so at most one refresh is queued however many readers poll. No new collection: the counts are the daily visitor hashes the beacon already sends. A figure that cannot be read stays a dash, never 0.

## [22.9.4] - 2026-10-07 — the 2-row allowance covers an estimate too

### Changed
- **The 2-row allowance covers an estimate too** (owner rule 2026-10-07). The first live run of the 22.9.3 gate showed Oct 6's `vi` 2 vs 0 was not a lost row: the legacy dataset held one sampled row standing for 2, the new one held none. Independent sampling can land on zero on one side for any small event. An event other than pageviews whose two sides differ by at most 2 (rows and visitors) is now allowed whether either side is counted or estimated. Pageviews still get no allowance, and a gap of 3 is still a mismatch.

