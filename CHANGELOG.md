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
- **The résumé PDF is two pages again.** With the earlier-career bullets back on /resume (owner, 2026-11-12) the PDF ran to three pages, and its own page check flagged it. The PDF now always shows earlier career as one line per role, whatever the page shows (`inc/resume-pdf/operations.php`); the editor's checkbox now says it governs /resume and that the PDF is always compact. Test: `tests/resume-ops-pdf-build.php`.

## [2026.11.31] - 2026-11-12 — Exact page views from the analytics worker's counter

### Added
- **Exact page views, from the analytics worker's own counter.** Analytics Engine samples a busy visitor-day when it writes it, so its figures are weighted estimates (on 2026-11-11 one visitor-day kept 7 page-view rows standing for 17). Analytics worker 2.2.0 counts every public page view in D1 and sends today and yesterday with each 15-minute refresh poke. The poke validates that block and holds it in memory. After the response, `inc/analytics-exact.php` and `inc/analytics-exact-reconcile.php` (both new) classify each visitor-day from Analytics Engine's own rows, with the same human rule and over-cap list every figure uses, in one read per day. They then store only per-day totals per class (`sn_analytics_exact_v1`). No visitor hash is stored or returned.
  - **A day is called exact only when nothing contradicts it.** It must be on or after the counter's first full day, with no read cut short and the over-cap list read whole. Every counted visitor-day must have exactly one class in Analytics Engine, and none may have fewer counted page views than Analytics Engine kept. Otherwise the reading says why, and the estimate stands.
  - **Where it shows, while the reading is under 45 minutes old:**
    - SN Traffic's Today so far
    - the Analytics header strip, human class
    - Overview Views today ("exact count")
    - `live-now` `today` with `today_exact: true`
    - /stats Views today (owner 2026-11-11), with the same label and the same public answer shape
  - Tests: `tests/analytics-exact.php` (new; the guards fail when the reconcile is forced exact) and `tests/analytics-refresh-rest.php`.

