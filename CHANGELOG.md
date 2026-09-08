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
- Home: rail stacks to column at ≤820px; action buttons become individually scrollable with full labels and 44px touch targets; intro/heading/pulse/section rules tighten at ≤640px.
- Analytics: container-scoped queries keep filter flex-bases on the inline axis at narrow widths; report grid/controls wrap to a scrollable container so custom-date forms no longer consume the full canvas; date inputs and export targets reach 44px; metric/source tables stack to single-column.
- Both: minimum app size reduced from 760–800px to 360×360 so normal desktop resize can reach phone/tablet layouts; Analytics gains a visible body-level Refresh button (the shell hides titlebar actions on mobile); Home refresh inner button accessible name fixed via slotted content; Analytics Posts/Search/Cron leaves receive leaf-local Refresh.
- Browser regression harness at `tests/js/mobile-apps.mjs` covers 46 app/size cases with 800+ assertions.

### Fixed
- Analytics toolbar flex-direction column was turning 190px / 170px select widths into heights, crowding the report out of the window.
- Custom-date `os-form` host wrapping flex collapsed date inputs at wide sizes.
- Home `--os-ui-button-min-height` variable only styled the shell's `fill-cell` variant; inner buttons now set 44px via `::part(button)`.

## [13.106.22] - 2026-09-08 — Report signed WordPress authentication outcomes to Login Guard

### Added
- Send request-bound, HMAC-signed authentication outcomes to the existing Login Guard Worker, using a dedicated shared secret and the verified audit/MFA hooks. Show verified observations and missing/invalid coverage in classic and native Login defense analytics without changing enforcement or counting outcomes as extra edge requests.

