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
- **/verify runs a compare on arrival from a link.** `?compare_a=N&compare_b=M`, the compare form's own field names, beside the page's `?note=`: the theme's "Updated" line sends a note's last two versions this way (`#compare` opens the tab). Each version must be a plain number (no sign, exponent, decimal or leading zero, at most six digits), the two must differ, and the page's note id must have the uid shape. Anything else is ignored without a word, so a malformed link leaves the page exactly as a bare `?note=` link does. A well-formed pair runs as the form would, so a version the note does not have gets the form's own "Could not fetch both versions" sentence. There is no range check against the latest credential: review found that URL edge-cached for up to two hours and never purged, so right after an edit it would have refused the newest version. Valid values fill the form, focus moves to the compare output (unless the reader already moved it) and the polite region reads the diff once; a link without `#compare` selects the Compare tab first. The arrival runs once every deferred script has (`DOMContentLoaded`, `load` as backup), so the panel it lands in is the one shown. URL values reach the page only as input values, after the pattern passed. The form's own compare is unchanged (one shared `runCompare()`). The output takes focus without a ring, like the panels (`prov-verify.css`). New behavior suite `tests/prov-verify-compare-arrival.cjs` (61 assertions, sweep wrapper `.php`) runs the real script and the real core in a VM, with hostile parameters.

### Fixed
- **Check Again lists the updates it just found.** On Dashboard > Updates, Check Again (`update-core.php?force-check=1`) ran the plugin's cache clear after WordPress's own theme and plugin checks on the same screen load, so it deleted the records those checks had just written, and the screen, which reads them right after, said there were no theme or plugin updates until the next page load. The clear now runs first (priority 9 on `load-update-core.php`, before core's checks at 10), and core's checks rebuild what it cleared from fresh GitHub tags. Confirmed through WordPress's own `WP_Hook` (records missing before, fresh after); `tests/force-check-no-nonce.php` dispatches the action the way core does and failed on the old order. One edge: if WordPress.org's update API fails during Check Again, core skips the theme's GitHub hook (the plugin still lists, its offer runs on core's earlier write), and a plain revisit of Updates or Themes re-checks.

## [2026.10.82] - 2026-10-31 — Indexing report marks a page requested and checks with Google live

### Added
- **Indexing: mark a page requested, and check with Google live (Integrity › Reports › Indexing, both twins).** Google's Indexing API covers job postings and livestreams only, so asking Google to index a note stays one click in Search Console; the row's link now says so ("Request in Search Console"). Everything else moved onto the site:
  - **Mark requested** on each row records the day of the mark (the site cannot see the day of the click in Search Console, so the status says "(marked)"). Only a path the inspection map holds, not indexed, can be marked.
  - **Check now with Google** inspects the marked rows, then the request list, live (`snt_gsc_inspect_one( $id, 0, true )`, forced past the weekly six-day freshness guard). A press starts no new page after 20 seconds and says what is left; the next press continues where it stopped, never re-inspecting a page it already answered. A forced pass that fails keeps the last good answer. A complete check that got an answer cannot be repeated for 10 minutes. It spends quota only on the owner's click: every screen, the brief and the watches still read the stored map. A forced check leaves the map's `synced_at` alone, so the headline's map date stays the weekly run's.
  - **Each row reads Google's answer against the mark:** not requested yet; requested (marked), waiting on Google; requested, no crawl since in 14 days, request again; requested, crawled since and still left out, request again. Every row shows Google's last crawl date, stored all along and never shown. Pages indexed after a request are listed under "Indexed after your request" for 30 days.
  - **The reminder stops repeating:** a marked row with no crawl since waits quietly for 14 days. The `index_gap` watch counts it ("N waiting on Google after your request") instead of listing it, and the morning brief leaves it out; a crawl after the mark that still did not index brings it back.
  - The marks and the status ride the health report only. `sn_indexing_report()`, which the `indexing-requests` ability and its remote twin return, is unchanged, so the remote contract does not move. A press refreshes the stored report directly, never through `sn_health_store_scan()`, so it adds no scan-history row. New: `inc/search-console-requested.php`, `inc/search-console-check-now.php`, `inc/health-render-indexing-status.php`, `inc/admin-post-actions/gsc-indexing.php`. Pinned in `tests/gsc-requested.php`, `tests/search-console-coverage.php`, `tests/search-console-index-watch.php`, `tests/os-leaf-tools-reports.php` and `tests/morning-brief.php`.

