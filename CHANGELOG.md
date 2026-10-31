# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **Indexing: mark a page requested, and check with Google live (Integrity › Reports › Indexing, both twins).** Google's Indexing API covers job postings and livestreams only, so asking Google to index a note stays one click in Search Console; the row's link now says so ("Request in Search Console"). Everything else moved onto the site:
  - **Mark requested** on each row records the day of the mark (the site cannot see the day of the click in Search Console, so the status says "(marked)"). Only a path the inspection map holds, not indexed, can be marked.
  - **Check now with Google** inspects the marked rows, then the request list, live (`snt_gsc_inspect_one( $id, 0, true )`, forced past the weekly six-day freshness guard). A press starts no new page after 20 seconds and says what is left; the next press continues where it stopped, never re-inspecting a page it already answered. A forced pass that fails keeps the last good answer. A complete check that got an answer cannot be repeated for 10 minutes. It spends quota only on the owner's click: every screen, the brief and the watches still read the stored map. A forced check leaves the map's `synced_at` alone, so the headline's map date stays the weekly run's.
  - **Each row reads Google's answer against the mark:** not requested yet; requested (marked), waiting on Google; requested, no crawl since in 14 days, request again; requested, crawled since and still left out, request again. Every row shows Google's last crawl date, stored all along and never shown. Pages indexed after a request are listed under "Indexed after your request" for 30 days.
  - **The reminder stops repeating:** a marked row with no crawl since waits quietly for 14 days. The `index_gap` watch counts it ("N waiting on Google after your request") instead of listing it, and the morning brief leaves it out; a crawl after the mark that still did not index brings it back.
  - The marks and the status ride the health report only. `sn_indexing_report()`, which the `indexing-requests` ability and its remote twin return, is unchanged, so the remote contract does not move. A press refreshes the stored report directly, never through `sn_health_store_scan()`, so it adds no scan-history row. New: `inc/search-console-requested.php`, `inc/search-console-check-now.php`, `inc/health-render-indexing-status.php`, `inc/admin-post-actions/gsc-indexing.php`. Pinned in `tests/gsc-requested.php`, `tests/search-console-coverage.php`, `tests/search-console-index-watch.php`, `tests/os-leaf-tools-reports.php` and `tests/morning-brief.php`.

## [2026.10.81] - 2026-10-31 — unmatched paths class feeds by visits; Secrets API watch waits for a 7.2 RC

### Fixed
- **Unmatched paths: a feed is no longer read as a scanner.** The 2026.10.80 report (scan of 2026-10-31) listed seven paths under "Scanners and bots" that people reach: `/notes/feed`, `/feed/atom`, `/feed`, `/comments/feed`, `/tag/metadata`, `/notes/tag/ai-music` and `/compound`. Every analytics event counts a visit, but only a page view counts a view, so an event-only row (a feed fetch, among others) carried visits (17 human for `/notes/feed`) and no views, and the class fell through to "bot". They never got the live check, the list's own sentence ("paths only scanners or bots reached") was false for them, and `/notes/tag/ai-music` hid a 404 with a suggested fix (`/music`). A path with no page views at all is now classed by its visits; page views still decide wherever there are any, and a path with neither stays with the scanners. Among people rows with no human view, visits now rank before recency, so a feed is not ranked behind a suspect-only row (found by the independent review); every other row keeps its order. Its Views cell reads "17 visits, no page views" on both twins instead of blank, and the coverage line says how such a path is classed. Pinned in `tests/unmatched-paths.php` and `tests/os-leaf-tools-reports-unmatched.php`.
- **Unmatched paths: no "0 human views" headline.** When every visitor path left has no human page view (only feeds or suspect-only paths), the headline read "N paths visitors reached are not a note or page (0 human views in all)". It now counts visits instead ("no human page views, 17 visits in all"); the report payload carries `people_visits`. A scan stored before this keeps the old words until the next scan. Pinned in `tests/os-leaf-tools-reports-unmatched.php`.
- **The Secrets API watch no longer ripens before core ships it (#1625).** It ripened on `function_exists( 'wp_set_secret' )` alone, and on 2026-10-31 that function existed on a site running WordPress 7.1.4, from something other than core: the morning brief called the keyring fold due against a proposal, not core's API. The recorded call (2026-10-25) is to build against the 7.2 release candidate, so the watch now also needs WordPress 7.2-RC1 or later; until then its note says the function exists but waits for the RC. A 7.2 beta or a 7.2 trunk build does not count; anything from 7.2-RC1 on does. The misplaced docblock above it (it described the 7.2 watch) now sits on its own function. Pinned in `tests/watches.php`.

