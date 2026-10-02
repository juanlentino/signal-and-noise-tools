# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **/resume's closing line sits in the page column.** 20.5.0 appended it after the last band, so it rendered full-bleed with its first letter clipped at the window edge. It is now the last block inside the Skills band, sharing the page's 1320px column and spacing. Press Generate after installing to rewrite the page. Pinned in `tests/resume-sync-engine.php` (the line is inside the last band; red against 20.5.0).

## [20.5.0] - 2026-10-02 — the resume hands you onward

### Added
- **/resume ends with a next step.** The page ended on the skills list, a dead end for two of the site's three audiences. It now closes with "Beyond the record: the research · the music" (owner-approved copy, 2026-10-02), linking /provenance and /music in the theme's `.sn-page-next` style (theme 14.8.0, which ends /music the same way and reorders the header). Each link is a beacon goal (`next_research`, `next_music`), so `sn-metrics{analytics_events}` counts which handoff readers take. The line lands the next time the resume page syncs (press Generate, or any resume save). Pinned in `tests/resume-sync-engine.php` (the body ends with the line; red without it).

