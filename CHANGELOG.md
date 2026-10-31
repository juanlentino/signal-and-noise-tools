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
- **Unmatched paths: a feed is no longer read as a scanner.** The 2026.10.80 report (scan of 2026-10-31) listed seven paths under "Scanners and bots" that people reach: `/notes/feed`, `/feed/atom`, `/feed`, `/comments/feed`, `/tag/metadata`, `/notes/tag/ai-music` and `/compound`. Every analytics event counts a visit, but only a page view counts a view, so an event-only row (a feed fetch, among others) carried visits (17 human for `/notes/feed`) and no views, and the class fell through to "bot". They never got the live check, the list's own sentence ("paths only scanners or bots reached") was false for them, and `/notes/tag/ai-music` hid a 404 with a suggested fix (`/music`). A path with no page views at all is now classed by its visits; page views still decide wherever there are any, and a path with neither stays with the scanners. Among people rows with no human view, visits now rank before recency, so a feed is not ranked behind a suspect-only row (found by the independent review); every other row keeps its order. Its Views cell reads "17 visits, no page views" on both twins instead of blank, and the coverage line says how such a path is classed. Pinned in `tests/unmatched-paths.php` and `tests/os-leaf-tools-reports-unmatched.php`.

## [2026.10.80] - 2026-10-30 — served routes sit apart in unmatched paths; own probes are not feed readers

### Changed
- **Unmatched paths: what the site serves or already redirects sits apart.** The first live read (2026-10-30) put pages the site serves at the top of the people list: `/provhub` (26 views), `/notes/tags` (17), `/index`, the feeds. It also listed paths a redirect already handles, such as `/provenance/verify` to `/verify` and `/proof-of-origin`, and suggested redirects for them. The scan now checks the 50 most viewed people paths live, with the broken-links check's own probe (`sn_health_link_status()`: same host, HEAD, no redirect followed, cached a day, a Cloudflare challenge read as no answer). A 2xx or 3xx moves the path to a new "Served by the site" list on both twins, with its status ("served (200)", "redirects (301)"). A live 404 stays with the people rows, with its status. A path the check could not ask about (a placeholder in it), or that did not answer, keeps what the 404 log said. No list of theme routes is kept: the site's own answer decides, so a new route never needs registering. A row stored in another spelling (capitals, an encoded slash) is never checked: the site's answer for the cleaned form is not the answer that visitor got (`/Provhub` 404s where `/provhub` serves). Where a redirect lands is not checked. When nothing left is broken, the headline says so ("None of these paths answers with an error: the N paths the site serves or already redirects are listed below") instead of "No visitor reached a path". Suggestions now cover the rows shown after served ones leave. The breakdown on the local door makes no request and is unchanged. Pinned in `tests/unmatched-paths.php`, `tests/unmatched-paths-read.php` and `tests/os-leaf-tools-reports-unmatched.php`.

### Fixed
- **The site's own probes no longer count as feed readers.** The RSS tracker's bot test did not know the plugin's own user agent (`SignalNoiseTools/<version> <check>`). A same-host HEAD on a feed, from the broken-links check and now from the unmatched-paths live check, logged a feed request, added a "direct reader" to the Subscribers tile, and sent a human `RSS Feed Request` event to the collector. `sn_rss_tracker_is_bot()` now matches `signalnoisetools`; pinned in `tests/bot-detection.php`. Found by the independent review of this change before it shipped.
- **The "(unmatched)" breakdown's sums, stated as they are.** The catalog said the breakdown's rows sum to the default answer's `(unmatched)` row. Views do: 114 both ways, live. Visitor-days do not: 102 against 76. A stored row from before visitor-days were recorded (2026-07-11) reads its visits instead, and that fallback applies per row, so a path whose whole history predates it counts in the breakdown and not inside the merged bucket. The docs now say so, and `tests/analytics-rows-unmatched.php` pins the difference. The default answer is unchanged.

