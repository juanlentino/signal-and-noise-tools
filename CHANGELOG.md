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

### Changed
- **Feed opens count site days.** The open store is keyed by the day in the site timezone (midnight in New York), the day `today`, today_stored and Sessions count, instead of the UTC day. `feed_opens.today` in live-now is now since midnight in New York (`today_day` replaces `today_utc`), and the dashboard line reads "Feed: at least N opens today, M this week". The 7-day window counts calendar days, so a DST change never moves the cut. rss_stats and north-star use the same window, so their 7-day totals stay equal. One-time seam: opens recorded before this release are keyed by UTC day, so for the first day after install, an evening open in New York (after 7 or 8 pm) may count on the next day. Test: `tests/feed-opens.php` (+3; red with the UTC day).

### Removed
- **remote-get-insights and remote-get-narration are off the remote door (contract 36 -> 37).** Both answered null: they read 7-day caches that only an admin run fills. The weekly narration digest was retired in v9.4.1, and the insights weekly scan is opt-in and off (`insights.weekly_cron_enabled`), so no cron writes either cache. That is also why cron health showed nothing late. The remote door cannot start a run, so it could never fill them. The local get-insights and get-narration abilities, and the admin pages that run them, stay. The remote worker drops the two tools at contract 37. Tests: `tests/abilities-remote-set.php` (pins the retirement), `tests/remote-contract-shapes.php`, `tests/mcp-remote-guard.php`, `tests/mcp-door-manifest.php`, `tests/os-leaf-ai-agent-tools.php`; fixture `tests/fixtures/remote-output-schemas.json` regenerated.

## [2026.11.51] - 2026-11-18 — The Person node names Juan Martín Lentino and links GitHub; feed opens beside Today so far

### Added
- **The Person node carries the full name and the GitHub profile.** Every page's Person JSON-LD (`#/schema/Person`, `@id` unchanged) gains `alternateName` "Juan Martín Lentino" and "Juan Martin Lentino" (US records drop the accent), and `sameAs` gains `https://github.com/juanlentino`, the profile /resume links. `name`, `jobTitle` and every visible page still say what they said. alumniOf and memberOf were already there. No SSRN author page: the site links papers by DOI only, never an author page. Test: `tests/seo-schema.php` (+3).
- **Feed opens where you look.** `live-now` gains `feed_opens`: today (since 00:00 UTC, the day the store keys on), the last 7 days and which notes, labeled a floor. The SN Traffic widget and the Analytics footer pulse show "Feed: at least N opens today (UTC), M this week" beside Today so far. Same pixel count and same 7-day window as rss_stats and north-star, no new counter. Tests: `tests/feed-opens.php` (+3), `tests/analytics-live-slots.php` (the live answer plus `feed_opens`).

