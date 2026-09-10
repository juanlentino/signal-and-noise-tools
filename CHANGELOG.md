# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.15] - 2026-09-10 — the view switch remembers

### Fixed
- **The Signal & Noise view switch survives a reload.** Icons or list is a preference, and it was being reset on every page load. `state.view` is declared Local in the app's schema beside `query`, `status`, `item` and `selected` — those are right to be ephemeral, but a view choice is different in kind. Measured live 2026-09-10: set to List it survived navigating to the root and back, then reset to icons on **every reload**, dropping the reader into a 104px tile grid that clipped **63% of Notes titles and 57% of Attention's** — those items are sentences, not names. The choice is now stored per viewer per browser, the way the plugin already keeps `sn-theme`; the schema's `icons` is the fallback and now says so. Both list and icons remain available — Discography reads well as tiles, where items are names and clipping is 20%.

