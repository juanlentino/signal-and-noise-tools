# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.7.5] - 2026-09-23 — A tighter resume PDF

### Changed
- **The resume PDF fits more on its two pages.** Owner, 2026-09-23: "the space between lines can be less", sizes "dropping them a bit", and publication venue and date beside the title. Body line-height 1.25 to 1.1 (Dompdf sets Lato's lines taller than a browser at the same value; 1.05 was tried and crowds the bullets). Every size half a point down: body, bullets, summary and publications 9 to 8.5pt, name 22 to 20, headline 10 to 9.5, tagline 9.5 to 9, contact 9 to 8.5, section headings 10.5 to 10, company and role 9.5 to 9, location and dates 9 to 8.5, stat numbers 13 to 12 (competencies 8.5 and stat labels 7.5 unchanged). Each publication is one row, the linked title left and venue and date right-aligned like a role's dates, replacing the dash separator. Measured on a live-shaped fixture: page 2 ends at 393pt of 792, from 583pt, about 2.6 in freed; still two pages. The web page is untouched. `tests/resume-pdf.php` (38) pins the publication row and the missing separator (both fail against the old template).

