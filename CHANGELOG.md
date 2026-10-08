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
- **Being read now, on /stats.** Under the Live figures, the pages people are on right now (the same 5-minute window as Reading now), each with its reader count, top five, in the most-read list's style. One more grouped query per realtime refresh (`inc/analytics-live-pages.php`); a path is listed only when it resolves to a published, unprotected page or note (or Home), so a draft, a private page or a junk path never appears. A query string folds into its page. Not read yet stays a dash; nobody on a page says so. Aggregate counts, cookieless as before; nothing new collected.

## [23.0.0] - 2026-10-08 — live visits on /stats and in admin, still cookieless

### Added
- **Live visits on /stats and in admin, still cookieless.** /stats opens with a Live strip: readers active in the last 5 minutes and human views today (site time), refreshed every 60 s while the tab is open. The analytics dashboard's Now, Right now and Views today figures (native and classic) update in place every 30 s, and the Traffic desktop widget gains a live Reading now row. Everything already on those surfaces stays. One public route, `GET signal-noise/v1/live` (human counts only, read from the realtime transient, edge-cacheable for 30 s behind a 30 s bucket in the query), and one gated twin, `live/admin` (every class, `view_stats`). Either keeps the pair warm through the same throttle as the admin warmer, so at most one refresh is queued however many readers poll. No new collection: the counts are the daily visitor hashes the beacon already sends. A figure that cannot be read stays a dash, never 0.

