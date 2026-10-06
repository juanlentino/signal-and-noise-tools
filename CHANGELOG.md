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
- **Links on the colophon's Kept honest band were invisible until hovered.** On the band a link takes the text's color, and the theme removes link underlines, so the maturity index, pair programmer, OpenStation, Daniel López Sánchez and the record's note title read as plain text. They are now underlined at rest in the signal red, thicker on hover and focus.

## [22.6.6] - 2026-10-06 — the colophon is a spec sheet

### Changed
- **The colophon is a spec sheet, not a scroll.** The page takes the shared 1320px page track (title and rule with it, as on Resume and the stats page), and each group is a band: its heading on the left, its rows in a grid on the right (Made with two by two, On the page side by side, Kept honest with Records across and Systems, AI and Interop three across). The theme and plugin versions moved from the bottom to the header, beside the opening, so they are seen without scrolling. The Records row shows one real record: the newest published note whose newest version is signed and confirmed in a Bitcoin block, with its version and date, shortened fingerprint, signature and block, and a Verify a Note button. The Type row shows the heading face and the Appearance row the palette, both decorative and hidden from screen readers. Kept honest is inverted in both palettes. The rows' words are unchanged. New `assets/colophon-front.css`, loaded in the head only on a page carrying the shortcode, and `inc/colophon-front.php`.

