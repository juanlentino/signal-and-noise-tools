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
- **A read needs a page view.** The first live run of the reads store (2026-11-14) counted 23 reads for 11-08 to 11-14, where the Sessions read gives 15. The read grouped time, scroll and custom-event beacons without asking for a page view, and a beacon-only visitor-day (about 30 of 100 in that fortnight) sends those with none. The read now also reads page views, and only a visitor-day, page and site day with at least one counts. Bot-class rows are left out of this read to keep it under its row cap. The backfill walks again under a new cursor (`sn_analytics_reads_backfill_v2`), so every stored day is measured again: the trailing week on the next run, then one older week a night. Test: `tests/analytics-reads.php` (a beacon-only row is not a read; it is red without the check).

## [2026.11.38] - 2026-11-14 — Reads lead the owner's numbers; share echoes show which shares turned into visits

### Added
- **Share echoes: the owner can see which shares turned into visits (owner decision 2026-11-14).** When a link to the site is pasted into WhatsApp, Messenger, LinkedIn, Slack, Discord, Telegram, X or Apple Messages, the app fetches the page for its preview. Rights-signals 1.39.0 records each fetch (time, path and app only), and the plugin reads it through the existing machine-readers read with `view=echoes`. Every field is validated. A worker that answers rows that are not echoes reads as a failed read, never as shares. A read that reached its row cap is treated as failed, never joined or stored as complete. Repeat fetches by one app within a minute count as one share. Two rules keep crawls out:
  - **A Meta fetch counts only on an `/r/` link.** Verified `facebookexternalhit` fetched about 4,300 pages in the 14 days to 11-14, about 300 a day, so on any other page almost every visit would follow one.
  - **A fetch claiming Meta, LinkedIn, Slack, Discord or X counts only when Cloudflare verified it.** WhatsApp, Signal and Apple Messages build the preview on the sender's phone, so they cannot be verified and are not gated; Telegram is not gated either, because no verified listing for it could be confirmed. Meta re-fetches every shared page about every 30 days on its own (Meta's sharing docs), which is why its fetch counts only on an `/r/` link.
  - **The join** is `inc/share-echoes.php`. Each human session is joined to the most recent echo on its entry page (for `/r/CODE`, the same code) in the 72 hours before it, by page and time only, never by a visitor identifier. Two echoes before one visit go to the later one. A visit 80 hours later is not joined.
  - **The Sessions list and the sessions read** (both doors) carry `share` (`app`, `family`, `gap_s`, `read`, or null), shown as "after a share via WhatsApp, 40 min, read". Each page view gains `read`, by the same rule as the Reads count. The remote contract moves 32 to 33.
  - **The Links view** gains "Shares, last 14 days" (`inc/analytics-view-shares.php`): when, page, app, and what followed (each visit's gap and whether it was a read). A share no one opened yet says "no visit yet".
  - **Durable counts**, echoes per site day, app and page, are kept nightly in `sn_share_echoes_by_day`.
  - **Wording:** copy says "after a share", never "from your share", and names the app, never a person, because a preview fires whoever shares the link.
  - **Privacy:** the suggested privacy-policy text gains one sentence: the site notes when link-preview services fetch a page, and keeps the time, page and service only. The live privacy page is unchanged until the owner adds it.
  - Test: `tests/share-echoes.php` (new); `tests/analytics-sessions-read.php` pins `read` and `share`.
- **Reads lead the owner's numbers: proven reading, not page loads (owner decision 2026-11-14).** A read is a page view with at least 10 seconds of visible time, a scroll to 50 percent or more, or a custom event (an outbound click, a download, a goal click; every custom event the beacon sends follows a click). One rule, `sn_analytics_is_read()`, so every surface counts the same way. A scraper that loads a page and leaves never makes one, wherever it rents servers. A read is counted once per visitor-day, page and site day: the beacons of two views of the same page that day add up, so two short views can make one read and two long ones count one. A new nightly store (`sn_analytics_reads_by_day`, `inc/analytics-reads.php` and `inc/analytics-reads-store.php`) keeps reads per site day, page and class, reads the trailing week each night, and walks back 14 days a night as far as Analytics Engine holds (88 days). A day not yet stored reads "not measured", never 0, and a read that fails or reaches its row cap stores nothing. The read keys on the site's day, not the UTC day, because the visitor code rotates there. It uses only shapes already live on Analytics Engine (`sumIf` over the time column, `max(if(cond, 1, 0))`), so it avoids the Double/Integer mix that refused the 11-11 query.
  - **SN Traffic** leads with reads (human) over 14 days, with the presumed-human views and the strictly-human line under it.
  - **The Analytics Overview** gains a Reads card, first in the strip.
  - **The Links view** gains a Reads column per `/r/` code (human).
  - **`get-analytics-summary` and its remote twin** gain `reads` (`reads`, `days`, `measured_days`, `partial`, `definition`), with the definition in both descriptions. The remote contract moves 31 to 32, and the sn-remote-mcp worker's `CONTRACT_VERSION` moves with it.
  - Views, `human` and the strict count are computed as before. Public `/stats` is unchanged.
  - Test: `tests/analytics-reads.php` (new: each branch of the definition, the brief's four named views, measured with none against not measured, the per-code count, the read's SQL); `tests/desktop-mode-widget-views-delta.php` pins the card's three states.

