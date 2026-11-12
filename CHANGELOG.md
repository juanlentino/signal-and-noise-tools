# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The résumé PDF is JuanLentino_Resume.pdf again (owner, 2026-11-12).** The one résumé's PDF defaults to `/resume/JuanLentino_Resume.pdf`, both on a blank "PDF file name" and in the Operations setup. The stored name decides, so the live file changes when the owner sets that field and presses Generate. Its two old addresses redirect there with a 301 and the ?v= of the current file: `/resume/JuanLentino_Resume_Operations.pdf`, and `/wp-content/uploads/resume/JuanLentino_Resume.pdf`, where the music PDF used to be. No address is redirected while it is still the stored name, or when no PDF is live. The music PDF, linked from nothing public, is now written as `JuanLentino_Resume_Music.pdf`. Generate removes the old uploads file and purges its address and every ?v= ever issued, since a static file is served before WordPress can redirect it (`inc/resume-pdf/generate.php`, `inc/resume-pdf/operations.php`, `inc/resume-variants.php`, `inc/resume-operations-setup.php`, `docs/RESUME-PDF.md`). Tests: `tests/resume-ops-pdf-build.php` (38; the new group fails with either change removed), plus the file names pinned in `resume-one`, `resume-head`, `resume-pdf` and `resume-no-phone`.

## [2026.11.33] - 2026-11-12 — Spanish CV PDF credits line in Spanish

### Fixed
- **The Spanish CV PDF's credits line is in Spanish.** The PDF template printed "Verified credits:" in English on JuanLentino_CV.pdf too; it now reads "Créditos verificados:" there, as /cv already did (`inc/resume-pdf/template.php`). Test: `tests/resume-variants.php`.

