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

### Added
- **Strictly human removes a second kind of visitor-day: no engagement recorded (strict rule v2, owner decision 2026-11-13).** A presumed-human visitor-day leaves the strict figure when it had exactly one page view, direct, on desktop, with no time, scroll or custom event recorded, on a page other than `/resume`, `/cv` or an `/r/` link code. Behavior only: no country, city or network test. Mobile, any referrer and the job-search pages stay counted. The visitor-day is judged whole across UTC days, so a page view whose time event lands after UTC midnight is kept. Two such sessions in one visitor-day are kept too, which narrows the brief's "every session". Where both reasons apply the automation-signal removal wins, so a visitor-day is removed once. Every surface names the two counts apart ("1 removed: automation signals; 21 removed: no engagement recorded"), and a range whose days predate the reading says "not measured", never 0. `human` is unchanged. The nightly per-day store reads the new columns (`inc/analytics-strict-no-engagement.php`) and walks the Analytics Engine window again under a new cursor (`sn_bot_signals_by_day_backfill_v2`), so the first nights after install fill in the older days. The `strict` field gains `removed_no_engagement`, `reason_no_engagement` and `window_no_engagement`; `rule` is now `strict_v2`. The SN dashboard gains a "Removed: no engagement recorded" tile. Test: `tests/analytics-strict-no-engagement.php` (new).

## [2026.11.36] - 2026-11-12 — Résumé editor: On /resume is Shown or Hidden; credentials follow /resume

### Changed
- **"On /resume" is Shown or Hidden, nothing else.** The third choice, "Shown, not on the music PDF", is gone from both editors: with no music PDF it meant Shown. A row or bullet still stored with it shows as Shown and is saved as Shown on the next save, which changes nothing on /resume or in either PDF (`inc/admin-forms/resume-variants.php`, `apps/sn-dashboard/parts/leaves/content-resume-variants.php`). Tests: `tests/resume-admin-form.php` (fails on the old select), `tests/os-leaf-content-resume.php` (the data-safety round trip now expects the fold).
- **The Person's credentials (schema.org `hasCredential` on profile pages) come from what /resume shows.** They read the old music view, which the fold above would have changed on the next save; they now read `sn_resume_shown_doc()` through `sn_resume_credentials_doc()`, so the graph lists the same degrees and certificates as /resume, no more and no fewer (`inc/resume-credentials.php`). Test: `tests/resume-one.php` (fails with the old source).
- **The removed setup's leftover option is deleted.** `sn_resume_ops_setup_missing`, which nothing reads since 2026.11.35, is removed on the next admin load (`inc/resume-variants.php`).
- **Not changed, on purpose:** the stored headline, tagline, summary, location, competencies and toolkit stay in the document. The résumé settings fall back to them only when a setting is blank, and all of them are set.

