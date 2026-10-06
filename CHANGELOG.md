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
- **The contrast report says what the live pages show.** Above its two stylesheet counts (token pairs that would fail if rendered together; placement-dependent pairings), the report on both the classic Health tab and the dashboard now leads with the latest run of the theme's live contrast check (`contrast.yml`): every text pair at AA and every color-only link at 3:1 across the site, light and dark, or the failure count linked to the run that names each. Read from the public GitHub API, cached six hours; an unreachable API reads as unknown, never as a pass (`inc/health-contrast-rendered.php`).

### Fixed
- **/verify honors a reduced-motion setting everywhere on the page.** Its own reset listed the check states and tally only, so the form button, the tabs and the compare button still faded for a visitor who asked for less motion (5 of the motion scan's 44 uncovered declarations). /verify is a standalone document without the theme's sheets, so `assets/css/prov-verify.css` now carries a complete reset of its own; the theme's new global reset covers the rest of the site.

## [22.6.8] - 2026-10-06 — text is dimmed by the rust token, not opacity

### Fixed
- **Text dimmed with opacity fell under AA; it is dimmed by the rust token now.** A sitewide computed contrast audit (every page template, light and dark) found two plugin surfaces. The note provenance panel faded its meta (the Bitcoin block link) and the "(mempool.space)" host to .6, measured 2.5:1 and 2.9:1; the caveat line used the same .65 fade. The roadmap legend faded whole cells (.55 to .62) and the sub line again (.55, at 10.9px, under the 11px floor), reaching 2.86:1 in dark. All of them take rust instead (5.7:1 light, 7.4:1 dark), the legend's speculative numerals turn rust (large text), the badge borders keep their solid/dashed/dotted fade, and the sub holds 11px. Same look, readable words. Guarded by `tests/front-end-text-not-faded.php`.

