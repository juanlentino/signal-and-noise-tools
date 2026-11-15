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
- **The Shares note and the sessions ability say how shares are joined now.** The note under Links › Shares still said a Meta fetch counts only on an `/r/` link and that shares are joined by page and time only. It now says a visit whose source names an app counts only a share through that app, a share of an `/r/` link stays on the link when the app then fetches the page it names, and Meta's preview renderer, recognized by its network, counts on any page while its crawler counts only on `/r/` links. The `share` field in the sessions ability description says the same; its output schema, which the remote contract hashes, is unchanged.

## [2026.11.45] - 2026-11-15 — A coded link stays the share when the app fetches the page it names

### Fixed
- **A coded link stays the share when the app then fetches the page it names.** `/r/CODE` names `/resume/` as its `og:url`, and Telegram fetches that page right after the link (2026-11-14 20:01 ET: `/r/prueba` sent, `/resume/` fetched two seconds later). Fetches by one app within 60 seconds are one share credited to the last page, so the share landed on `/resume` and later plain `/resume` visits inherited it. Within a burst, a plain page no longer replaces an `/r/` link; a later `/r/` fetch still replaces a plain one, so a link typed letter by letter still ends on the link sent. Shares are joined when read, so past shares are corrected. Test: `tests/share-echoes.php` (2 checks red without the change).

