# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.80] - 2026-10-30 — served routes sit apart in unmatched paths; own probes are not feed readers

### Changed
- **Unmatched paths: what the site serves or already redirects sits apart.** The first live read (2026-10-30) put pages the site serves at the top of the people list: `/provhub` (26 views), `/notes/tags` (17), `/index`, the feeds. It also listed paths a redirect already handles, such as `/provenance/verify` to `/verify` and `/proof-of-origin`, and suggested redirects for them. The scan now checks the 50 most viewed people paths live, with the broken-links check's own probe (`sn_health_link_status()`: same host, HEAD, no redirect followed, cached a day, a Cloudflare challenge read as no answer). A 2xx or 3xx moves the path to a new "Served by the site" list on both twins, with its status ("served (200)", "redirects (301)"). A live 404 stays with the people rows, with its status. A path the check could not ask about (a placeholder in it), or that did not answer, keeps what the 404 log said. No list of theme routes is kept: the site's own answer decides, so a new route never needs registering. A row stored in another spelling (capitals, an encoded slash) is never checked: the site's answer for the cleaned form is not the answer that visitor got (`/Provhub` 404s where `/provhub` serves). Where a redirect lands is not checked. When nothing left is broken, the headline says so ("None of these paths answers with an error: the N paths the site serves or already redirects are listed below") instead of "No visitor reached a path". Suggestions now cover the rows shown after served ones leave. The breakdown on the local door makes no request and is unchanged. Pinned in `tests/unmatched-paths.php`, `tests/unmatched-paths-read.php` and `tests/os-leaf-tools-reports-unmatched.php`.

### Fixed
- **The site's own probes no longer count as feed readers.** The RSS tracker's bot test did not know the plugin's own user agent (`SignalNoiseTools/<version> <check>`). A same-host HEAD on a feed, from the broken-links check and now from the unmatched-paths live check, logged a feed request, added a "direct reader" to the Subscribers tile, and sent a human `RSS Feed Request` event to the collector. `sn_rss_tracker_is_bot()` now matches `signalnoisetools`; pinned in `tests/bot-detection.php`. Found by the independent review of this change before it shipped.
- **The "(unmatched)" breakdown's sums, stated as they are.** The catalog said the breakdown's rows sum to the default answer's `(unmatched)` row. Views do: 114 both ways, live. Visitor-days do not: 102 against 76. A stored row from before visitor-days were recorded (2026-07-11) reads its visits instead, and that fallback applies per row, so a path whose whole history predates it counts in the breakdown and not inside the merged bucket. The docs now say so, and `tests/analytics-rows-unmatched.php` pins the difference. The default answer is unchanged.

