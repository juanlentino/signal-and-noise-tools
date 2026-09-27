# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.3.0] - 2026-09-27 — wave 4 retires four read tools

### Changed
- **Wave 4, first four: duplicate MCP read tools retired.** The 2026-09-27 telemetry read (30 days) found agents still calling most absorbed single-purpose reads, so only the four with 0 to 2 read-door calls leave the door: `ai-cache-probe-status` (use `sn-status{ai_cache_probe}`), `cadence-flags` (`sn-status{cadence}`), `get-rss-stats` (`sn-metrics{rss_stats}`), `get-analytics-events` (`sn-metrics{analytics_events}`). Door-only, as in waves 1 and 2: every ability stays registered and keeps serving the dashboard widgets. Read door 51 to 47.
- **Scheduled reads use the consolidated tools.** The nightly run called five absorbed singles by name, which kept their telemetry up with calls that were nobody's usage. It now makes two calls, `sn-status{health_scan, uptime, deploy, anchor}` and `sn-metrics{analytics_summary}`. The `wave4_telemetry` watch moves to 2026-10-25, so the remaining eight are judged on a month without it.

