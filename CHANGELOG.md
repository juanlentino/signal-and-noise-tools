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

## [2026.11.53] - 2026-11-22 — An hourly ingest canary tells a quiet day from a dead collector

### Added
- **An hourly ingest canary, so a quiet day no longer reads as a dead collector.** Once an hour the plugin sends one pageview of `/` to the real collector (`/_sn/px`, through Cloudflare, with the public beacon key) under the user agent `SignalNoise-IngestCanary/1.0`. The analytics Worker files a SignalNoise- user agent as this site's own tool (identity 7) in `sn_owner_hits`, so it is in no human, suspect or bot count; it shows only in the own-tools figure. The last answer is stored in `sn_ingest_canary_last`. New: `inc/analytics-ingest-canary.php`, test `tests/analytics-ingest-canary.php`.

### Changed
- **The Analytics ingest health check reads the canary.** It still flags at 24 hours without a human pageview when the canary is missing (the collector is likely dark) or could not be read. When a canary arrived in the last 24 hours, the collector is proven to write, so it waits 72 hours before flagging, and then points at the theme beacon or the worker's classifier. Prompted by 2026-11-20: a day with no presumed-human pageview (nine data-center visits, all suspect) raised the alarm though ingest was fine. Test: `tests/health-check-analytics-ingest.php` (+7; the quiet-day test goes red with the canary branch removed).

### Fixed
- **A test that expired with its date.** `tests/analytics-sessions-paired.php` passed a fixed day (2026-11-17) as today to the sampled-today note, so it went red on 2026-11-18. It now passes the current site day, and also pins that a window ending before today carries no note (red with the day comparison removed). Test only; no runtime change.
- **Composer's PHP platform pin is 8.3.37.** PHP shipped a security release on 2026-11-19 and `roave/security-advisories` now conflicts with 8.3 below 8.3.37, so the dev-dependency install failed and took PHPStan and the coding-standards job down on every pull request. `config.platform.php` moves from 8.3.36 to 8.3.37, as `docs/changelog/v13.md` says to do when roave raises the floor. Dev tooling only.

