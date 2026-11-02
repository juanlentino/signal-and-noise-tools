# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **A résumé draft the database did not store said "Draft saved".** `sn_resume_draft_save()` ignored `update_option()`'s answer. It now compares first (the same draft saved twice in a day is already stored, and `update_option()` answers false for an unchanged value too), then reports the write. Save draft and Set up the operations résumé flash a new error, "Draft not saved: the database did not take the write", instead of success or the refusal message, which names a content rule the owner did not break. `tests/resume-draft.php` (+6).
- **The CI tests job's timing comment said 40s.** The job now takes up to 82s over the last 8 successful runs; the comment says so. `timeout-minutes: 4` stays, about three times the measured max.
- **docs/MACHINE-READERS.md named only the uploads PDF for the `resume` surface.** The operations PDF served at `/resume/<file>.pdf` counts as `resume`, format `pdf`, once the Worker counts it (sn-rights-signals-worker#117); until then those reads land in `html`. The surface table names both.

## [2026.11.8] - 2026-11-02 — The résumé's Markdown follows the lane: /resume.md?lane=operations

### Changed
- **The résumé's Markdown follows the lane.** `/resume.md?lane=operations` (and `?output_format=markdown` on the operations lane) is the operations résumé: its headline and summary, What I've Built, its experience and sections, and no phone. On `/resume/?lane=operations` the page's `Link` header and head alternate name it, so an agent asking that address for Markdown gets that lane. The rights-signals Worker 1.37.2 reads the lane query; until then it converts the visible lane, as of 1.37.1. A section with nothing in it is left out of the Markdown. The music Markdown is unchanged byte for byte, pinned to main's output. `tests/resume-page-lanes.php` (+4).

