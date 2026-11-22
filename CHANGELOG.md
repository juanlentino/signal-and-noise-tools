# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.
- **Preview PDF previews the résumé PDF.** With résumé settings in the draft it streams the one résumé's layout (two pages, What I've Built, verified credits), the file Publish would build, instead of the music layout nothing publishes (`sn_resume_pdf_draft_parts()`, layout shared with Generate through `sn_resume_ops_pdf_layout()`). Test: `tests/resume-ops-pdf-build.php`.

## [Unreleased]

### Added
- **An hourly ingest canary, so a quiet day no longer reads as a dead collector.** Once an hour the plugin sends one pageview of `/` to the real collector (`/_sn/px`, through Cloudflare, with the public beacon key) under the user agent `SignalNoise-IngestCanary/1.0`. The analytics Worker files a SignalNoise- user agent as this site's own tool (identity 7) in `sn_owner_hits`, so it is in no human, suspect or bot count; it shows only in the own-tools figure. The last answer is stored in `sn_ingest_canary_last`. New: `inc/analytics-ingest-canary.php`, test `tests/analytics-ingest-canary.php`.

### Changed
- **The Analytics ingest health check reads the canary.** It still flags at 24 hours without a human pageview when the canary is missing (the collector is likely dark) or could not be read. When a canary arrived in the last 24 hours, the collector is proven to write, so it waits 72 hours before flagging, and then points at the theme beacon or the worker's classifier. Prompted by 2026-11-20: a day with no presumed-human pageview (nine data-center visits, all suspect) raised the alarm though ingest was fine. Test: `tests/health-check-analytics-ingest.php` (+7; the quiet-day test goes red with the canary branch removed).

## [2026.11.52] - 2026-11-18 — Feed opens count New York days; insights and narration retired from the remote door

### Changed
- **Feed opens count site days.** The open store is keyed by the day in the site timezone (midnight in New York), the day `today`, today_stored and Sessions count, instead of the UTC day. `feed_opens.today` in live-now is now since midnight in New York (`today_day` replaces `today_utc`), and the dashboard line reads "Feed: at least N opens today, M this week". The 7-day window counts calendar days, so a DST change never moves the cut. rss_stats and north-star use the same window, so their 7-day totals stay equal. One-time seam: opens recorded before this release are keyed by UTC day, so for the first day after install, an evening open in New York (after 7 or 8 pm) may count on the next day. Test: `tests/feed-opens.php` (+3; red with the UTC day).

### Removed
- **remote-get-insights and remote-get-narration are off the remote door (contract 36 -> 37).** Both answered null: they read 7-day caches that only an admin run fills. The weekly narration digest was retired in v9.4.1, and the insights weekly scan is opt-in and off (`insights.weekly_cron_enabled`), so no cron writes either cache. That is also why cron health showed nothing late. The remote door cannot start a run, so it could never fill them. The local get-insights and get-narration abilities, and the admin pages that run them, stay. The remote worker drops the two tools at contract 37. Tests: `tests/abilities-remote-set.php` (pins the retirement), `tests/remote-contract-shapes.php`, `tests/mcp-remote-guard.php`, `tests/mcp-door-manifest.php`, `tests/os-leaf-ai-agent-tools.php`; fixture `tests/fixtures/remote-output-schemas.json` regenerated.

