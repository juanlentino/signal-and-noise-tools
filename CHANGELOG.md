# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.3.3] - 2026-10-09 — share bars on an even accent ramp, another site's monitor is not this site's uptime

### Fixed
- **The share bars use an even ramp of the accent, so their pieces read apart.** Faded by opacity, the steps were under 0.1 apart in OKLab and the last two sat under 1.5:1 on the card: one muddy red. Each step now keeps the accent's hue (oklch relative color) at an even lightness, about 0.1 apart, with chroma easing off as it lightens: deep red, rose, pink, blush, each at roughly 3:1 or more on the card. The biggest share stays the deepest; segments keep a 2px card-colored gap. A browser without relative color draws the plain accent.
- **Another site on the Better Stack account no longer counts as this site's uptime.** The account also watches Panacea Studio; its monitor counted in SN Systems' "N of N up", Slowest and verdict, the morning brief and the uptime abilities. A monitor (and its incidents) is kept only when its URL is this site's host or a subdomain of it, so a site added to the account later is left out with no list to keep. Better Stack itself still watches it.

