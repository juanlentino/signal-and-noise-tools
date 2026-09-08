# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.107.4] - 2026-09-08 — Resilient status widgets and clearer Analytics composition

### Fixed
- Bound the MCP read budget to fixed minute windows instead of extending the same counter on every accepted request. Keep the 120-request cap and existing permission/kill-switch behavior; use atomic persistent-cache increments and report the remaining-window retry delay.
- Keep timestamped last-known Deploy Status and Uptime data during temporary fetch failures, with explicit stale notices rather than current-green status. Back off after failures, respect retry delays, avoid overlapping polls, and cancel pending requests on widget teardown.
- Recompose native Analytics so headline metrics and charts precede routine forecast and release commentary while anomalies remain prominent. Retain controls, counts, explanations and exports; tighten phone spacing, align desktop period controls, and keep uptime table columns reachable. Classic header output remains unchanged.

### Added
- Deterministic rate-window and widget recovery/cancellation tests, plus populated Overview composition checks covering Uptime, Movers, annotations, sessions and scroll reachability.

