# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.60] - 2026-10-23 — a crashed job says so; the degree credential on the profile pages only

### Fixed
- **A scheduled job that dies says so.** A run that hit a fatal error or the time limit never reached the history callback, so it left no row at all: the weekly insights scan went seven weeks without a trace before showing up only as overdue. The start of every run now arms a shutdown check; a run that started and never finished is recorded as a failure, "Died mid-run", with PHP's last error when there is one.

### Changed
- **The degree credential sits on the profile pages only.** The Person node rides every page's structured data, so the Parchment-verified MBA (`hasCredential`) appeared on every note. It now appears on /about, /resume, /services and /uses (and their Spanish twins), the same pages typed ProfilePage; one predicate, `sn_schema_is_profile_post()`, decides both.

