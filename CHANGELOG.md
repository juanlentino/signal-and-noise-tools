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

## [2026.11.36] - 2026-11-12 — Résumé editor: On /resume is Shown or Hidden; credentials follow /resume

### Changed
- **"On /resume" is Shown or Hidden, nothing else.** The third choice, "Shown, not on the music PDF", is gone from both editors: with no music PDF it meant Shown. A row or bullet still stored with it shows as Shown and is saved as Shown on the next save, which changes nothing on /resume or in either PDF (`inc/admin-forms/resume-variants.php`, `apps/sn-dashboard/parts/leaves/content-resume-variants.php`). Tests: `tests/resume-admin-form.php` (fails on the old select), `tests/os-leaf-content-resume.php` (the data-safety round trip now expects the fold).
- **The Person's credentials (schema.org `hasCredential` on profile pages) come from what /resume shows.** They read the old music view, which the fold above would have changed on the next save; they now read `sn_resume_shown_doc()` through `sn_resume_credentials_doc()`, so the graph lists the same degrees and certificates as /resume, no more and no fewer (`inc/resume-credentials.php`). Test: `tests/resume-one.php` (fails with the old source).
- **The removed setup's leftover option is deleted.** `sn_resume_ops_setup_missing`, which nothing reads since 2026.11.35, is removed on the next admin load (`inc/resume-variants.php`).
- **Not changed, on purpose:** the stored headline, tagline, summary, location, competencies and toolkit stay in the document. The résumé settings fall back to them only when a setting is blank, and all of them are set.

