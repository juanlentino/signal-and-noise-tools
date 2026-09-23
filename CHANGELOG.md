# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.9.2] - 2026-09-23 — one repair at a time, and a probe that measures

### Fixed
- **The one-shot edge repair queued itself again while it was still running.** WP-Cron removes a single event from the queue before running it, and a month of re-rolls takes longer than a minute, so the `init` check ("not done, nothing queued") was true mid-run and a second repair was queued every minute until the first finished (measured 2026-09-23). The runs were idempotent, so no data was harmed. The repair now holds a 30-minute lock that the scheduler reads, and releases it when done. A run that dies is retried when the lock expires.

### Added
- **`signal-noise/edge-sampling-probe`, two live GraphQL reads for #1002.** The week's sampled 5xx (about 38,600, all `edge=504 origin=-`) and the zone's exact totals (about 6,100, mostly 503) disagree. 17.9.1 read that as a double count, but the repaired totals didn't move, which refuted it at this volume. The probe measures instead: `sampling` gives `count`, `count × sampleInterval` and the interval range, so whether sampling is active here at all is read rather than assumed. `by_request_source` splits the 5xx by `requestSource`, which separates a Worker's subrequest from a visitor's request. Each read reports its own error. It's on the read door (47 → 48). `sn_edge_query()` gains an optional by-reference `$error` so a caller can see why it got `null`; every existing caller is unchanged.

