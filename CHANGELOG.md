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
- Restore blank native Analytics reports by carrying the window body height through the shell’s tab stack and panel wrappers. Keep hidden panels hidden and limit the height rule to Analytics mounts; retain container-aware scrolling.

### Added
- Mobile shell geometry regression with actual mobile CSS and native tab wrappers, browser/standalone markers and mobile–desktop–mobile transitions. Strengthen desktop coverage with native Analytics wrappers, hidden-panel assertions, and Analytics CSS loaded alongside Home/other leaves.

## [13.107.1] - 2026-09-08 — App-window scrolling and native facts URL wrapping

### Fixed
- Base Analytics scrolling on the app container's height as well as its width, so short desktop windows remain usable inside tall browser viewports while wide, tall windows retain fixed controls.
- Allow long inline code values and URLs in native facts lists, including Home's IndexNow key URL, to wrap without changing link targets or copyable text.

### Added
- Shell-CSS geometry regression coverage with independently varied browser and app dimensions, a complete Campaigns route with fixture readers, and actual IndexNow/Performance painters. Assertions check clipping ancestors, report and final table-row reachability, horizontal overflow, and URL preservation; this is not installed-PWA or full-shell interaction verification.

