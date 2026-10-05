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
- **A release says whether it changes the public site; an update that does not leaves the caches warm.** Owner, 2026-10-05: 50 edge purges in a week, 42 by plugin updates. `tools/cut-release.sh` takes `--front-end=yes|no` (default `yes`) and writes a `Front-End Change:` header into the plugin. The theme's update purge (theme PR) and this plugin's version-change rollover skip the purge on an explicit `no` for a plugin-only update; a theme change, any other package, and a missing header purge as before.
  The `no` holds only for a forward update from a version at or after the release's `Front-End Baseline:` (the last release that changed the public site, carried forward by every `no` cut and reset by every `yes`). An update that jumps past a public release, a rollback, or an unknown prior version purges (Codex P1: the updater installs the latest tag directly).

### Fixed
- **SN Systems: every amber line now links to where it gets fixed, and a line nothing here can fix is no longer amber.** The owner opened the card to three amber reasons and no way to act on any of them. A section that adds anything to the headline now ends with its fix link: Edge and Cache open Cloudflare (5xx by path, purge and probes), Cron opens Cron, Health opens Health, and Uptime opens Better Stack. A health check the AI provider refused for an empty credit balance reads "paused: AI credit out", counts as "1 paused", and links to Anthropic billing, not to "could not run". A purge verifying within 15 minutes of when it was sent reads "1 verifying". Neither turns the dot amber: the dot is gray, not green, when they are all that is left. A purge still "verifying" after 15 minutes is unmeasured and amber, as before. Edge 5xx stays amber, because those errors are real.

## [22.6.1] - 2026-10-05 — Action Scheduler runs from WP-Cron

### Changed
- **Action Scheduler no longer runs its queue from page loads.** A third-party plugin's Action Scheduler started a loopback queue run from ordinary requests: about 150 runs a day at about 5.8 seconds each on this 2 GB / 2 vCPU server, overlapping the desktop's own REST calls when the edge recorded 503s (8 at the origin in 24 hours, 5 of them OpenStation's session check). Its queue still runs from WP-Cron, which the system cron fires every 5 minutes; only when it runs moves. Owner-approved.

