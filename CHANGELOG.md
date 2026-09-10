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
- **The Signal & Noise view switch survives a reload.** Icons or list is a preference, and it was being reset on every page load. `state.view` is declared Local in the app's schema beside `query`, `status`, `item` and `selected` — those are right to be ephemeral, but a view choice is different in kind. Measured live 2026-09-10: set to List it survived navigating to the root and back, then reset to icons on **every reload**, dropping the reader into a 104px tile grid that clipped **63% of Notes titles and 57% of Attention's** — those items are sentences, not names. The choice is now stored per viewer per browser, the way the plugin already keeps `sn-theme`; the schema's `icons` is the fallback and now says so. Both list and icons remain available — Discography reads well as tiles, where items are names and clipping is 20%.


## [13.109.14] - 2026-09-10 — the odd panel spans the row

### Fixed
- **Four S&N Analytics views stranded a panel in the left half.** The app's grids are a **fixed two tracks**, so a view feeding one an odd number of panels left the last alone with the right half empty. Measured live 2026-09-10 at a 1,557px window, the last child's right edge landing at **49%**: Technology (5 panels — TLS versions stranded), Engagement (3 — Connection RTT), Posts (3), Traffic & edge (1 — Status codes). Same empty-right-half symptom as the Home app's 820px width cap, a different cause: not a cap, an odd count against an even track count. An odd last child now spans both tracks — a deliberate full-width band rather than an accidental half, which is what `security/audit-log` already does with its counter table. All four measured at **100%** afterwards.
- **An empty grid no longer occupies a row.** Traffic & edge and Campaigns each painted a grid with no children in their no-data state.

