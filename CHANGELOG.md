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
- Restored pure OpenStation App Framework native windows for S&N Dashboard and
  S&N Analytics, excising the opt-out preference mechanism and registry removal
  filter.
- Resolved nested double-scrollbars and CSS cascade conflicts across native
  dashboard and analytics bodies.
- Added OpenStation design token and dark-scheme styling for Analytics custom
  date inputs and export buttons, and polished container sizing and
  focus-visible rings in the Signal & Noise app.

## [13.106.2] - 2026-09-07 — native window polish

### Fixed
- OpenStation Preferences now includes per-user switches for the native S&N
  Dashboard and Analytics windows; disabling either restores its classic
  WordPress admin window after an immediate menu refresh.
- Native Dashboard now follows OpenStation's Station Home layout contract: its
  window chrome stays fixed, one bounded main surface scrolls, settings-section
  margins no longer double the page rhythm, and cards and detail panels reflow
  from the window's own width.
- Native Analytics now uses OpenStation's fixed list-toolbar and scrolling-body
  layout, replaces the range button wall with native bound controls, removes
  controls from Search that cannot affect Google's scheduled window, and
  eliminates the component-spacing collision that produced large empty bands.
- Search Console tables retain query/path labels and render clicks,
  impressions, CTR, and average position instead of blank labels with fabricated
  zero views. Content uses independent responsive columns so a tall table no
  longer strands the next reports below an empty half-screen.


