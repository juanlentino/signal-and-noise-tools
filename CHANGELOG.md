# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.9.1] - 2026-09-23 — sampled edge figures counted once

### Fixed
- **Sampled edge figures were counted twice over.** Cloudflare returns a grouped adaptive `count` (and every `sum`) already scaled up to its estimate ("Cloudflare will estimate 50,000 total events (5,000 × 10) and report this value", analytics/graphql-api/sampling). `sn_edge_corrected()` multiplied it by `sampleInterval` again, so every figure built from sampled groups was inflated: threats, edge locations and their bytes, every attack-surface panel, and the new 5xx reading. The inflation was measured, not guessed: the week's 5xx rows read about seven times the zone's exact total. Grouped counts and sums are now taken as reported. Raw, ungrouped events still weigh by their `sampleInterval`, because a raw row does stand for that many events.
- **The inflated history is repaired where it can be.** A one-shot cron job re-rolls every day Cloudflare's sampled dataset still retains, and clears each day's rows for the dims it re-fetched, so a value that fell out of the top list doesn't keep its old count. Days older than the retention can't be recomputed, so they stay as stored, and `sn_edge_honest_from` (also in `cloudflare-status` → `errors_5xx.honest_from`) records where the honest counts begin.
- Two test stubs of the corrector multiplied too, so their suites asserted the double count. Both now use the real behaviour, and every figure they pinned is restated as Cloudflare reports it.

