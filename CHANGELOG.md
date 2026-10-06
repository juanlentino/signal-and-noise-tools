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
- **The Maturity pages dim with rust, not opacity.** Every system sheet faded its second table column, its list dashes and its planned scope badges with `opacity`, the hub faded its card notes and unlinked cards, and the roadmap faded whole board cells. All now use the `rust` token: planned board cells read at full ink, considering and later in rust. `tests/front-end-text-not-faded.php` now refuses any `opacity` declaration in the maturity stylesheets.

### Changed
- **README:** a Public pages section for the colophon and Maturity spec sheets; the contrast report's live tier under Content health; the health module count corrected to 28.

## [22.7.0] - 2026-10-06 — maturity pages on the page track, contrast report shows the live pages

### Changed
- **The maturity pages are on the page track, as a spec sheet.** The hub and the seven system pages (Accessibility, AI, Operations, Machine learning, Machine readability, Proof of origin, Analytics) leave the 760px column for the shared 1320px track: the hub's cards go four across (1,274px to 948px tall at 1440), and on each system page every heading after the opening table becomes a band, the heading on the left and its list or block on the right, with the principles and give-back lists two across (Machine readability: 3,097px to 2,335px). One sheet, `assets/maturity-layout-front.css`, loaded in the head on a page carrying a maturity shortcode (`inc/maturity-layout.php`); each family's own sheet keeps its look. The roadmap keeps its own wide layout. Words unchanged.

### Added
- **The contrast report says what the live pages show.** Above its two stylesheet counts (token pairs that would fail if rendered together; placement-dependent pairings), the report on both the classic Health tab and the dashboard now leads with the latest run of the theme's live contrast check (`contrast.yml`): every text pair at AA and every color-only link at 3:1 across the site, light and dark, or the failure count linked to the run that names each. Read from the public GitHub API, cached six hours; an unreachable API reads as unknown, never as a pass (`inc/health-contrast-rendered.php`).

### Fixed
- **/verify honors a reduced-motion setting everywhere on the page.** Its own reset listed the check states and tally only, so the form button, the tabs and the compare button still faded for a visitor who asked for less motion (5 of the motion scan's 44 uncovered declarations). /verify is a standalone document without the theme's sheets, so `assets/css/prov-verify.css` now carries a complete reset of its own; the theme's new global reset covers the rest of the site.

