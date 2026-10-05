# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.0.0] - 2026-10-05 — /stats reads as a report

### Added
- **/stats is redesigned, as the owner approved.** Views, Visits and Automated sit in one row of tiles that sizes itself to the tiles shown, so a tile left out leaves no gap. Reading rhythm keeps its daily bars, now with the busiest day in red, its sentence, and the week-by-day calendar, which is now always visible with the busiest day marked. Two new columns follow: "Where readers come from" (top sources and top countries as label, bar and share) and "How they read" (views that reached half the page, average depth reached, average time per view, one page only, read from the same figures as the SN Reading card). "Humans and machines" sets human views against the machine reads the edge sensor saw, with who the machines are (verified by Cloudflare, named themselves and not verified, not measured), one line on why Automated and machine reads differ, and a link to the public ledger. Any source or country with fewer than 3 visits is grouped as Other. A figure that cannot be read leaves its row or section out, never a zero. Dimmed text uses the theme's color tokens instead of opacity, so it holds AA contrast in light and dark. The page stays read-only over the stored analytics; its only write is its own hour-long cache (key v4).

### Fixed
- **/stats: Visits counts site-wide sessions.** The tile first read the rollup's plain visits, which also count feed- and beacon-only reader-days (520 visits against 337 views over 30 days), and then reader-days with a pageview, which are summed per page, so a reader who opened two pages counted twice. It now sums the session rollup the SN Reading card reads, under the owner-approved line "times a reader came to the site; reading several pages in one sitting counts once". A failed or empty session read leaves the tile out rather than showing 0.
- **/stats reads only what it can stand behind.** The page never calls the edge sensor while rendering: an hourly event stores the machine reads for the same 30 days as the human figures, and a snapshot of another window leaves the section out. Visits and One page only show only when every day of the window rolled up. When a source or country read fills its 500-row limit, the rows it dropped join Other and shares are of every view; a shorter read keeps its shares of the rows read.

