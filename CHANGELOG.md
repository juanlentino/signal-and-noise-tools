# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.9.2] - 2026-10-07 — an event the analytics 2.0 check leaves sampled says why

### Added
- **An event the analytics 2.0 check leaves `sampled` says why** (`sampled_why` per day). After 22.9.1 was installed, Oct 7 still read `sampled` with nothing set aside, and the check could not say what blocked it. Each pageviews-side event that stays sampled now names the first reason the set-aside rule failed: no complete over-cap list, sampled on one side only, nothing to set aside, the counted visitor-days that differ (with both readings), or the totals left after setting aside (both sides). Read-only; it changes no state.

