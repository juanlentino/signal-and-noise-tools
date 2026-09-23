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
- **Cloudflare's own Early Hints lookups no longer count as errors.** `edge-sampling-probe` showed that 7,593 of the 7,743 sampled 5xx in a day had `requestSource: earlyHintsCache`. That's Cloudflare checking whether it holds a cached `103 Early Hints` for a URL, and a miss is logged as a 504 that never reaches the origin and that no visitor sees. It's why every stored 5xx read `edge=504 origin=-`, why the top paths were just the most-requested URLs, and why the exact daily totals never counted them. The 5xx query now excludes them. It also keeps the request source, so a row says who asked ("asked by a visitor", "asked by a Worker"), and records its own outcome as `errors_5xx.query`, so a refused query reads as "not read" rather than "no errors". Rows stored earlier keep their old wording.

### Added
- **`edge-sampling-probe` read C: what a sampled `count` means.** Yesterday's visitor requests from the sampled groups, taken as `count` and as `count × sampleInterval`, are compared against the exact daily total. The verdict (`count_is_the_estimate`, `multiply_by_interval`, or `unsettled` when neither or both land within 10%) decides whether 17.9.1's change stays.

## [17.9.2] - 2026-09-23 — one repair at a time, and a probe that measures

### Fixed
- **The one-shot edge repair queued itself again while it was still running.** WP-Cron removes a single event from the queue before running it, and a month of re-rolls takes longer than a minute, so the `init` check ("not done, nothing queued") was true mid-run and a second repair was queued every minute until the first finished (measured 2026-09-23). The runs were idempotent, so no data was harmed. The repair now holds a 30-minute lock that the scheduler reads, and releases it when done. A run that dies is retried when the lock expires.

### Added
- **`signal-noise/edge-sampling-probe`, two live GraphQL reads for #1002.** The week's sampled 5xx (about 38,600, all `edge=504 origin=-`) and the zone's exact totals (about 6,100, mostly 503) disagree. 17.9.1 read that as a double count, but the repaired totals didn't move, which refuted it at this volume. The probe measures instead: `sampling` gives `count`, `count × sampleInterval` and the interval range, so whether sampling is active here at all is read rather than assumed. `by_request_source` splits the 5xx by `requestSource`, which separates a Worker's subrequest from a visitor's request. Each read reports its own error. It's on the read door (47 → 48). `sn_edge_query()` gains an optional by-reference `$error` so a caller can see why it got `null`; every existing caller is unchanged.

