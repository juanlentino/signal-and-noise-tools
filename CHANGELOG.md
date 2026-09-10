# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.16] - 2026-09-10 — the row menu button becomes visible

### Fixed
- **Every row of the Signal & Noise list carried an invisible menu button.** The row's "More actions" control renders a `.dashicons` glyph slotted into `os-button` — and measured live 2026-09-10 on the shipped build, that glyph computed to `font-family: Geist` instead of `dashicons` and rendered **0×0**, leaving a 26×14px ghost button with nothing in it on all 40 rows. A control you cannot see is worse than a decorative glitch. The same raw span works inside `os-segment` (the view toggle uses one and paints at 16px); `os-button` slots its content where the `.dashicons` rule cannot reach. `os-icon` puts the glyph in its own shadow root instead — verified in place on the same button before the change: raw span 0px, `os-icon` 16×16px.

