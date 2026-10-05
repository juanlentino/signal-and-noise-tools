# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.6.1] - 2026-10-05 — Action Scheduler runs from WP-Cron

### Changed
- **Action Scheduler no longer runs its queue from page loads.** A third-party plugin's Action Scheduler started a loopback queue run from ordinary requests: about 150 runs a day at about 5.8 seconds each on this 2 GB / 2 vCPU server, overlapping the desktop's own REST calls when the edge recorded 503s (8 at the origin in 24 hours, 5 of them OpenStation's session check). Its queue still runs from WP-Cron, which the system cron fires every 5 minutes; only when it runs moves. Owner-approved.

