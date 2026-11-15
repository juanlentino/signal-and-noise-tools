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
- **Public `/stats` opens How they read with reads (owner 2026-11-14).** "Reads (10 seconds on the page, half of it scrolled, or a click)", from the nightly reads store. It is shown only when every day of the window was measured, so a partial count is never published; a window the backfill has not reached shows no row, never 0. Test: `tests/public-stats.php`.
- **Live slots and surge tell readers from arrivals.** Each 5-minute slot of the live hour carries `engaged` (visitors with an engagement signal in the slot) beside `readers` (every active visitor), and each stored slot carries `engaged` and `arrived` (null for slots stored before this release, never 0). Surge keeps a second baseline of engaged readers (`sn_live_slot_log_readers`). Until that baseline holds the four days surge needs, it judges all active visitors as before, and once it does, it judges readers. Either way the verdict names its basis (`basis`: readers or visitors, with `readers_days` while learning). The hour bars still draw active visitors. Tests: `tests/analytics-live-surge.php`, `tests/analytics-live-slots.php`.

### Fixed
- **An `Undefined array key "views"` warning from an analytics render file (seen 2026-11-12).** The log redacts which file, so every unguarded `views` read in `inc/analytics-render-*.php` now defaults to 0.
- **The two retired backfill cursors are removed** (`sn_bot_signals_by_day_backfill`, `sn_analytics_reads_backfill`). Nothing has read them since the walks restarted under new names.

## [2026.11.40] - 2026-11-14 — SN Traffic: one-line strict figure; This week and Top pages move to SN Reading

### Changed
- **SN Traffic's strictly-human line is one line (owner 2026-11-14).** Under the reads headline the card's strict line spelled out both removal reasons and wrapped to three lines, so the Traffic card grew while the others stayed the same. It now reads "66 strictly human views", with "(partial)" when a removal window is partial. The two removal counts, any partial UTC window and the rule's reasons ride word for word in its hover text and, so they never depend on a pointer, in screen-reader text. The figure and its sources are unchanged. Test: `tests/desktop-mode-widget-views-delta.php` (the render harness now reports each row's hover text).
- **This week and Top pages moved from SN Traffic to SN Reading (owner 2026-11-14).** What people read is Reading's; Traffic keeps who came and from where. Reading now opens with This week (engaged visitor-days with its change, DOI downloads, inquiries; absent when the north star is not set up, never zeros), and lists Top pages (the window's three most-viewed pages, human) after On the page. Same figures and sources as Traffic showed. The week's change keeps Traffic's color rule: colored only when it is at least 5 and 20% of the prior week. The Traffic card loses about eight lines and Reading gains them (`inc/desktop-mode-reading-moved.php`). Test: `tests/desktop-mode-reading-moved.php` (new).

