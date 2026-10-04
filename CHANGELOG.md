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
- **Twelve desktop cards fold to six, as the owner approved.** Three cards now carry their neighbors and keep their ids, so each keeps its place on the desktop. **SN Traffic** (was SN Site Views) keeps the views headline, the trend and the sparkline, then shows today so far, top countries, devices, top sources, Hacker News, search, feed subscribers (24h, 7d, 30d) and top pages, with one Open Analytics link; the engaged readers, the other north star rows and the bot share left it, since SN Reading already carries them. **SN Systems** (was SN Health) says "All systems normal" in one line when uptime, the content-health checks and cron are all fine, and says what is wrong when they are not ("1 down", "1 to look at · 1 could not run"); it names a monitor only when one is down and an orphaned cron event only when there is one. **SN Provenance** (was SN Anchors) adds the machine readers under the anchors: reads in the last 30 days, who the edge verified, and declared AI-training reads, with links to Provenance and Machine Readers. SN Audience, SN RSS Subscribers, SN Uptime, SN Cron, SN Quick Actions and SN Machine Readers are retired. Quick Actions' buttons moved: "Clear DB overrides" to SN Systems, now behind a confirm, and "Check for updates" to SN Deploy Status. A saved layout keeps each card's old height, so resize SN Traffic, SN Systems and SN Provenance once.

## [21.8.2] - 2026-10-04 — SN Reading fits its card; SN Health without the reason line

### Fixed
- **SN Reading fits its card again.** Once session depth started arriving, "Two pages" and "Three or more" added two rows and pushed "Open Analytics" below the card's fold. They share one row now ("Two pages · three or more: 10% · 2%"). "Open Machine Readers" gets the same gap before its arrow as the other widget links. SN Health names a check that could not run without a reason line under it; the reason is on the Health tab, one link away.

