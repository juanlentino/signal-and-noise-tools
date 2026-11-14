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
- **Reads lead the owner's numbers: proven reading, not page loads (owner decision 2026-11-14).** A read is a page view with at least 10 seconds of visible time, a scroll to 50 percent or more, or a custom event (an outbound click, a download, a goal click; every custom event the beacon sends follows a click). One rule, `sn_analytics_is_read()`, so every surface counts the same way. A scraper that loads a page and leaves never makes one, wherever it rents servers. A read is counted once per visitor-day, page and site day: the beacons of two views of the same page that day add up, so two short views can make one read and two long ones count one. A new nightly store (`sn_analytics_reads_by_day`, `inc/analytics-reads.php` and `inc/analytics-reads-store.php`) keeps reads per site day, page and class, reads the trailing week each night, and walks back 14 days a night as far as Analytics Engine holds (88 days). A day not yet stored reads "not measured", never 0, and a read that fails or reaches its row cap stores nothing. The read keys on the site's day, not the UTC day, because the visitor code rotates there. It uses only shapes already live on Analytics Engine (`sumIf` over the time column, `max(if(cond, 1, 0))`), so it avoids the Double/Integer mix that refused the 11-11 query.
  - **SN Traffic** leads with reads (human) over 14 days, with the presumed-human views and the strictly-human line under it.
  - **The Analytics Overview** gains a Reads card, first in the strip.
  - **The Links view** gains a Reads column per `/r/` code (human).
  - **`get-analytics-summary` and its remote twin** gain `reads` (`reads`, `days`, `measured_days`, `partial`, `definition`), with the definition in both descriptions. The remote contract moves 31 to 32, and the sn-remote-mcp worker's `CONTRACT_VERSION` moves with it.
  - Views, `human` and the strict count are computed as before. Public `/stats` is unchanged.
  - Test: `tests/analytics-reads.php` (new: each branch of the definition, the brief's four named views, measured with none against not measured, the per-code count, the read's SQL); `tests/desktop-mode-widget-views-delta.php` pins the card's three states.

## [2026.11.37] - 2026-11-13 — Strictly human removes zero-engagement visitor-days, named apart

### Added
- **Strictly human removes a second kind of visitor-day: no engagement recorded (strict rule v2, owner decision 2026-11-13).** A presumed-human visitor-day leaves the strict figure when it had exactly one page view, direct, on desktop, with no time, scroll or custom event recorded, on a page other than `/resume`, `/cv` or an `/r/` link code. Behavior only: no country, city or network test. Mobile, any referrer and the job-search pages stay counted. The visitor-day is judged whole across UTC days, so a page view whose time event lands after UTC midnight is kept. Two such sessions in one visitor-day are kept too, which narrows the brief's "every session". Where both reasons apply the automation-signal removal wins, so a visitor-day is removed once. Every surface names the two counts apart ("1 removed: automation signals; 21 removed: no engagement recorded"), and a range whose days predate the reading says "not measured", never 0. `human` is unchanged. The nightly per-day store reads the new columns (`inc/analytics-strict-no-engagement.php`) and walks the Analytics Engine window again under a new cursor (`sn_bot_signals_by_day_backfill_v2`), so the first nights after install fill in the older days. The `strict` field gains `removed_no_engagement`, `reason_no_engagement` and `window_no_engagement`; `rule` is now `strict_v2`. The SN dashboard gains a "Removed: no engagement recorded" tile. Test: `tests/analytics-strict-no-engagement.php` (new).

