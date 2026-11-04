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
- **/cv/: the Panacea Studio founder line reworded in the owner's words.** The move in 2021, the three partners brought in to run the studio, and the non-operating founder role, keeping ownership and strategic direction. Replaces the earlier line that said the same thing; the Spanish PDF regenerates with it. `inc/seed-content/resume-data-es.json`.

## [2026.11.10] - 2026-11-02 — Résumé lane report for the owner; résumé on the maturity index

### Added
- **The machine-readability page names the résumé.** A `Résumé versions` coverage chip, live, joins the twelve on `[sn_machine_maturity]`, and the roadmap board's code floor gains a fifth Machine readability done row (now at the five-row ceiling): "The résumé served as two versions at two addresses, each under its own title, with machine copies (Markdown and JSON Resume) that name both versions; each version marks what only it holds, and no version, human or machine, carries a phone number". Code default only; the three-way merge carries the new cell to the live board unless the override moved that cell. Floor md5 pin re-pinned. `inc/machine-maturity-page.php`, `inc/maturity-roadmap-shortcode-board-parts.php`. Tests: `tests/maturity-family.php`, `tests/maturity-roadmap-shortcode.php`.
- **A Résumé lanes panel in the analytics Links view, owner only.** Per /resume lane, music and operations, for the chosen window: views, average time and scroll per view (the rows read's `resume_lane`, Analytics Engine, human class), clicks on the switch link to that lane (`resume_lane_<lane>`) and on that lane's edition mark (`resume_hunk_<lane>`). The beacon already sends any `data-sn-goal` click as a custom event and the worker keeps any name, so the four goals needed no registry; the panel reads them from the stored events. Lanes only: no link code or per-code number. Classic and native twin (`paint_resume_lanes()`), both gated on `manage_options`; a refused lane read (older than about 90 days) says why. `inc/analytics-resume-lanes.php` (new), `inc/analytics-view-links.php`, `apps/sn-analytics/parts/painters/view-links.php`. Tests: `tests/analytics-resume-lanes.php` (new, 18), `tests/analytics-rows-resume-lane.php` (the owner-only file list adds the panel).

