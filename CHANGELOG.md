# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.0.2] - 2026-09-12 — worker follow-ups

### Fixed
- **Edge-workers health: a null denylist count reads as UNKNOWN, not EMPTY** (#1237). The login-guard worker answers `denylistCount: null` when its list meta is unreadable while a warm isolate may still be enforcing; the finding now says so and names `enforcedCount`. Only a measured 0 is EMPTY.
- **Bridge route: a rejected argument value answers 400** (#1238). Core's `ability_invalid_input` carries no status, so the REST layer defaulted to 500 and the remote MCP worker reported an origin outage for a bad `days`; the route stamps 400 on that code. The worker side (1.5.0) classifies both.
- **Analytics abilities refuse an unknown `range`** (#1239) — `range: 60` used to resolve silently to 7 days; `get-analytics-summary` and `get-analytics-events` now answer `ability_invalid_input` (400). The admin URL parameter keeps its 7-day fallback.

