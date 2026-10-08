# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.1.0] - 2026-10-08 — being read now on /stats

### Added
- **Being read now, on /stats.** Under the Live figures, the pages people are on right now (the same 5-minute window as Reading now), each with its reader count, top five, in the most-read list's style. One more grouped query per realtime refresh (`inc/analytics-live-pages.php`); a path is listed only when it resolves to a published, unprotected note or page that is not hidden from search (or Home), so a draft, a private or noindex page, another post type or a junk path never appears; two spellings of one page count as one entry. A query string folds into its page. Not read yet stays a dash; nobody on a page says so. Aggregate counts, cookieless as before; nothing new collected.

