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
- **SN Traffic's strictly-human line is one line (owner 2026-11-14).** Under the reads headline the card's strict line spelled out both removal reasons and wrapped to three lines, so the Traffic card grew while the others stayed the same. It now reads "66 strictly human views", with "(partial)" when a removal window is partial. The two removal counts, any partial UTC window and the rule's reasons ride word for word in its hover text. The figure and its sources are unchanged. Test: `tests/desktop-mode-widget-views-delta.php` (the render harness now reports each row's hover text).

## [2026.11.39] - 2026-11-14 — Reading now counts readers; a read needs a page view; time bucketed per view

### Changed
- **"Reading now" counts readers, not arrivals (owner brief 2026-11-14).** At 16:50 ET on 11-14 a page view of `/cv` with nothing after it showed as "Reading now 1" and "Top now: Curriculum vitae" for five minutes. The owner's live figures now count only visitors whose visit sent an engagement signal in the last 5 minutes: visible time (a `tm` with time in it), a scroll milestone or a custom event (`inc/analytics-live-engaged.php`, the strict rule's own engagement shape). Visitors with a page view and nothing else yet are counted apart as `arrived`.
  - **The tile** shows them on a smaller line, "Just arrived", under "Reading now".
  - **Top now** lists only pages with a reader on them, coded links included.
  - **The Now card and Right now** read `sn_analytics_realtime_readers()`, which is null when the cache predates the split, never the arrivals' total.
  - **The readers list** keeps only engaged visits.
  - **The live-now ability** gains `arrived` and `arrived_classes`.
  - **Unchanged:** the public `/stats` count and its pages list, which read the same cache.
  - **Surge** still reads the hour's active visitors. Moving it to readers would restart its 4-day learning window; that is left for the owner.
  - The theme's "still here" ping (theme 2026.11.13) is what lets a reader who neither scrolls nor leaves show as reading after 10 seconds.
  - Tests: `tests/analytics-live-readers.php`, `tests/analytics-live.php`, `tests/analytics-realtime.php`, `tests/analytics-live-scroll.php`, `tests/desktop-mode-widget-views-delta.php`.

### Fixed
- **A read needs a page view.** The first live run of the reads store (2026-11-14) counted 23 reads for 11-08 to 11-14, where the Sessions read gives 15. The read grouped time, scroll and custom-event beacons without asking for a page view, and a beacon-only visitor-day (about 30 of 100 in that fortnight) sends those with none. The read now also reads page views, and only a visitor-day, page and site day with at least one counts. Bot-class rows are left out of this read to keep it under its row cap. The backfill walks again under a new cursor (`sn_analytics_reads_backfill_v2`), so every stored day is measured again: the trailing week on the next run, then one older week a night. Test: `tests/analytics-reads.php` (a beacon-only row is not a read; it is red without the check).
- **Time on page is bucketed per view, not per `tm` delta.** A view's visible time arrives as one or more deltas: the leave flush, each return to the tab, and now the 10-second ping. The time distribution and the engaged rate bucketed each delta on its own, so a 25-second view sent as 10 s and 15 s counted twice, once in 0–10s. `inc/analytics-buckets-time.php` sums the deltas per visitor-day, page and UTC day in Analytics Engine, then buckets that sum in PHP; a read that reaches its 5,000-row cap is not written. Test: `tests/analytics-buckets-time.php` (new).

