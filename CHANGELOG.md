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
- **A record of which days Analytics Engine sampled.** The nightly rollup now also reads the last 90 site days and keeps, per day and event type, the rows Analytics Engine stored and the events they stand for, for every class and for the counted-human class alone (`inc/analytics-sampled-days.php`, option `sn_analytics_sampled_days`). A day is only added or overwritten from a complete fresh read, never deleted, and per day and event the reading with more stored rows wins, so a thinner re-read never replaces a fuller one and the evidence outlives Analytics Engine's retention. A run that writes nothing records why (`last_run`). The `analytics-dual-write` ability returns it as `sampled_days` with `onset`, the earliest day a human row was sampled; `sn_analytics_day_estimated()` answers per day, and Integrity › Lanes labels such a beacon day "estimated (sampled)". No number changes. Test: `tests/analytics-sampled-days.php`.
- **The contact form's blocked attempts, counted per day.** A late hook on Forms' spam verdict adds one per refused submission to that site day's count for the guard that caught it (honeypot, time trap, rate limit, rule, challenge, Akismet, other), kept 90 days, counts only. Integrity › Reports shows the daily table in both twins, scan or no scan, and `forms-spam-scan` returns it as `blocked_daily` (`inc/forms-blocked-tally.php`). Test: `tests/forms-blocked-tally.php`.

### Changed
- **The analytics-integrity check names what it recorded.** A flagged check's fix hint now leads with the recorded UTC time, day, path, class, views and pageview_visits, so `get-health-scan` and its remote twin show the scope without wp-cli. The record and its writer are unchanged. Test: `tests/health-analytics-integrity.php`.

## [2026.11.46] - 2026-11-15 — The Shares note describes how shares are joined

### Fixed
- **The Shares note and the sessions ability say how shares are joined now.** The note under Links › Shares still said a Meta fetch counts only on an `/r/` link and that shares are joined by page and time only. It now says a visit whose source names an app counts only a share through that app, a share of an `/r/` link stays on the link when the app then fetches the page it names, and Meta's preview renderer, recognized by its network, counts on any page while its crawler counts only on `/r/` links. The `share` field in the sessions ability description now names the app match; its output schema, which the remote contract hashes, is unchanged.

