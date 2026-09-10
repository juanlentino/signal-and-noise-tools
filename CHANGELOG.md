# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.19] - 2026-09-10 — the tap targets clear the floor

### Fixed
- **Twelve tap targets sat under the WCAG 2.2 floor.** SC 2.5.8 puts a 24×24 CSS-pixel minimum under a pointer target. Measured in OpenStation's phone layer on the running product: 118 controls, 35 under the floor — of which 5 are legitimately exempt (links inside a sentence, where the spec's inline exception applies) and 30 are genuine. Twenty of those thirty are OpenStation's own widget-frame Dock/Remove buttons at 20×20 and are not ours. The remaining ten were, plus two more the live probe could not see because their state was not rendered.
- The doorway links at the foot of every widget — *Open Analytics →*, *Open Health →*, *Open Uptime →*, *Open RSS tab →*, *Open Dashboard →*, *Open Machine Readers →*, *Cron events →*, *Run a scan →* — rendered 17–18px tall with `padding: 0`, the natural line box of 11px type and nothing else. They are built in JS with an inline `style:` string, one per widget file with **no shared helper**, which is the exact shape a convention drifts out of. All ten now declare `min-height: 24px`.
- `.snt-home__view-all` and `os-button.snt-sys__v` in S&N Home carried the same 18px line box from the stylesheet side. `.snt-sys__v` is **not** pinned as a whole: it also labels static values, and a floor applied to every value would pad text nobody can click.
- The same eight doorways measure 18px at 1920px wide, so this was never a mobile-only defect — the phone is only where it bites.

### Added
- `tests/openstation-widget-tap-target.php` pins the floor per link rather than per file, reading each `el( 'a', … )` object literal whole because `style:` and `text:` appear in either order across these files. Its first regex matched only `Open …` doorways and walked straight past *Cron events →* and *Run a scan →*; the convention is a label ending in an arrow, and the pin now says so. Negative controls: it must detect the exact declaration that shipped, must accept a compliant one, must ignore a non-doorway link, and must leave `.snt-sys__meta` alone.

