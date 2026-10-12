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
- **S&N links follow your accent pick in OS Settings › Appearance.** The desktop cards' "Open Analytics →", "Open Provenance →", "Open Health →", "Machine readers in S&N Analytics →" and the rest, and every link in the S&N Home and S&N Analytics windows, were OpenStation's fixed Nebula pink (#ec9bff), a color the accent picker never changes. They now take the picked accent, lifted toward white only as far as AA for link text needs (4.5:1 on every dark window and card surface): your red reads as #ff6767 (6.2:1 in a window, 6.7:1 on a card); Indigo, Emerald and WordPress Blue are lightened the same way; Sirius, Nebula and other light picks are used exactly as picked. On the light Legacy desktop theme the pick is darkened instead. S&N Analytics links, which painted the raw accent (4.4:1 for red, under AA), use the same color. Figure changed: link color, pink #ec9bff to the accent-derived color.

## [2026.10.6] - 2026-10-12 — Credentials tables paint on a cold open, a shorter Colophon credit, and wallpaper thumbnails that show the signal

### Changed
- **Colophon › Interop is one short sentence.** It now reads "the site's admin runs inside OpenStation, a WordPress plugin that turns it into a desktop; Daniel López Sánchez, one of its maintainers, contributed to the integration." Removed: "free", "with windows and a dock", "which readers of the public site never see", and the repeated "OpenStation". Both links and the credit stay. The theme's humans.txt follows, since it reads the same facts. Owner's yes, 2026-10-12.

### Fixed
- **Connections › Credentials lists its rows on a cold first open (post-install, 2026.10.5).** All three tables (Site secret and the workers, Cloudflare, Issued by others) could read "Nothing to show." while the classic page listed every credential. No credential was lost: the rows reached the window, but OpenStation hands a table its rows before it has loaded the table component, and on a cold open the rows were parked where the table never looks. After every paint, S&N now hands any such value to the component again once it has loaded. This covers every table and picker in S&N Home and S&N Analytics, not only Credentials.
- **The desktop theme's wallpapers read in the picker (post-install, 2026.10.5).** At tile size the Signal and Quiet thumbnails were nearly black and Signal's red line did not show: every stroke was drawn for a 1920-wide screen and shrank to a fifth of a pixel. Every stroke now keeps its width on screen, so a tile shows the gray noise and the red line with its pulse. Full size looks as before on a 1920-wide screen (on a larger screen the lines no longer thicken).

