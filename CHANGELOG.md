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

### Fixed
- **A test that expired with its date.** `tests/analytics-sessions-paired.php` passed a fixed day (2026-11-17) as today to the sampled-today note, so it went red on 2026-11-18. It now passes the current site day, and also pins that a window ending before today carries no note (red with the day comparison removed). Test only; no runtime change.

## [2026.11.52] - 2026-11-18 — Feed opens count New York days; insights and narration retired from the remote door

### Changed
- **Feed opens count site days.** The open store is keyed by the day in the site timezone (midnight in New York), the day `today`, today_stored and Sessions count, instead of the UTC day. `feed_opens.today` in live-now is now since midnight in New York (`today_day` replaces `today_utc`), and the dashboard line reads "Feed: at least N opens today, M this week". The 7-day window counts calendar days, so a DST change never moves the cut. rss_stats and north-star use the same window, so their 7-day totals stay equal. One-time seam: opens recorded before this release are keyed by UTC day, so for the first day after install, an evening open in New York (after 7 or 8 pm) may count on the next day. Test: `tests/feed-opens.php` (+3; red with the UTC day).

### Removed
- **remote-get-insights and remote-get-narration are off the remote door (contract 36 -> 37).** Both answered null: they read 7-day caches that only an admin run fills. The weekly narration digest was retired in v9.4.1, and the insights weekly scan is opt-in and off (`insights.weekly_cron_enabled`), so no cron writes either cache. That is also why cron health showed nothing late. The remote door cannot start a run, so it could never fill them. The local get-insights and get-narration abilities, and the admin pages that run them, stay. The remote worker drops the two tools at contract 37. Tests: `tests/abilities-remote-set.php` (pins the retirement), `tests/remote-contract-shapes.php`, `tests/mcp-remote-guard.php`, `tests/mcp-door-manifest.php`, `tests/os-leaf-ai-agent-tools.php`; fixture `tests/fixtures/remote-output-schemas.json` regenerated.

