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
- **The one-shot edge repair queued itself again while it was still running.** WP-Cron removes a single event from the queue before running it, and a month of re-rolls takes longer than a minute, so the `init` check ("not done, nothing queued") was true mid-run and a second repair was queued every minute until the first finished (measured 2026-09-23). The runs were idempotent, so no data was harmed. The repair now holds a 30-minute lock that the scheduler reads, and releases it when done. A run that dies is retried when the lock expires.

### Added
- **`signal-noise/edge-sampling-probe`, two live GraphQL reads for #1002.** The week's sampled 5xx (about 38,600, all `edge=504 origin=-`) and the zone's exact totals (about 6,100, mostly 503) disagree. 17.9.1 read that as a double count, but the repaired totals didn't move, which refuted it at this volume. The probe measures instead: `sampling` gives `count`, `count × sampleInterval` and the interval range, so whether sampling is active here at all is read rather than assumed. `by_request_source` splits the 5xx by `requestSource`, which separates a Worker's subrequest from a visitor's request. Each read reports its own error. It's on the read door (47 → 48). `sn_edge_query()` gains an optional by-reference `$error` so a caller can see why it got `null`; every existing caller is unchanged.

## [17.9.1] - 2026-09-23 — sampled edge figures counted once

### Fixed
- **Sampled edge figures were counted twice over.** Cloudflare returns a grouped adaptive `count` (and every `sum`) already scaled up to its estimate ("Cloudflare will estimate 50,000 total events (5,000 × 10) and report this value", analytics/graphql-api/sampling). `sn_edge_corrected()` multiplied it by `sampleInterval` again, so every figure built from sampled groups was inflated: threats, edge locations and their bytes, every attack-surface panel, and the new 5xx reading. The inflation was measured, not guessed: the week's 5xx rows read about seven times the zone's exact total. Grouped counts and sums are now taken as reported. Raw, ungrouped events still weigh by their `sampleInterval`, because a raw row does stand for that many events.
- **The inflated history is repaired where it can be.** A one-shot cron job re-rolls every day Cloudflare's sampled dataset still retains, and clears each day's rows for the dims it re-fetched, so a value that fell out of the top list doesn't keep its old count. Days older than the retention can't be recomputed, so they stay as stored, and `sn_edge_honest_from` (also in `cloudflare-status` → `errors_5xx.honest_from`) records where the honest counts begin.
- Two test stubs of the corrector multiplied too, so their suites asserted the double count. Both now use the real behaviour, and every figure they pinned is restated as Cloudflare reports it.

