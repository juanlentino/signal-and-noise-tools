# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The SN Queue card shows the coming six weeks as a calendar (owner's design, 2026-10-12).** A grid from the Monday of this week, Monday first, in the site's timezone: every scheduled note is a dot inside its day, under the number (all of them, not only the next four), today has a solid outline and an underlined number, the next-up day is tinted, empty days are dimmed, and the 1st of a month shows the month over its number and dot. Days inside a gap you marked Planned get a quiet dashed outline, explained in a line under the grid. Tap a day, or move to it with the arrow keys (Home and End go to the week's ends), and a box under the grid names that day's notes with title, time and a link to the editor; it opens on the next-up day, so "Next up" is said there now instead of in a separate headline. Notes past the six weeks are counted ("+14 more through Jan 25, 2027"). The drafts are now listed by title too (the newest three, each linking to its editor, with "+N more" when there are others). Still shown: the scheduled count and the date the queue runs to, the draft count, the gap line with its Planned button, Just published and Open Scheduled. Removed: the "Then" list (its notes are dots on the grid and named when you pick their day; one past the six weeks is listed under "Later") and the separate "Next up" headline (its title and time are in the box). Why: the owner asked for a calendar for the notes to come and a list for the ones gone. The card is taller: its default height goes from 380 to 600, measured live at the default 312 width (the old card already needed 418 there and scrolled); a card you resized keeps its size, so on your desktop it scrolls until you drag it taller once. Pinned by tests/desktop-status-resilience.cjs (every note in the window is a dot across six weeks including a 1st of the month; the 1st shows tag, number and dot; today is marked on exactly one cell; drafts by title; each day's accessible name carries the date and titles; arrow keys and one tab stop; seven columns that cannot overflow at the narrowest card), tests/abilities-content-queue.php (the reading carries every scheduled note with its site-timezone day and time, today, the week's Monday on a Sunday too, planned gaps and drafts) and tests/desktop-mode-integration.php (the 600 height).

### Fixed
- **S&N links follow your accent pick in OS Settings › Appearance.** The desktop cards' "Open Analytics →", "Open Provenance →", "Open Health →", "Machine readers in S&N Analytics →" and the rest, and every link in the S&N Home and S&N Analytics windows, were OpenStation's fixed Nebula pink (#ec9bff), a color the accent picker never changes. They now take the picked accent, lifted toward white only as far as AA for link text needs (4.5:1 on every dark window and card surface): your red reads as #ff6767 (6.2:1 in a window, 6.7:1 on a card); Indigo, Emerald and WordPress Blue are lightened the same way; Sirius, Nebula and other light picks are used exactly as picked. In the light windows of the Legacy desktop theme the pick is darkened instead; its desktop cards stay dark and keep the lift. S&N Analytics links, which painted the raw accent (4.4:1 for red, under AA), use the same color. Figure changed: link color, pink #ec9bff to the accent-derived color.

## [2026.10.6] - 2026-10-12 — Credentials tables paint on a cold open, a shorter Colophon credit, and wallpaper thumbnails that show the signal

### Changed
- **Colophon › Interop is one short sentence.** It now reads "the site's admin runs inside OpenStation, a WordPress plugin that turns it into a desktop; Daniel López Sánchez, one of its maintainers, contributed to the integration." Removed: "free", "with windows and a dock", "which readers of the public site never see", and the repeated "OpenStation". Both links and the credit stay. The theme's humans.txt follows, since it reads the same facts. Owner's yes, 2026-10-12.

### Fixed
- **Connections › Credentials lists its rows on a cold first open (post-install, 2026.10.5).** All three tables (Site secret and the workers, Cloudflare, Issued by others) could read "Nothing to show." while the classic page listed every credential. No credential was lost: the rows reached the window, but OpenStation hands a table its rows before it has loaded the table component, and on a cold open the rows were parked where the table never looks. After every paint, S&N now hands any such value to the component again once it has loaded. This covers every table and picker in S&N Home and S&N Analytics, not only Credentials.
- **The desktop theme's wallpapers read in the picker (post-install, 2026.10.5).** At tile size the Signal and Quiet thumbnails were nearly black and Signal's red line did not show: every stroke was drawn for a 1920-wide screen and shrank to a fifth of a pixel. Every stroke now keeps its width on screen, so a tile shows the gray noise and the red line with its pulse. Full size looks as before on a 1920-wide screen (on a larger screen the lines no longer thicken).

