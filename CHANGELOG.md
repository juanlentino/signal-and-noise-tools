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
- **Every row of the Signal & Noise list carried an invisible menu button.** The row's "More actions" control renders a `.dashicons` glyph slotted into `os-button` — and measured live 2026-09-10 on the shipped build, that glyph computed to `font-family: Geist` instead of `dashicons` and rendered **0×0**, leaving a 26×14px ghost button with nothing in it on all 40 rows. A control you cannot see is worse than a decorative glitch. The same raw span works inside `os-segment` (the view toggle uses one and paints at 16px); `os-button` slots its content where the `.dashicons` rule cannot reach. `os-icon` puts the glyph in its own shadow root instead — verified in place on the same button before the change: raw span 0px, `os-icon` 16×16px.


## [13.109.15] - 2026-09-10 — the view switch remembers

### Fixed
- **The Signal & Noise view switch survives a reload.** Icons or list is a preference, and it was being reset on every page load. `state.view` is declared Local in the app's schema beside `query`, `status`, `item` and `selected` — those are right to be ephemeral, but a view choice is different in kind. Measured live 2026-09-10: set to List it survived navigating to the root and back, then reset to icons on **every reload**, dropping the reader into a 104px tile grid that clipped **63% of Notes titles and 57% of Attention's** — those items are sentences, not names. The choice is now stored per viewer per browser, the way the plugin already keeps `sn-theme`; the schema's `icons` is the fallback and now says so. Both list and icons remain available — Discography reads well as tiles, where items are names and clipping is 20%.

