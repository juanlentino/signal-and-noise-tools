# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **The sessions read takes whole New York visitor-days.** The visitor-day rotates at midnight in `SN_ANALYTICS_SPLIT_TZ`, not UTC, so a read bounded at UTC midnight cut the first hours of a visit in two. `sn_analytics_visitor_day_bounds()` widens the read to the visitor-days the window touches; sessions are still kept by when they began. The sessions ability and the Sessions tab read the pinnable clock (`sn_analytics_clock()`), and `tests/analytics-sessions-read.php` is pinned to it, which also ends the time-of-day flake in its first group (about 83 minutes after each New York midnight).
- **A session's source is a clean label, and a goal is an identifier.** The referrer folds to `(direct)`, `(internal)`, a brand label or a bare hostname, and anything else reads `(invalid)`; goal names must match `^[A-Za-z0-9_.:-]{1,64}$`. A visitor-written string can no longer reach an agent through the sessions read.
- **A window that starts after today is refused with a 400**, on the rows and sessions reads, instead of an empty answer that looked like no traffic.
- **sn-metrics refuses a sessions or rows window its source would refuse**, before dispatch, as it already did for a bad query. The dispatch folds every error into `{error:"unavailable"}`, so a typo read as an outage. `snt_arows_resolve()` and `snt_analytics_sessions_check()` hold the checks once for both doors.
- **The live hour, scroll and per-slot pages and sources queries name the over-cap list once.** The human class already carries it; the second copy could push a full list past the statement budget, as it did for the live sources in #2336. Pinned for every live query by counting a known hash in the SQL.
- **The Links view says when its read reached a row cap**: the newest 500 opens only, and a visitor-day past the 5,000-row pages cap may miss the signals that would mark it likely automated.
- **The rows description no longer says every view is sample-corrected** (source sessions counts them as read, as the Sessions tab does), and scroll is "0-100 on most rows": a thin row can read above 100 when beacons land in it without their page view.
- **Test gaps from the coverage review, each checked against a mutation:** the Links rows and their cache carry no visitor code; the realtime refresh stores the coded links it read; time and scroll are null in a by-hour answer, not 0; the SQL dialect guard covers the nine new Analytics Engine builders; the public-stats window check no longer fails for a second at UTC midnight.
- **A CI flake in `tests/mcp-telemetry-read.php`** (62/63 on the 2026.11.8 cut, green on rerun). Four checks rebuilt a timestamp, to the second, with `$ago()` after the fixture had built it, so a second ticking between the two failed them. They now compare the fixture's own value. A forced 1-second pause reproduces CI's 62/63 on the old test and passes the new one.

## [2026.11.8] - 2026-11-02 — The résumé's Markdown follows the lane: /resume.md?lane=operations

### Changed
- **The résumé's Markdown follows the lane.** `/resume.md?lane=operations` (and `?output_format=markdown` on the operations lane) is the operations résumé: its headline and summary, What I've Built, its experience and sections, and no phone. On `/resume/?lane=operations` the page's `Link` header and head alternate name it, so an agent asking that address for Markdown gets that lane. The rights-signals Worker 1.37.2 reads the lane query; until then it converts the visible lane, as of 1.37.1. A section with nothing in it is left out of the Markdown. The music Markdown is unchanged byte for byte, pinned to main's output. `tests/resume-page-lanes.php` (+4).

