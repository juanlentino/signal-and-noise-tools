# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **A stale-CV alert.** /cv/ is a translation and does not follow the English résumé on its own. When the English résumé changes, the Résumé screen (classic and dashboard) and a new Health check (`resume_es_stale`, Other checks family) say "The Spanish CV (/cv/) is out of date" and list the English lines added, changed or removed since the translation. The baseline is captured from the live English résumé on the first run. After that it moves only when the Spanish file's `translated_from` names the live English (the "English version" code the alert shows), so a typo fix or a partial Spanish update cannot clear an alert whose lines are still untranslated. No AI, no network. The scan now runs one more check. Pinned in `tests/resume-es-stale.php`.

### Fixed
- **The /stats Automated tile now counts over-cap visitor-days, as the dashboards do.** A visitor-day over the page-view cap (more than 50 views) counts as bot everywhere the dashboards read Analytics Engine directly, but both daily rollup queries dropped it from every row, bot included, so the tile (suspect plus bot from the daily table) undercounted. The rollup now files those views under the bot row (`sn_analytics_counted_class_select()`, whose bot branch is the readers' own condition). Human and suspect rows do not move: over-cap views were never in them. Bot rows, and so the Automated tile, rise on the trailing 7 days at the next nightly roll; older days keep the old bot count until they are re-rolled. Size, not measured exactly: the 14-day bot-signal readout saw 5 over-cap visitor-days that carried beacon signals, each over 50 views, so a floor. The other group-by builders (breakdowns, buckets, UTM, realtime) still drop over-cap rows from their bot rows and are unchanged here. Pinned red-first in `tests/analytics-rollup.php` (daily-table bot equals the reader's bot for a fixture day with an over-cap visitor) and `tests/analytics-human-rule.php`.

## [2026.10.17] - 2026-10-15 — The Spanish résumé (/cv/ and its PDF), and a compact Release notes box

### Added
- **/cv/ and `JuanLentino_CV.pdf`, the Spanish résumé** (owner, 2026-10-15: a PDF too, and no second résumé system in the settings). One Spanish file, `inc/seed-content/resume-data-es.json`, renders both through the same page builder and PDF template as /resume, with their fixed headings in Spanish (`inc/resume-lang.php`); English output is byte for byte unchanged. Contact details come from the live English résumé, so the phone never reaches the repo. /cv/ re-renders on the next admin load after the file or the contact details change, and its PDF re-renders every time the English PDF is generated. /cv/ is a fifth Spanish page paired to /resume. The Spanish pages seed now records each slug it handled, so a slug added later is seeded on the next admin load. A lock (`sn_spanish_pages_seed_lock`, taken over after ten minutes) replaces the run-once flag. Pinned in `tests/resume-es.php` and `tests/bilingual.php`.

### Changed
- **The Release notes box is compact** (Connections › Discography). Each saved note is now one line: the release name, then the English and Spanish fields side by side. "Add a note" is folded into one line until opened. It was a fieldset of three stacked labeled fields per note, and the owner flagged how much of the page it took (2026-10-15). Saving is unchanged.

