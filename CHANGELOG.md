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
- **Owner analytics name every row; public /stats keeps its floor (2026-10-23).** Privacy floors belong to the audience, not the data. On a small site the 3-visit floor hid most human views as `(withheld)`, including the pages the owner needed to see, while the live view already named single visits. `analytics-rows` (local and the remote twin), the sn-metrics sections (sources, geography, devices, journeys) and `analytics-query` now list every row with its real name, views and engagement. Contract kept: `withheld` stays in each payload, always `{rows:0, views:0, visits:0}`, and `floor` is `null` (the value the query already returned for a day series). `(unmatched)` and `(invalid)` are unchanged. The public /stats page is untouched (`SN_PUBLIC_STATS_MIN_GROUP` stays 3, pinned by a test), and the quiet-week movers keep their noise threshold under their own constant, `SNT_MOVERS_FLOOR` (3). Tool and ability descriptions say the new behavior. The remote worker's own description of `sn_remote_analytics_query` still says values under 3 visitor-days are withheld; it needs a separate update.

## [2026.10.60] - 2026-10-23 — a crashed job says so; the degree credential on the profile pages only

### Fixed
- **A scheduled job that dies says so.** A run that hit a fatal error or the time limit never reached the history callback, so it left no row at all: the weekly insights scan went seven weeks without a trace before showing up only as overdue. The start of every run now arms a shutdown check; a run that started and never finished is recorded as a failure, "Died mid-run", with PHP's last error when there is one.

### Changed
- **The degree credential sits on the profile pages only.** The Person node rides every page's structured data, so the Parchment-verified MBA (`hasCredential`) appeared on every note. It now appears on /about, /resume, /services and /uses (and their Spanish twins), the same pages typed ProfilePage; one predicate, `sn_schema_is_profile_post()`, decides both.

