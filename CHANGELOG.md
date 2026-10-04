# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [21.2.0] - 2026-10-04 — analytics is four desktop widgets

### Added
- **Two desktop widgets, so analytics is a family of four: Site Views, Audience, Reading, RSS Subscribers.** Site Views was the tallest tile and covered only the overview. **SN Audience** is who reads and from where: top countries, devices, source categories, campaigns (only when a tagged link was followed), the latest Hacker News stories that link here with points, comments and front-page rank (not bound to the window, and labeled so), and Google and Bing clicks and impressions over their own windows. **SN Reading** is what readers do here: the share of views that reached half the page, the average depth reached and average time per view, visits with the one-page share, pages per visit and the typical visit, the top custom events, and each Core Web Vital at its 75th percentile (the figure Google assesses a page on) with its good and poor shares. Both read the last 14 days of rollups the Analytics views already fill; nothing new is collected. A reading that failed or has nothing says so and is never painted as zero. The four register together at the top of the widget picker; RSS Subscribers itself is unchanged.

### Fixed
- The Internet Archive run's status said "one every five minutes"; the server's cron wakes every five, so the real pace is five to ten. It now says so, on the status line, the button's hint and the started notice.

