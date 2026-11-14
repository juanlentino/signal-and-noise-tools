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

## [2026.11.40] - 2026-11-14 — SN Traffic: one-line strict figure; This week and Top pages move to SN Reading

### Changed
- **SN Traffic's strictly-human line is one line (owner 2026-11-14).** Under the reads headline the card's strict line spelled out both removal reasons and wrapped to three lines, so the Traffic card grew while the others stayed the same. It now reads "66 strictly human views", with "(partial)" when a removal window is partial. The two removal counts, any partial UTC window and the rule's reasons ride word for word in its hover text and, so they never depend on a pointer, in screen-reader text. The figure and its sources are unchanged. Test: `tests/desktop-mode-widget-views-delta.php` (the render harness now reports each row's hover text).
- **This week and Top pages moved from SN Traffic to SN Reading (owner 2026-11-14).** What people read is Reading's; Traffic keeps who came and from where. Reading now opens with This week (engaged visitor-days with its change, DOI downloads, inquiries; absent when the north star is not set up, never zeros), and lists Top pages (the window's three most-viewed pages, human) after On the page. Same figures and sources as Traffic showed. The week's change keeps Traffic's color rule: colored only when it is at least 5 and 20% of the prior week. The Traffic card loses about eight lines and Reading gains them (`inc/desktop-mode-reading-moved.php`). Test: `tests/desktop-mode-reading-moved.php` (new).

