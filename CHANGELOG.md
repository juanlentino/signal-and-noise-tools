# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.6] - 2026-10-12 — Credentials tables paint on a cold open, a shorter Colophon credit, and wallpaper thumbnails that show the signal

### Changed
- **Colophon › Interop is one short sentence.** It now reads "the site's admin runs inside OpenStation, a WordPress plugin that turns it into a desktop; Daniel López Sánchez, one of its maintainers, contributed to the integration." Removed: "free", "with windows and a dock", "which readers of the public site never see", and the repeated "OpenStation". Both links and the credit stay. The theme's humans.txt follows, since it reads the same facts. Owner's yes, 2026-10-12.

### Fixed
- **Connections › Credentials lists its rows on a cold first open (post-install, 2026.10.5).** All three tables (Site secret and the workers, Cloudflare, Issued by others) could read "Nothing to show." while the classic page listed every credential. No credential was lost: the rows reached the window, but OpenStation hands a table its rows before it has loaded the table component, and on a cold open the rows were parked where the table never looks. After every paint, S&N now hands any such value to the component again once it has loaded. This covers every table and picker in S&N Home and S&N Analytics, not only Credentials.
- **The desktop theme's wallpapers read in the picker (post-install, 2026.10.5).** At tile size the Signal and Quiet thumbnails were nearly black and Signal's red line did not show: every stroke was drawn for a 1920-wide screen and shrank to a fifth of a pixel. Every stroke now keeps its width on screen, so a tile shows the gray noise and the red line with its pulse. Full size looks as before on a 1920-wide screen (on a larger screen the lines no longer thicken).

