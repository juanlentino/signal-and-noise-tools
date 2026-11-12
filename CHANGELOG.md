# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.30] - 2026-11-12 — One résumé on /resume

### Changed
- **/resume is one résumé (owner, 2026-11-12).** The music / operations switch is gone: readers had to notice it to use it, and two résumés were two things to keep straight. Once the operations settings exist, the page is one body built from the operations view (its headline, subhead, location, summary, experience order, sections and "What I've Built"), with no switch, no hidden second lane, no "only in this version" marks and no hunks (`inc/resume-page-lanes.php`, `inc/resume-page-bands.php`, the old `inc/resume-lane-marks.php`). Rows the owner tags "Both résumés" show on it; "Music only" rows stay on the music PDF and /cv. The stored document is untouched, so this can be undone. The page re-renders once on the next admin load (`SN_RESUME_ENGINE_REV` 7).
  - **New: a kicker** (`variants.operations.kicker`, "Kicker" in Content › Resume Page › Operations résumé, classic and native): a small line straight over the headline.
  - **New: "Verified credits: juanlentino.com/music"** in the hero rail, the PDF's contact line, and the JSON Resume's `basics.profiles` (`network: "Verified credits"`), shown only while /music is published (`sn_resume_credits_url()`).
  - **`?lane=` means nothing anywhere.** Every address, `/r/CODE` included, shows the same page; the canonical and `og:url` are `/resume/` (the operations-lane canonical, title and Markdown alternate in `inc/resume-lane-address.php` are gone, and the render filter `inc/resume-page-lane-render.php` with them). `/resume.md`, the JSON Resume and the vCard are the one résumé, with no `lane:`, `lanes:`, `meta.x-lane` or `meta.x-lanes`, and the format list has no `*_operations` entries.
  - **One PDF**: the operations build, printing `juanlentino.com/resume` and the credits line, Info Title "Juan Lentino · Résumé". The music PDF is still built but linked nowhere on /resume.
  - **The description** of /resume (meta, og, twitter, ProfilePage) is the first sentence of the résumé's summary, on every address (`inc/resume-page-description.php`).
  - **Cache**: leaving engine rev 6 purges the old lane addresses once (`SN_CF_RESUME_LANE_URLS_RETIRED`); they are off the per-save purge list.
  - **Analytics**: the owner-only "Résumé lanes" panel keeps its history and says that from 2026-11-12 the lane field means nothing (`SN_RESUME_LANES_ENDED`).
  - The /services/consulting seed's hire line now reads "Start with the résumé", to `/resume/` (the live page is the owner's edit).
  - Unchanged: the Spanish /cv, a document with no operations settings, the vCard never linked from the operations résumé (owner, 2026-11-02), and no phone anywhere.
  - Tests: `tests/resume-one.php` and `tests/resume-head.php` (new; both fail against the two-lane code), replacing `tests/resume-page-lanes.php`, `tests/resume-lane-marks.php`, `tests/resume-lane-address.php` and `tests/resume-lane-heads.php`; the PDF, purge, regen, no-phone and lanes-panel suites updated.

