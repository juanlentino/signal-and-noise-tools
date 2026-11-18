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

## [2026.11.51] - 2026-11-18 — The Person node names Juan Martín Lentino and links GitHub; feed opens beside Today so far

### Added
- **The Person node carries the full name and the GitHub profile.** Every page's Person JSON-LD (`#/schema/Person`, `@id` unchanged) gains `alternateName` "Juan Martín Lentino" and "Juan Martin Lentino" (US records drop the accent), and `sameAs` gains `https://github.com/juanlentino`, the profile /resume links. `name`, `jobTitle` and every visible page still say what they said. alumniOf and memberOf were already there. No SSRN author page: the site links papers by DOI only, never an author page. Test: `tests/seo-schema.php` (+3).
- **Feed opens where you look.** `live-now` gains `feed_opens`: today (since 00:00 UTC, the day the store keys on), the last 7 days and which notes, labeled a floor. The SN Traffic widget and the Analytics footer pulse show "Feed: at least N opens today (UTC), M this week" beside Today so far. Same pixel count and same 7-day window as rss_stats and north-star, no new counter. Tests: `tests/feed-opens.php` (+3), `tests/analytics-live-slots.php` (the live answer plus `feed_opens`).

