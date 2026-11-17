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

## [2026.11.49] - 2026-11-17 — LinkedIn link scans leave the suspect list; sampled-day labels on Countries, Sources and Top pages

### Changed
- **Countries, Sources and Top pages say when their window holds a sampled day.** Their totals over the 14-day window include the rows Analytics Engine kept on a sampled day, weighted up (the Niigata visit of 2026-11-16 reads as Japan 14 and /contact 12). When any day in the window is estimated in the sampled-day record, the card's title reads "Countries · estimated (sampled)", as the day rows elsewhere already do. Label only; no number changes. Code: `sn_analytics_title_estimated()` in `inc/analytics-sampled-days-label.php`. Test: `tests/analytics-sampled-days.php` (2 checks, red without the label).

### Added
- **An app checking a coded link it was just sent is no longer a suspect visit.** When a coded link is sent in a LinkedIn message, LinkedIn's verified preview bot fetches it and, within a minute, a browser on Microsoft's network opens it for a few seconds (2026-11-17, /r/angela: verified fetches 15:46:54 to 15:47:01 UTC, the browser at 15:47:48 from AS8075). The browser alone proves nothing, since the same network carries this site's own smoke tests, so it is recognized only through the verified fetch: a suspect-class session on the same /r/ code, one page view, starting within 60 seconds after it, joined by code and time only. The sessions read takes it out of the suspect list and counts it in `link_scans` (class suspect only; null otherwise or when the shares could not be read). The share itself is already in Links › Shares; class totals elsewhere still count the visit. Remote contract 35 to 36 (additive). Code: `inc/share-echoes-scan.php`. Test: `tests/share-echoes-scan.php` (red with a wider window or without the after-the-fetch guard).

