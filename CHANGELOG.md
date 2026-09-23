# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.7.4] - 2026-09-23 — The PDF's own summary

### Changed
- **The resume PDF's professional summary can have its own wording.** The PDF already printed a PROFESSIONAL SUMMARY section, always the Summary from Hero, the same text as /resume. A Professional summary field in the PDF-only section (both editors) now overrides it for the PDF; blank keeps today's behavior. /resume never reads it (the sync engine ignores `pdf`). `tests/resume-pdf.php` (36) pins the override and the fallback (dropping the override fails it), `tests/resume-pdf-page-invariance.php` (18) that the page stays byte-identical with it filled, and `tests/os-leaf-content-resume.php` (86) that the dashboard leaf carries it and saves it round trip.

