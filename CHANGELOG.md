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

## [2026.11.42] - 2026-11-15 — A link typed into a messaging app is one share

### Fixed
- **A link typed into a messaging app is one share, not a dozen (owner test 2026-11-14).** Telegram and WhatsApp fetch a preview as the link is typed, so sending `/r/prueba` recorded `/`, `/notes/`, `/r/te`, `/r/tel` ... `/r/prueba` within 16 seconds, and each would have listed as its own share (a code "tele" that was never sent). Fetches by one app, each within 60 seconds of the one before, now count as one share, credited to the last page fetched, the link actually sent. Ordering uses the worker's milliseconds, because a typed burst lands within one second. The owner's test now reads as two shares, Telegram and WhatsApp, both `/r/prueba`. Two people sharing through the same app within a minute of each other count once, which is fine at this site's volume. Test: `tests/share-echoes.php` (the owner's test as a fixture; red without the burst rule).
  - The same test showed Messenger leaves no trace: its encrypted chats build the preview on the phone without naming themselves, and Meta's server fetcher never asked for the link. The Shares note now says not every share is seen.

