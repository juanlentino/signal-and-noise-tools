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
- **Text dimmed with opacity fell under AA; it is dimmed by the rust token now.** A sitewide computed contrast audit (every page template, light and dark) found two plugin surfaces. The note provenance panel faded its meta (the Bitcoin block link) and the "(mempool.space)" host to .6, measured 2.5:1 and 2.9:1; the caveat line used the same .65 fade. The roadmap legend faded whole cells (.55 to .62) and the sub line again (.55, at 10.9px, under the 11px floor), reaching 2.86:1 in dark. All of them take rust instead (5.7:1 light, 7.4:1 dark), the legend's speculative numerals turn rust (large text), the badge borders keep their solid/dashed/dotted fade, and the sub holds 11px. Same look, readable words. Guarded by `tests/front-end-text-not-faded.php`.

## [22.6.7] - 2026-10-06 — links on the colophon band are underlined

### Fixed
- **Links on the colophon's Kept honest band were invisible until hovered.** On the band a link takes the text's color, and the theme removes link underlines, so the maturity index, pair programmer, OpenStation, Daniel López Sánchez and the record's note title read as plain text. They are now underlined at rest in the signal red, thicker on hover and focus.

