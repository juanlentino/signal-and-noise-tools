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
- **A link typed into a messaging app is one share, not a dozen (owner test 2026-11-14).** Telegram and WhatsApp fetch a preview as the link is typed, so sending `/r/prueba` recorded `/`, `/notes/`, `/r/te`, `/r/tel` ... `/r/prueba` within 16 seconds, and each would have listed as its own share (a code "tele" that was never sent). Fetches by one app, each within 60 seconds of the one before, now count as one share, credited to the last page fetched, the link actually sent. Ordering uses the worker's milliseconds, because a typed burst lands within one second. The owner's test now reads as two shares, Telegram and WhatsApp, both `/r/prueba`. Two people sharing through the same app within a minute of each other count once, which is fine at this site's volume. Test: `tests/share-echoes.php` (the owner's test as a fixture; red without the burst rule).

## [2026.11.41] - 2026-11-15 — Reads on /stats; live slots and surge tell readers from arrivals

### Added
- **Public `/stats` opens How they read with reads (owner 2026-11-14).** "Reads (10 seconds on the page, half of it scrolled, or a click)", from the nightly reads store. It is shown only when every day of the window was measured, so a partial count is never published; a window the backfill has not reached shows no row, never 0. Test: `tests/public-stats.php`.
- **Live slots and surge tell readers from arrivals.** Each 5-minute slot of the live hour carries `engaged` (visitors with an engagement signal in the slot) beside `readers` (every active visitor), and each stored slot carries `engaged` and `arrived` (null for slots stored before this release, never 0). Surge keeps a second baseline of engaged readers (`sn_live_slot_log_readers`). Until that baseline holds the four days surge needs, it judges all active visitors as before, and once it does, it judges readers. Either way the verdict names its basis (`basis`: readers or visitors, with `readers_days` while learning). The hour bars still draw active visitors. Tests: `tests/analytics-live-surge.php`, `tests/analytics-live-slots.php`.

### Fixed
- **An `Undefined array key "views"` warning from an analytics render file (seen 2026-11-12).** The log redacts which file, so every unguarded `views` read in `inc/analytics-render-*.php` now defaults to 0.
- **The two retired backfill cursors are removed** (`sn_bot_signals_by_day_backfill`, `sn_analytics_reads_backfill`). Nothing has read them since the walks restarted under new names.

