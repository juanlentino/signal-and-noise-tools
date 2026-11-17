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

## [2026.11.48] - 2026-11-17 — Analytics Engine sampling check; Today so far says how many Sessions lists; direct to contact by measured time

### Added
- **A check that Analytics Engine stopped sampling.** The analytics summary (`get-analytics-summary` and its remote twin) gains `sampling_since_fix`: per event type of the page-view dataset, the rows written since analytics worker 2.3.0 went live (2026-11-17 15:53:12 UTC) and how many carry a sample interval above 1, for every class and for the counted-human class. `human_sampled` 0 means no human row was sampled since the fix; a failed read is null, never 0. One Analytics Engine read, cached an hour. Remote contract 34 to 35 (additive); the Worker's `CONTRACT_VERSION` and its fixture copy follow separately. Code: `inc/analytics-sampling-since.php`. Test: `tests/analytics-sampling-since.php`.

### Changed
- **"Today so far" says how many page views Sessions lists.** When today's figure is the exact count and Analytics Engine kept fewer page-view rows (it sampled them before the fix), the SN Traffic widget adds a smaller line, "Listed in Sessions (rest sampled)", with the kept count. Today's figure is unchanged.

### Fixed
- **"Direct to contact, nothing sent" now flags a visit that spent a moment on another page first.** A session counts when its second page is a contact page and its entry view was short. Short was measured as the gap between the two page views, which includes load and idle time, so the Niigata visit of 2026-11-16 (3.1 s measured on /, then /contact/personal) read false live. The entry page's measured time on page now decides when there is one; the gap is the fallback. Test: `tests/analytics-contact-direct.php` (2 checks, red without the fix).

