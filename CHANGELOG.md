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

## [2026.11.43] - 2026-11-15 — Stored live slots show readers' pages; a share link counts once

### Fixed
- **A share link counts as one page in the stored share counts, with or without its trailing slash.** The nightly per-day counts of share echoes grouped by the raw path, so `/r/jill` and `/r/jill/` were two rows, while joining a visit to its share already treated them as one link. The counts now group by the same page key as the join (`r:CODE` for a coded link, else the path with its trailing slash). Nothing reads the stored counts yet, and each night rewrites the last six days, so no migration is needed. Test: `tests/share-echoes.php` (red without the change).
- **A stored live slot's pages are its readers only, as Top now is.** The 24 hours of 5-minute slots listed every presumed-human visitor's page, so an arrival that sent nothing (a slot with `engaged: 0`) still showed /servicios or /workflow as being read, while the live Top now left it out. A slot's pages now count only visitors who sent visible time, a scroll milestone or a custom event in the slot (the same rule as Top now); its `readers`, `arrived` and sources are unchanged. Slots stored before this keep their old pages until they age out in 24 hours. Test: `tests/analytics-live-slots.php` (red without the filter).

