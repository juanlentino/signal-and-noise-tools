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
- **A check that the new analytics datasets hold what the old one holds.** The analytics worker (1.24.0) writes every beacon to the legacy dataset and to two second-generation ones. `analytics-dual-write` counts rows per UTC day in all three and compares them: every legacy row except property rows against `sn_pageviews_v2` (which keeps one row per custom event since worker 1.25.0, so no visitor is lost from it), custom events and their properties against `sn_events_v2`, and how many new rows carry a pageview ID. A day before the first full day of the dual write reads `partial`, never `mismatch`; a failed read is reported as not read, never as a mismatch. Nothing else reads the new datasets yet: this is the test that has to pass before any read moves.

### Changed
- **SN Site Views is the overview again.** With Audience and Reading beside it, three things it carried were in two places. Visits and Engaged moved to SN Reading (Visits under its real name, visitor-days; Engaged keeps its arrow, its change in points and its color at five points or more); Top sources moved to SN Audience, where the named sources (Hacker News, LinkedIn, direct) replace the five source categories. Site Views keeps the headline, today, the sparkline, the north star, the top mover, the bot share and the top pages, and is about 120 px shorter.

### Fixed
- SN Audience listed "(none)" as a campaign: that is the rollup's bucket for a tagged link that named no campaign. It is dropped, and the group is hidden when nothing else is left.
- SN Reading called its second group Visits beside SN Site Views' Visits, which is a different unit (sessions against visitor-days). It is Sessions now.

## [21.2.0] - 2026-10-04 — analytics is four desktop widgets

### Added
- **Two desktop widgets, so analytics is a family of four: Site Views, Audience, Reading, RSS Subscribers.** Site Views was the tallest tile and covered only the overview. **SN Audience** is who reads and from where: top countries, devices, source categories, campaigns (only when a tagged link was followed), the latest Hacker News stories that link here with points, comments and front-page rank (not bound to the window, and labeled so), and Google and Bing clicks and impressions over their own windows. **SN Reading** is what readers do here: the share of views that reached half the page, the average depth reached and average time per view, visits with the one-page share, pages per visit and the typical visit, the top custom events, and each Core Web Vital at its 75th percentile (the figure Google assesses a page on) with its good and poor shares. Both read the last 14 days of rollups the Analytics views already fill; nothing new is collected. A reading that failed or has nothing says so and is never painted as zero. The four register together at the top of the widget picker; RSS Subscribers itself is unchanged.

### Fixed
- The Internet Archive run's status said "one every five minutes"; the server's cron wakes every five, so the real pace is five to ten. It now says so, on the status line, the button's hint and the started notice.

