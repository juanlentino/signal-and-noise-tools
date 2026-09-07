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

## [13.106.1] - 2026-09-06 — native window polish

### Fixed
- Native Analytics focused tabs now open directly on their own reports instead
  of repeating Overview's insights, KPIs, and chart. The filter bar uses stable
  responsive rows, and custom date fields appear only when Custom is selected.
- Native Dashboard and Analytics windows use roomier cards and detail columns,
  readable supporting text, and proportional labels with monospace reserved for
  values, reducing the dense low-contrast wall visible in the first release.
