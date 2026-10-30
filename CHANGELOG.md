# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.79] - 2026-10-30 — unmatched paths named for the owner, and DST-proof queue tests

### Added
- **Unmatched paths, named for the owner.** Every reader has always counted the paths visitors reach that are not a note or a page as one "(unmatched)" row, and `/stats` still does. A new report-only health check, "Unmatched paths" in S&N Home › Integrity › Reports (classic and native twins), names them: path, views by class, first and last day seen, what the 404 log saw (status 404 and the referring host, or "not in the 404 log"), and a suggested fix ("Same page, another spelling", "Redirect to /notes/…?" or "No close match"). People come first, sorted by human views and then the most recent; paths that only scanners or bots reached sit apart under "Scanners and bots". The probe test and the redirect suggester are the 404 log's own (`sn_404_should_capture()`, `sn_404_suggest_target()`), so the site keeps one vocabulary for both. Nothing creates a redirect. It reads the whole stored history of the daily analytics table (no new storage, the same retention, no IP or user agent), and every path is cleaned before it is shown: query and fragment dropped, lowercase, slashes collapsed, any segment that looks like an email, id, hash, number or token a placeholder (judged by its parts too, so `sk_live_…` or `555-123-4567` cannot hide behind a separator), anything outside `[a-z0-9._~-]`, a line break included, a placeholder, at most 200 characters. Words survive, so a hyphenated sentence can; an address without "@" is not recognized. The scan runs it on its usual schedule, the suggester runs only for the people rows shown, and a read that fails marks the check skipped rather than reporting no paths. The health-scan tools on both MCP doors name the check and never carry its rows. Code: `inc/unmatched-paths*.php`, `inc/health-unmatched-paths.php`, `inc/health-render-unmatched.php`, `apps/sn-dashboard/parts/leaves/tools-reports-unmatched.php`. Pinned in `tests/unmatched-paths.php`, `tests/unmatched-paths-read.php`, `tests/os-leaf-tools-reports-unmatched.php` (both twins say the same words, escape a path on their own, and never fold) and `tests/abilities-health.php` (no rows in the health scan).
- **`analytics_rows` breaks "(unmatched)" down, on the local door only.** `sn-metrics{analytics_rows}` with `path: "(unmatched)"` and the `path` dimension returns one row per cleaned path, named in `visitor_path`, with `probe`, `suggested_target`, `status` and `referrer_host`; the rows sum to the default answer's `(unmatched)` row, and the default answer does not change. This loosens, by owner decision, the rule that a stored path never reaches a model: only the cleaned form does, and only here. The remote twin (`signal-noise/remote-analytics-rows`, the Worker's `sn_remote_analytics_query`) refuses the breakdown with a 400 and keeps its output schema, so the remote contract does not move. It fails closed: the shared callback refuses the breakdown, and only the local registration opts in (`snt_ability_analytics_rows_local()`), so a door wired to the shared callback later cannot serve it. Pinned in `tests/analytics-rows-unmatched.php` and `tests/abilities-remote-set.php`.

### Fixed
- **The queue twin suites no longer fail near a DST change.** `queue-cadence-twins`, `queue-runway-twins` and `queue-slot-twins` placed fixture notes at `time() + days × 86400`. Across a DST change a day is 23 or 25 hours, so two notes "one day apart" could land on one calendar day (Nov 1 2026, New York) or two, and `queue-cadence-twins` failed at some hours of the week before each change: 24 of 3,456 ten-minute instants across the 2026 and 2027 change weeks. The three suites now share one fixture, `tests/lib/queue-post.php`, which places each note at local noon a whole number of calendar days out; across the same instants it fails at none. The cluster sentence's assertion also takes the year a label gains across New Year ("Jan 1, 2027 and Jan 2, 2027"), so the suite no longer fails every Dec 30 and 31. Test-only: no plugin code changes.

