# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.7.1] - 2026-09-22 — the resume PDF contact line

### Fixed
- **The resume PDF's contact line reuses what the resume already knows.** The first generated PDF showed only LinkedIn: the location came solely from the new PDF-only field, which was blank, so "Orlando, FL" (already on the web page's contact line) silently vanished, and the site address the design shows was never printed (owner, 2026-09-22). The location now falls back to the web contact line when the PDF-only field is empty, and the site's home URL prints as its bare host, linked (`juanlentino.com`). Order as the design: location, phone (per the switch), email, LinkedIn, site. `tests/resume-pdf.php` (30) reads the location, LinkedIn and the site back out of the PDF bytes with the field blank; removing the fallback fails it. Headline, tagline and email have no web equivalent and still come from the PDF-only section.

