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
- **Every Analytics Engine read takes the visitor-day from the first 16 characters of the index.** The analytics worker can then give each event type its own index, so Analytics Engine stops sampling a burst of one visitor's events as one; figures are unchanged, because every index written today is exactly those 16 characters. One constant, `SN_AE_VID` in `inc/analytics-vid.php`, holds the expression; each reader requires it. `count(DISTINCT substring(index1, 1, 16))` was checked on the live dataset on 2026-11-17 and matched `count(DISTINCT index1)`, so the dialect guard now allows exactly that expression and still rejects every other one. Tests: `tests/analytics-vid-prefix.php` (no SQL string in `inc/` names `index1` outside the helper), `tests/analytics-sql-dialect.php`.

## [2026.11.46] - 2026-11-15 — The Shares note describes how shares are joined

### Fixed
- **The Shares note and the sessions ability say how shares are joined now.** The note under Links › Shares still said a Meta fetch counts only on an `/r/` link and that shares are joined by page and time only. It now says a visit whose source names an app counts only a share through that app, a share of an `/r/` link stays on the link when the app then fetches the page it names, and Meta's preview renderer, recognized by its network, counts on any page while its crawler counts only on `/r/` links. The `share` field in the sessions ability description now names the app match; its output schema, which the remote contract hashes, is unchanged.

