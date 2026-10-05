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
- **/stats: Visits counts days a reader opened a page.** The tile read the rollup's plain visits, which also count feed- and beacon-only reader-days, so it showed 520 visits against 337 views over 30 days. It now reads the analytics module's headline figure, reader-days with at least one pageview (250 over the same 30 days), under the owner-approved line "days a reader opened at least one page; the same reader tomorrow counts again". An unmeasured figure leaves the tile out rather than showing 0. The page stays read-only over the stored analytics.

## [21.9.0] - 2026-10-05 — twelve desktop cards fold to six

### Added
- **Twelve desktop cards fold to six, as the owner approved.** Three cards now carry their neighbors and keep their ids, so each keeps its place on the desktop. **SN Traffic** (was SN Site Views) keeps the views headline, the trend and the sparkline, then shows today so far; This week (engaged readers 7d with its change, DOI downloads, inquiries 7d); a reach row (distinct countries and sources in 14 days, each with its change against the prior 14 days); top countries, top sources, Campaigns when a tagged link was followed, the latest Hacker News story; one row each for devices, search (clicks and impressions per engine) and feed subscribers (24h, 7d, 30d); and top pages, with one Open Analytics link. **SN Systems** (was SN Health) says "All systems normal" in one line when uptime, the content-health checks and cron are all fine, and says what is wrong when they are not ("1 down", "1 to look at · 1 could not run"); its uptime row condenses the monitors ("4 of 4 up · 100% over 30 days · average 108 ms", no change shown since the uptime data has no prior period); it names a monitor only when one is not up and an orphaned cron event only when there is one. **SN Provenance** (was SN Anchors) adds the machine readers under the anchors: reads in the last 30 days, who the edge verified, declared AI-training reads and those that fetched the rights files directly, and the top crawler family with its share and its change against the prior 30 days, with links to Provenance and Machine Readers. Everything else the old cards showed (read 2+ pages, downloads outbound, the bot share, the top mover, the longer country, source and story lists, feed request counts, per-monitor uptime, the cron breakdown, crawler families, AI-training surfaces and purposes) lives in S&N Analytics and the full Health, Cron, RSS and Machine Readers windows. SN Audience, SN RSS Subscribers, SN Uptime, SN Cron, SN Quick Actions and SN Machine Readers are retired. Quick Actions' buttons moved: "Clear DB overrides" to SN Systems, now behind a confirm, and "Check for updates" to SN Deploy Status. A saved layout keeps each card's old height, so resize SN Traffic, SN Systems, SN Provenance and SN Deploy Status once.

### Fixed
- **The rollup-replace test no longer fails near midnight.** `tests/analytics-rollup-replace.php` built its expected window days from a separate "now", while `sn_analytics_rollup_window_days()` reads its first day at now+300 s and its last at now-300 s. On 2026-10-04 at 23:56 to 23:58 UTC the bounded-batch assertion failed on main and on CI, then passed minutes later. The test now captures the exact second the code read and builds both expectations from it, with the same skew and zone. It also expects six days instead of seven while the skew straddles a UTC midnight, which is what the code names then. Verified under a fixed fake clock at 12:00, 23:58, 00:02, 03:58 and 04:02 UTC: the old test fails at 23:58 and 00:02, the new one passes at all five. Tests only, no release.

